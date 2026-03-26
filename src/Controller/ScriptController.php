<?php

namespace App\Controller;

use App\Service\AggregateConfigLoader;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ScriptController
{
    public function __construct(
        private readonly AggregateConfigLoader $config,
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
        $content = preg_replace(
            "/var namespace = 'Aggregate';/",
            "var namespace = '" . addslashes($namespace) . "';",
            $content
        );

        $response = new Response($content);
        $response->headers->set('Content-Type', 'application/javascript');
        $response->headers->set('Cache-Control', 'public, max-age=3600'); // Cache for 1 hour

        return $response;
    }
}
