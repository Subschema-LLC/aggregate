<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AggregateConfigLoader;
use App\Service\DropInScripts;
use App\Service\SiteScriptConfig;
use App\Service\StandaloneConsentSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SetupController extends AbstractController
{
    public function __construct(private readonly AggregateConfigLoader $config, private readonly DropInScripts $scripts, private readonly StandaloneConsentSettings $standalone)
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
        $consentOption = $this->consentOption($query);
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
                $snippet = $this->scripts->snippet($token, $tags, $format, $consentOption);
                $trackerUrl = $this->scripts->trackerUrl($token);
                $siteId = SiteScriptConfig::idForToken($token);
            }
            if ($siteId !== null) {
                $consent = match ($consentOption) {
                    'standalone' => $this->scripts->standaloneConsentScript(false, $siteId)['content'],
                    'builtin' => $this->scripts->consentScript(false, $siteId)['content'],
                    default => null,
                };
                $loader = $this->scripts->tagLoader($siteId);
            }
        } catch (\Throwable) {
            $error = 'Installation scripts are unavailable. Check the selected website and application host in general settings.';
        }

        return $this->privateResponse($this->render('setup/index.html.twig', [
            'websites' => $websites, 'selected_website' => is_string($token) ? $token : '', 'include_tags' => $tags,
            'step' => $step, 'snippet' => $snippet, 'consent_script' => $consent, 'tag_loader' => $loader, 'setup_error' => $error,
            'site_id' => $siteId, 'snippet_format' => $format, 'tracker_url' => $trackerUrl,
            'consent_option' => $consentOption,
        ]));
    }

    #[Route('/dashboard/setup/download/{kind}', name: 'app_setup_download', methods: ['GET'])]
    public function download(Request $request, string $kind): Response
    {
        $this->authorize();
        if (!in_array($kind, ['snippet', 'consent', 'tags', 'standalone-consent', 'standalone-settings'], true)) {
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
            $option = str_starts_with($kind, 'standalone-') ? 'standalone' : $this->consentOption($query);
            $snippet = $this->scripts->snippet($token, $tags, $format, $option);
            $siteId = SiteScriptConfig::idForToken($token);
            $content = match ($kind) {
                'snippet' => $snippet,
                'consent' => $this->scripts->consentScript(($query['min'] ?? null) === '1', $siteId)['content'],
                'tags' => $this->scripts->tagLoader($siteId),
                'standalone-consent' => $this->scripts->standaloneConsentScript(($query['min'] ?? null) === '1', $siteId)['content'],
                'standalone-settings' => $this->standalone->exportYaml($siteId),
            };
        } catch (\InvalidArgumentException) {
            return $this->privateResponse(new Response('Choose a registered website.', Response::HTTP_BAD_REQUEST));
        } catch (\Throwable) {
            return $this->privateResponse(new Response('Installation scripts are unavailable. Check the saved configuration.', Response::HTTP_SERVICE_UNAVAILABLE));
        }
        $filename = match ($kind) {
            'snippet' => 'installation.html', 'consent' => 'consent.js', 'tags' => 'tag-loader.js',
            'standalone-consent' => 'standalone-consent.js', 'standalone-settings' => 'standalone-consent.yaml',
        };

        return $this->privateResponse(new Response($content, headers: [
            'Content-Type' => in_array($kind, ['snippet', 'standalone-settings'], true) ? 'text/plain; charset=UTF-8' : 'application/javascript; charset=UTF-8',
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

    private function consentOption(array $query): string
    {
        return in_array($query['consent_option'] ?? null, ['builtin', 'standalone', 'external'], true)
            ? $query['consent_option'] : 'builtin';
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
