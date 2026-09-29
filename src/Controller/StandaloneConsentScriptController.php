<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\DropInScripts;
use App\Service\SiteScriptConfig;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class StandaloneConsentScriptController
{
    public function __construct(private readonly DropInScripts $scripts, private readonly SiteScriptConfig $sites)
    {
    }

    #[Route('/standalone-cmp/sites/{siteId}/consent.js', name: 'standalone_consent_script', requirements: ['siteId' => '[a-f0-9]{24}'], methods: ['GET'])]
    public function __invoke(Request $request, string $siteId): Response
    {
        $headers = ['Content-Type' => 'application/javascript; charset=UTF-8', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff'];
        try {
            $this->sites->site($siteId);
        } catch (\InvalidArgumentException) {
            return new Response('/* Website script instance not found. */', 404, $headers);
        }
        try {
            $script = $this->scripts->standaloneConsentScript(($request->query->all()['min'] ?? null) === '1', $siteId);
        } catch (\Throwable) {
            return new Response('/* Standalone consent unavailable; optional consent remains denied. */', 503, $headers);
        }
        $headers['Cache-Control'] = 'public, max-age=0, must-revalidate';
        $headers['X-Aggregate-Script'] = $script['minified'] ? 'minified' : 'source';

        return new Response($script['content'], headers: $headers);
    }
}
