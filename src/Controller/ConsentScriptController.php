<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\DropInScripts;
use App\Service\SiteScriptConfig;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ConsentScriptController
{
    public function __construct(private readonly DropInScripts $scripts, private readonly ?SiteScriptConfig $sites = null)
    {
    }

    #[Route('/consent-manager.js', name: 'consent_script', methods: ['GET'])]
    #[Route('/cmp-lite/sites/{siteId}/consent.js', name: 'site_consent_script', requirements: ['siteId' => '[a-f0-9]{24}'], methods: ['GET'])]
    public function __invoke(Request $request, ?string $siteId = null): Response
    {
        if ($siteId !== null) {
            try {
                $this->sites?->site($siteId) ?? throw new \InvalidArgumentException();
            } catch (\InvalidArgumentException) {
                return new Response('/* Website script instance not found. */', 404, ['Content-Type' => 'application/javascript', 'Cache-Control' => 'no-store']);
            }
        }
        try {
            $script = $this->scripts->consentScript(($request->query->all()['min'] ?? null) === '1', $siteId);
        } catch (\Throwable) {
            return new Response('/* Consent script unavailable; consent remains denied. */', Response::HTTP_SERVICE_UNAVAILABLE, [
                'Content-Type' => 'application/javascript; charset=UTF-8', 'Cache-Control' => 'no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        return new Response($script['content'], headers: [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            'Cache-Control' => 'public, max-age=0, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
            'X-Aggregate-Script' => $script['minified'] ? 'minified' : 'source',
        ]);
    }
}
