<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The tag manager script a website loads (/lib.js or
 * /tms-lite/sites/{siteId}/lib.js): public/tag-manager.js with the website's
 * validated public settings and its custom JavaScript tags compiled in. With
 * `min`, the Terser build when it matches the source, otherwise the source
 * compacted here.
 */
final class TagManagerScript
{
    public const PLACEHOLDER = '__AGGREGATE_TAG_MANAGER__';
    public const SOURCE_DEFAULTS = 'var tagManagerConfig = {enabled: false, tags: []};';
    /** Custom JavaScript tags are compiled into the script as functions here. */
    public const CUSTOM_PLACEHOLDER = '__AGGREGATE_CUSTOM_SCRIPTS__';
    public const CUSTOM_DEFAULTS = 'var customScripts = {};';

    public function __construct(
        private readonly TagManagerSettings $settings,
        private readonly string $projectDir = __DIR__.'/../..',
        private readonly ?BrowserScriptCompactor $compactor = null,
    ) {
    }

    /**
     * @return array{content: string, variant: string} the script, and whether it is the minified build, compacted here or the source
     *
     * @throws TagManagerScriptUnavailable when the settings or the source cannot be used; its message is safe to send
     */
    public function render(?string $siteId, bool $minified): array
    {
        try {
            $configuration = $this->settings->toBrowserConfig($siteId);
            $json = json_encode($configuration, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
            $custom = $this->customScripts($siteId);
        } catch (\Throwable) {
            // No settings, parser messages, or executable tags escape a broken policy.
            throw new TagManagerScriptUnavailable('Tag manager configuration is unavailable. No tags loaded.');
        }
        $path = $this->projectDir.'/public/tag-manager.js';
        $source = is_readable($path) ? file_get_contents($path) : false;
        if (!is_string($source) || substr_count($source, self::SOURCE_DEFAULTS) !== 1 || substr_count($source, self::CUSTOM_DEFAULTS) !== 1) {
            throw new TagManagerScriptUnavailable('Tag manager source is unavailable. No tags loaded.');
        }

        // One pass, so text inside custom code is never treated as a placeholder.
        $template = null;
        $variant = 'source';
        if ($minified) {
            $template = BrowserBuild::template($this->projectDir, 'tag-manager-manifest.json', 'tag-manager.template.min.js',
                ['sourceSha256' => $source], [self::PLACEHOLDER, self::CUSTOM_PLACEHOLDER]);
            $variant = 'minified';
            if ($template === null) {
                $template = ($this->compactor ?? new BrowserScriptCompactor())->template($source, [
                    self::SOURCE_DEFAULTS => 'var tagManagerConfig = '.self::PLACEHOLDER.';',
                    self::CUSTOM_DEFAULTS => 'var customScripts = '.self::CUSTOM_PLACEHOLDER.';',
                ], [self::PLACEHOLDER, self::CUSTOM_PLACEHOLDER]);
                $variant = $template !== null ? 'compact' : 'source';
            }
        }

        return [
            'content' => $template !== null
                ? strtr($template, [self::PLACEHOLDER => $json, self::CUSTOM_PLACEHOLDER => $custom])
                : strtr($source, [self::SOURCE_DEFAULTS => 'var tagManagerConfig = '.$json.';', self::CUSTOM_DEFAULTS => 'var customScripts = '.$custom.';']),
            'variant' => $variant,
        ];
    }

    /**
     * A JavaScript object of the served custom tags' functions. Their code was
     * parsed when the settings were validated: each is one strict-mode
     * function body that cannot close its function early.
     */
    private function customScripts(?string $siteId): string
    {
        $functions = [];
        foreach ($this->settings->customScripts($siteId) as $id => $code) {
            $functions[] = json_encode((string) $id, JSON_THROW_ON_ERROR).': '.TagManagerCustomCode::wrap($code);
        }

        return $functions === [] ? '{}' : "{\n".implode(",\n", $functions)."\n}";
    }
}
