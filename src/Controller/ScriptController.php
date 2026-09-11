<?php

namespace App\Controller;

use App\Service\AggregateConfigLoader;
use App\Service\InternalTrafficSettings;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ScriptController
{
    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly InternalTrafficSettings $internalTraffic,
    ) {}

    #[Route('/aggregate.js', name: 'aggregate_script', methods: ['GET'])]
    public function __invoke(): Response
    {
        $namespace = $this->config->getWithEnvFallback('js_namespace', 'Aggregate');

        $scriptPath = __DIR__ . '/../../public/aggregate.js';
        if (!file_exists($scriptPath)) {
            return new Response('Script not found', Response::HTTP_NOT_FOUND);
        }

        $content = file_get_contents($scriptPath);

        // Replace the default namespace with configured one
        // This allows users to set it once in config instead of via data-attribute
        $jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR;
        $content = str_replace(
            "var namespace = 'Aggregate';",
            'var namespace = ' . json_encode($namespace, $jsonFlags) . ';',
            $content
        );
        // Only explicitly public marker settings belong in this script. The
        // token protecting the enrollment page must never be distributed here.
        $content = str_replace(
            "var internalTrafficDefaults = {storage: 'cookie', name: 'orgInternalTraffic', value: 'true', cookieDomain: ''};",
            'var internalTrafficDefaults = ' . json_encode($this->internalTraffic->toBrowserConfig(), $jsonFlags) . ';',
            $content
        );

        $response = new Response($content);
        $response->headers->set('Content-Type', 'application/javascript');
        // Revalidate on each page load so edited marker settings take effect.
        $response->headers->set('Cache-Control', 'public, max-age=0, must-revalidate');

        return $response;
    }
}
