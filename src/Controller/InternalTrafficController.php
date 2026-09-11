<?php

declare(strict_types=1);

namespace App\Controller;

use App\EventSubscriber\InternalTrafficResponseSubscriber;
use App\Service\AggregateConfigLoader;
use App\Service\InternalTrafficSettings;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class InternalTrafficController extends AbstractController
{
    public const CSRF_TOKEN_ID = 'internal_traffic_settings';

    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly InternalTrafficSettings $settings,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/dashboard/internal-traffic', name: 'app_internal_traffic', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyUnlessAvailableToAdmin();
        $error = null;
        $shareUrl = null;
        $marker = InternalTrafficSettings::DEFAULTS;
        $browserConfig = null;
        try {
            $marker = $this->settings->markerSettings();
            $browserConfig = $this->settings->toBrowserConfig();
            $token = $this->settings->getShareToken();
            if ($token !== '') {
                $shareUrl = $this->generateUrl('app_internal_traffic_public', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to load internal traffic settings.', ['exception' => $e]);
            $error = 'The organization traffic configuration is invalid. Correct its YAML or environment values before using it.';
            $browserConfig = null;
        }

        return InternalTrafficResponseSubscriber::protect($this->render('dashboard/internal_traffic.html.twig', [
            'settings' => $marker,
            'browser_config' => $browserConfig,
            'overrides' => $this->settings->getEnvironmentOverrides(),
            'configuration_error' => $error,
            'share_url' => $shareUrl,
        ]));
    }

    #[Route('/dashboard/internal-traffic/save', name: 'app_internal_traffic_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $this->denyUnlessAvailableToAdmin();
        $submitted = $request->request->all();
        $csrf = $submitted['_csrf_token'] ?? null;
        if (!is_string($csrf) || !$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $csrf)) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
        } else {
            unset($submitted['_csrf_token']);
            $action = $submitted['action'] ?? 'save';
            unset($submitted['action']);
            try {
                if ($action === 'save') {
                    $this->settings->saveMarker($submitted);
                    $message = 'Organization traffic marker settings were saved. Use the button below to mark this browser with the saved settings.';
                } elseif ($submitted === [] && $action === 'rotate') {
                    $this->settings->rotateShareToken();
                    $message = 'A new share link was created. Previous links no longer work.';
                } elseif ($submitted === [] && $action === 'revoke') {
                    $this->settings->revokeShareToken();
                    $message = 'The share link was revoked. Existing browser markers remain in place.';
                } else {
                    throw new \InvalidArgumentException('The form contained an unexpected action or setting. Nothing was saved.');
                }
                $this->addFlash('success', $message);
            } catch (\InvalidArgumentException $e) {
                $this->addFlash('error', $e->getMessage());
            } catch (\Throwable $e) {
                $this->logger->error('Failed to save internal traffic settings.', ['exception' => $e]);
                $this->addFlash('error', 'Organization traffic settings could not be saved. Check the configuration-file permissions and application logs.');
            }
        }

        return InternalTrafficResponseSubscriber::protect($this->redirectToRoute('app_internal_traffic'));
    }

    #[Route('/dashboard/internal-traffic/download', name: 'app_internal_traffic_download', methods: ['GET'])]
    public function download(): Response
    {
        $this->denyUnlessAvailableToAdmin();
        $response = $this->render('internal_traffic/public.html.twig', [
            'browser_config' => $this->settings->toBrowserConfig(),
            'download_url' => null,
        ]);
        $response->headers->set('Content-Disposition', 'attachment; filename="internal-traffic.html"');

        return InternalTrafficResponseSubscriber::protect($response);
    }

    private function denyUnlessAvailableToAdmin(): void
    {
        if (!$this->config->isDashboardEnabled()) {
            throw $this->createNotFoundException('Dashboard is disabled.');
        }
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
    }
}
