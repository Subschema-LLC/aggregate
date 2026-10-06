<?php

namespace App\Controller;

use App\Service\AggregateConfigLoader;
use App\Service\BrowserScriptCache;
use App\Service\BrowserScriptCompactor;
use App\Service\CustomDataSettings;
use App\Service\InternalTrafficSettings;
use App\Service\TrackerScript;
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
        $script = (new TrackerScript($this->config, $this->internalTraffic, $this->customData, $this->projectDir, $this->compactor))
            ->render($request?->query->get('min') === '1');
        if ($script === null) {
            return new Response('Script not found', Response::HTTP_NOT_FOUND);
        }

        $response = new Response($script['content']);
        $response->headers->set('Content-Type', 'application/javascript');
        // minified, compact or source; and which features the build keeps.
        $response->headers->set('X-Aggregate-Script', $script['variant']);
        $response->headers->set('X-Aggregate-Build', $script['build']);

        // Reused for five minutes, then confirmed unchanged with a 304.
        return BrowserScriptCache::apply($response, $request);
    }
}
