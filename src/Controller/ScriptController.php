<?php

namespace App\Controller;

use App\Service\AggregateConfigLoader;
use App\Service\BrowserScriptCache;
use App\Service\BrowserScriptCompactor;
use App\Service\CollectionProfile;
use App\Service\CustomDataSettings;
use App\Service\InternalTrafficSettings;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ScriptController
{
    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly InternalTrafficSettings $internalTraffic,
        private readonly CustomDataSettings $customData,
        private readonly string $projectDir = __DIR__.'/../..',
        private readonly ?BrowserScriptCompactor $compactor = null,
    ) {}

    #[Route('/aggregate.js', name: 'aggregate_script', methods: ['GET'])]
    public function __invoke(?Request $request = null): Response
    {
        $namespace = $this->config->getWithEnvFallback('js_namespace', 'Aggregate');

        $scriptPath = $this->projectDir . '/public/aggregate.js';
        if (!file_exists($scriptPath)) {
            return new Response('Script not found', Response::HTTP_NOT_FOUND);
        }

        $content = file_get_contents($scriptPath);
        if ($content === false) {
            return new Response('Script not found', Response::HTTP_NOT_FOUND);
        }

        // The minified template never contains deployment settings. Inject the
        // same explicitly public configuration into either script variant.
        $jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR;
        $values = [
            '__AGGREGATE_NAMESPACE__' => json_encode($namespace, $jsonFlags),
            '__AGGREGATE_INTERNAL_TRAFFIC__' => json_encode($this->internalTraffic->toBrowserConfig(), $jsonFlags),
            '__AGGREGATE_CUSTOM_DATA__' => json_encode($this->customData->toBrowserConfig(), $jsonFlags),
            '__AGGREGATE_COLLECTION__' => json_encode((new CollectionProfile($this->config))->toBrowserConfig(), $jsonFlags),
        ];
        $variant = 'source';
        $minified = null;
        if ($request?->query->get('min') === '1') {
            // The Terser build when it matches the source; otherwise, as on a
            // server without Node, the source compacted here.
            $minified = $this->minifiedTemplate($content);
            $variant = 'minified';
            if ($minified === null) {
                $minified = ($this->compactor ?? new BrowserScriptCompactor())->template($content, [
                    "var namespace = 'Aggregate';" => 'var namespace = __AGGREGATE_NAMESPACE__;',
                    "var internalTrafficDefaults = {storage: 'cookie', name: 'orgInternalTraffic', value: 'true', cookieDomain: ''};" => 'var internalTrafficDefaults = __AGGREGATE_INTERNAL_TRAFFIC__;',
                    "var customDataDefaults = {queryParameters: {utm_source: 'utm_source', utm_medium: 'utm_medium', utm_campaign: 'utm_campaign', utm_term: 'utm_term', utm_content: 'utm_content', utm_id: 'utm_id'}, consentFreeProperties: []};" => 'var customDataDefaults = __AGGREGATE_CUSTOM_DATA__;',
                    "var collectionDefaults = {profile: 'standard'};" => 'var collectionDefaults = __AGGREGATE_COLLECTION__;',
                ], array_keys($values));
                $variant = $minified !== null ? 'compact' : 'source';
            }
        }
        if ($minified !== null) {
            $content = strtr($minified, $values);
        } else {
            $content = strtr($content, [
                "var namespace = 'Aggregate';" => 'var namespace = '.$values['__AGGREGATE_NAMESPACE__'].';',
                "var internalTrafficDefaults = {storage: 'cookie', name: 'orgInternalTraffic', value: 'true', cookieDomain: ''};" => 'var internalTrafficDefaults = '.$values['__AGGREGATE_INTERNAL_TRAFFIC__'].';',
                "var customDataDefaults = {queryParameters: {utm_source: 'utm_source', utm_medium: 'utm_medium', utm_campaign: 'utm_campaign', utm_term: 'utm_term', utm_content: 'utm_content', utm_id: 'utm_id'}, consentFreeProperties: []};" => 'var customDataDefaults = '.$values['__AGGREGATE_CUSTOM_DATA__'].';',
                "var collectionDefaults = {profile: 'standard'};" => 'var collectionDefaults = '.$values['__AGGREGATE_COLLECTION__'].';',
            ]);
        }

        $response = new Response($content);
        $response->headers->set('Content-Type', 'application/javascript');
        $response->headers->set('X-Aggregate-Script', $variant);

        // Reused for five minutes, then confirmed unchanged with a 304.
        return BrowserScriptCache::apply($response, $request);
    }

    private function minifiedTemplate(string $source): ?string
    {
        $directory = $this->projectDir.'/var/browser';
        if (!is_readable($directory.'/manifest.json') || !is_readable($directory.'/aggregate.template.min.js')) {
            return null;
        }

        try {
            $manifest = json_decode(file_get_contents($directory.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
            $template = file_get_contents($directory.'/aggregate.template.min.js');
            if (!is_array($manifest) || ($manifest['format'] ?? null) !== 1 || !is_string($template)
                || ($manifest['sourceSha256'] ?? null) !== hash('sha256', $source)
                || ($manifest['templateSha256'] ?? null) !== hash('sha256', $template)) {
                return null;
            }
            foreach (['__AGGREGATE_NAMESPACE__', '__AGGREGATE_INTERNAL_TRAFFIC__', '__AGGREGATE_CUSTOM_DATA__', '__AGGREGATE_COLLECTION__'] as $placeholder) {
                if (!str_contains($template, $placeholder)) {
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
