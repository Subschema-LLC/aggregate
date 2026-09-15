<?php

namespace App\Controller;

use App\Service\AggregateConfigLoader;
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
        ];
        $minified = $request?->query->get('min') === '1' ? $this->minifiedTemplate($content) : null;
        if ($minified !== null) {
            $content = strtr($minified, $values);
        } else {
            $content = strtr($content, [
                "var namespace = 'Aggregate';" => 'var namespace = '.$values['__AGGREGATE_NAMESPACE__'].';',
                "var internalTrafficDefaults = {storage: 'cookie', name: 'orgInternalTraffic', value: 'true', cookieDomain: ''};" => 'var internalTrafficDefaults = '.$values['__AGGREGATE_INTERNAL_TRAFFIC__'].';',
                "var customDataDefaults = {queryParameters: {utm_source: 'utm_source', utm_medium: 'utm_medium', utm_campaign: 'utm_campaign', utm_term: 'utm_term', utm_content: 'utm_content', utm_id: 'utm_id'}, consentFreeProperties: []};" => 'var customDataDefaults = '.$values['__AGGREGATE_CUSTOM_DATA__'].';',
            ]);
        }

        $response = new Response($content);
        $response->headers->set('Content-Type', 'application/javascript');
        $response->headers->set('X-Aggregate-Script', $minified !== null ? 'minified' : 'source');
        // Revalidate on each page load so edited collection settings take effect.
        $response->headers->set('Cache-Control', 'public, max-age=0, must-revalidate');

        return $response;
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
            foreach (['__AGGREGATE_NAMESPACE__', '__AGGREGATE_INTERNAL_TRAFFIC__', '__AGGREGATE_CUSTOM_DATA__'] as $placeholder) {
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
