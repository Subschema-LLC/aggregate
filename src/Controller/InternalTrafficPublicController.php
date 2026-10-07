<?php

declare(strict_types=1);

namespace App\Controller;

use App\EventSubscriber\InternalTrafficResponseSubscriber;
use App\Service\CollectionProfile;
use App\Service\AggregateConfigLoader;
use App\Service\InternalTrafficMarking;
use App\Service\InternalTrafficSettings;
use App\Service\WebsiteConfigManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class InternalTrafficPublicController extends AbstractController
{
    public function __construct(
        private readonly InternalTrafficSettings $settings,
        private readonly InternalTrafficMarking $marking,
        private readonly WebsiteConfigManager $websites,
        private readonly AggregateConfigLoader $config,
    ) {
    }

    /**
     * Checks a marking code for the tracker on a website and tells it whether
     * to mark or remove, which cookie domain to use, and where to send the
     * browser next. Called cross-origin without credentials; the reply holds
     * no settings beyond the public cookie domain.
     */
    #[Route('/internal-traffic/verify', name: 'app_internal_traffic_verify', methods: ['GET'], priority: 10)]
    public function verify(Request $request): JsonResponse
    {
        $continueUrl = $this->marking->continueUrl($this->generateUrl('app_internal_traffic_continue', [], UrlGeneratorInterface::ABSOLUTE_URL));
        $action = (new CollectionProfile($this->config))->isStrict() ? null : $this->marking->verify($request->query->get('code'));
        $body = ['valid' => false, 'continueUrl' => $continueUrl];
        if ($action !== null) {
            try {
                $website = $this->websites->findOneByToken((string) $request->query->get('token', ''));
                $body = [
                    'valid' => true,
                    'action' => $action,
                    'cookieDomain' => $this->marking->cookieDomainFor($request->headers->get('Origin'), $website),
                    'continueUrl' => $continueUrl,
                ];
            } catch (\Throwable) {
                // Invalid marker settings: nothing can be marked until they are fixed.
            }
        }

        return new JsonResponse($body, Response::HTTP_OK, ['Access-Control-Allow-Origin' => '*']);
    }

    /** Where the tracker returns the browser; continues through the remaining websites. */
    #[Route('/internal-traffic/continue', name: 'app_internal_traffic_continue', methods: ['GET'], priority: 10)]
    public function continue(): Response
    {
        return InternalTrafficResponseSubscriber::protect($this->render('internal_traffic/continue.html.twig'));
    }

    #[Route('/internal-traffic/{token}', name: 'app_internal_traffic_public', defaults: ['token' => ''], methods: ['GET'])]
    public function index(Request $request, string $token = ''): Response
    {
        if (!$this->settings->matchesShareToken($token)) {
            return $this->unavailable();
        }
        try {
            $browserConfig = $this->settings->toBrowserConfig();
        } catch (\Throwable) {
            return $this->unavailable();
        }

        $download = $request->query->get('download') === '1';
        $response = $this->render('internal_traffic/public.html.twig', [
            'browser_config' => $browserConfig,
            'standalone' => $download,
            'download_url' => $download ? null : $this->generateUrl('app_internal_traffic_public', ['token' => $token, 'download' => '1']),
            // A downloaded page is hosted on one website; its codes would expire.
            'marking' => $download ? null : $this->marking->page(),
        ]);
        if ($download) {
            $response->headers->set('Content-Disposition', 'attachment; filename="internal-traffic.html"');
        }

        return InternalTrafficResponseSubscriber::protect($response);
    }

    private function unavailable(): Response
    {
        return InternalTrafficResponseSubscriber::protect(new Response(
            '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="robots" content="noindex,nofollow,noarchive"><meta name="referrer" content="no-referrer"><title>Page not found</title></head><body><h1>Page not found</h1></body></html>',
            Response::HTTP_NOT_FOUND,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        ));
    }
}
