<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The configured tracker served at /aggregate.js, with this deployment's
 * public settings filled in. Shared by ScriptController and the page speed
 * panel on General settings, so both report the same script.
 *
 * `?min=1` selects the Terser build (app:assets:build-js) when it matches the
 * current source and otherwise the source compacted on this server; with
 * "Leave out unused tracker features" on, that is the smallest build that
 * behaves like the full one for these settings (see TrackerBuilds). Without
 * `min=1`, the readable source with every feature is served.
 */
final class TrackerScript
{
    /** Source declaration => template declaration; the build script makes the same replacements. */
    public const DECLARATIONS = [
        "var namespace = 'Aggregate';" => 'var namespace = __AGGREGATE_NAMESPACE__;',
        "var internalTrafficDefaults = {storage: 'cookie', name: 'orgInternalTraffic', value: 'true', cookieDomain: ''};" => 'var internalTrafficDefaults = __AGGREGATE_INTERNAL_TRAFFIC__;',
        "var customDataDefaults = {queryParameters: {utm_source: 'utm_source', utm_medium: 'utm_medium', utm_campaign: 'utm_campaign', utm_term: 'utm_term', utm_content: 'utm_content', utm_id: 'utm_id'}, consentFreeProperties: []};" => 'var customDataDefaults = __AGGREGATE_CUSTOM_DATA__;',
        "var collectionDefaults = {profile: 'standard'};" => 'var collectionDefaults = __AGGREGATE_COLLECTION__;',
    ];
    public const PLACEHOLDERS = ['__AGGREGATE_NAMESPACE__', '__AGGREGATE_INTERNAL_TRAFFIC__', '__AGGREGATE_CUSTOM_DATA__', '__AGGREGATE_COLLECTION__'];

    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly InternalTrafficSettings $internalTraffic,
        private readonly CustomDataSettings $customData,
        private readonly string $projectDir = __DIR__.'/../..',
        private readonly ?BrowserScriptCompactor $compactor = null,
    ) {
    }

    /**
     * The configured script, or null when public/aggregate.js is missing.
     * variant: minified (Terser build), compact (compacted here) or source.
     * build: a TrackerBuilds build; pass one to render it instead of the one
     * the current settings select.
     *
     * @return array{content: string, variant: string, build: string}|null
     */
    public function render(bool $minified, ?string $build = null): ?array
    {
        $source = is_file($this->projectDir.'/public/aggregate.js') ? @file_get_contents($this->projectDir.'/public/aggregate.js') : false;
        if (!is_string($source)) {
            return null;
        }

        // The templates never contain deployment settings. Insert the same
        // explicitly public configuration into every variant.
        $collection = (new CollectionProfile($this->config))->toBrowserConfig();
        $customData = $this->customData->toBrowserConfig();
        $jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR;
        $values = array_combine(self::PLACEHOLDERS, [
            json_encode($this->config->getWithEnvFallback('js_namespace', 'Aggregate'), $jsonFlags),
            json_encode($this->internalTraffic->toBrowserConfig(), $jsonFlags),
            json_encode($customData, $jsonFlags),
            json_encode($collection, $jsonFlags),
        ]);

        if ($minified) {
            $build ??= TrackerBuilds::select((new TrackerBuilds($this->config))->enabled(), $collection, $customData);
            // A smaller build that cannot be produced falls back to the full one.
            foreach (array_unique([$build, TrackerBuilds::FULL]) as $candidate) {
                $template = $this->minifiedTemplate($source, $candidate);
                $variant = 'minified';
                if ($template === null) {
                    $template = ($this->compactor ?? new BrowserScriptCompactor())->template($source, self::DECLARATIONS, self::PLACEHOLDERS, TrackerBuilds::SWITCHES[$candidate]);
                    $variant = 'compact';
                }
                if ($template !== null) {
                    return ['content' => strtr($template, $values), 'variant' => $variant, 'build' => $candidate];
                }
            }
        }

        $declarations = array_map(static fn (string $declaration): string => strtr($declaration, $values), self::DECLARATIONS);

        return ['content' => strtr($source, $declarations), 'variant' => 'source', 'build' => TrackerBuilds::FULL];
    }

    /**
     * Sizes for the page speed panel: the script visitors receive with
     * `?min=1` and the full build, in bytes as sent and gzip-compressed.
     *
     * @return array{variant: string, build: string, bytes: int, gzip: int, full_gzip: int, source_gzip: int}|null
     */
    public function sizes(): ?array
    {
        $served = $this->render(true);
        $full = $this->render(true, TrackerBuilds::FULL);
        $source = $this->render(false);
        if ($served === null || $full === null || $source === null) {
            return null;
        }
        $gzip = static fn (string $content): int => strlen((string) gzencode($content, 6));

        return [
            'variant' => $served['variant'],
            'build' => $served['build'],
            'bytes' => strlen($served['content']),
            'gzip' => $gzip($served['content']),
            'full_gzip' => $gzip($full['content']),
            'source_gzip' => $gzip($source['content']),
        ];
    }

    /** The verified Terser template for a build, or null when it is missing or stale. */
    private function minifiedTemplate(string $source, string $build): ?string
    {
        $directory = $this->projectDir.'/var/browser';
        $file = $build === TrackerBuilds::FULL ? 'aggregate.template.min.js' : 'aggregate-'.$build.'.template.min.js';
        if (!is_readable($directory.'/manifest.json') || !is_readable($directory.'/'.$file)) {
            return null;
        }

        try {
            $manifest = json_decode((string) file_get_contents($directory.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
            $template = file_get_contents($directory.'/'.$file);
            $expected = $build === TrackerBuilds::FULL ? ($manifest['templateSha256'] ?? null) : ($manifest['builds'][$build]['templateSha256'] ?? null);
            if (!is_array($manifest) || ($manifest['format'] ?? null) !== 1 || !is_string($template)
                || ($manifest['sourceSha256'] ?? null) !== hash('sha256', $source)
                || !is_string($expected) || $expected !== hash('sha256', $template)) {
                return null;
            }
            foreach (self::PLACEHOLDERS as $placeholder) {
                if (substr_count($template, $placeholder) !== 1) {
                    return null;
                }
            }

            return $template;
        } catch (\Throwable) {
            // An optional build must never cause tracking to use stale code or
            // stop working when generated assets are absent or incomplete.
            return null;
        }
    }
}
