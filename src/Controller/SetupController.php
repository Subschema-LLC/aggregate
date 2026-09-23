<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AggregateConfigLoader;
use App\Service\DropInScripts;
use App\Service\SiteScriptConfig;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SetupController extends AbstractController
{
    public function __construct(private readonly AggregateConfigLoader $config, private readonly DropInScripts $scripts)
    {
    }

    #[Route('/dashboard/setup', name: 'app_setup', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->authorize();
        $websites = $this->scripts->websites();
        $query = $request->query->all();
        $token = $query['website'] ?? ($websites[0]['token'] ?? '');
        [$tags, $format] = $this->snippetOptions($query);
        $step = $query['step'] ?? '1';
        $step = is_string($step) && in_array($step, ['1', '2', '3', '4'], true) ? (int) $step : 1;
        $error = null;
        $snippet = null;
        $consent = null;
        $loader = null;
        $siteId = null;
        $trackerUrl = null;
        try {
            if (!is_string($token)) {
                throw new \InvalidArgumentException('Choose a registered website.');
            }
            if ($token !== '') {
                $snippet = $this->scripts->snippet($token, $tags, $format);
                $trackerUrl = $this->scripts->trackerUrl($token);
                $siteId = SiteScriptConfig::idForToken($token);
            }
            if ($siteId !== null) {
                $consent = $this->scripts->consentScript(false, $siteId)['content'];
                $loader = $this->scripts->tagLoader($siteId);
            }
        } catch (\Throwable) {
            $error = 'Installation scripts are unavailable. Check the selected website and application host in general settings.';
        }

        return $this->privateResponse($this->render('setup/index.html.twig', [
            'websites' => $websites, 'selected_website' => is_string($token) ? $token : '', 'include_tags' => $tags,
            'step' => $step, 'snippet' => $snippet, 'consent_script' => $consent, 'tag_loader' => $loader, 'setup_error' => $error,
            'site_id' => $siteId, 'snippet_format' => $format, 'tracker_url' => $trackerUrl,
        ]));
    }

    #[Route('/dashboard/setup/download/{kind}', name: 'app_setup_download', methods: ['GET'])]
    public function download(Request $request, string $kind): Response
    {
        $this->authorize();
        if (!in_array($kind, ['snippet', 'consent', 'tags'], true)) {
            throw $this->createNotFoundException();
        }
        try {
            $query = $request->query->all();
            $token = $query['website'] ?? '';
            if (!is_string($token) || $token === '') {
                throw new \InvalidArgumentException('Choose a registered website.');
            }
            // Verify registration for every artifact, including a forged token.
            [$tags, $format] = $this->snippetOptions($query);
            $snippet = $this->scripts->snippet($token, $tags, $format);
            $siteId = SiteScriptConfig::idForToken($token);
            $content = match ($kind) {
                'snippet' => $snippet,
                'consent' => $this->scripts->consentScript(($query['min'] ?? null) === '1', $siteId)['content'],
                'tags' => $this->scripts->tagLoader($siteId),
            };
        } catch (\InvalidArgumentException) {
            return $this->privateResponse(new Response('Choose a registered website.', Response::HTTP_BAD_REQUEST));
        } catch (\Throwable) {
            return $this->privateResponse(new Response('Installation scripts are unavailable. Check the saved configuration.', Response::HTTP_SERVICE_UNAVAILABLE));
        }
        $filename = match ($kind) { 'snippet' => 'installation.html', 'consent' => 'consent.js', 'tags' => 'tag-loader.js' };

        return $this->privateResponse(new Response($content, headers: [
            'Content-Type' => $kind === 'snippet' ? 'text/plain; charset=UTF-8' : 'application/javascript; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]));
    }

    /** @return array{bool, string} */
    private function snippetOptions(array $query): array
    {
        $installation = $query['installation'] ?? null;
        if (in_array($installation, ['window', 'query', 'tags'], true)) {
            return [$installation === 'tags', $installation === 'query' ? 'query' : 'window'];
        }

        return [($query['tags'] ?? null) === '1', ($query['format'] ?? null) === 'query' ? 'query' : 'window'];
    }

    private function authorize(): void
    {
        if (!$this->config->isDashboardEnabled()) {
            throw $this->createNotFoundException('Dashboard is disabled.');
        }
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
