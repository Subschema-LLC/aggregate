<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AggregateConfigLoader;
use App\Service\FeatureFlags;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class FeatureFlagsController extends AbstractController
{
    public const CSRF_TOKEN_ID = 'feature_flags_settings';

    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly FeatureFlags $flags,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/dashboard/feature-flags', name: 'app_feature_flags', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyUnlessAvailableToAdmin();
        $settings = [];
        $configurationError = null;
        try {
            $settings = $this->flags->all();
        } catch (\Throwable $e) {
            $configurationError = 'Feature flag configuration could not be loaded. Correct the active YAML configuration before saving. Flagged features remain unavailable while configuration is invalid.';
            $this->logFailure('load', $e);
        }

        return $this->privateResponse($this->render('feature_flags/index.html.twig', [
            'definitions' => $this->flags->definitions(),
            'settings' => $settings,
            'configuration_error' => $configurationError,
        ]));
    }

    #[Route('/dashboard/feature-flags/save', name: 'app_feature_flags_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $this->denyUnlessAvailableToAdmin();
        $submitted = $request->request->all();
        $csrf = $submitted['_csrf_token'] ?? null;
        if (!is_string($csrf) || !$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $csrf)) {
            $this->addFlash('error', 'Invalid security token. Please try again.');

            return $this->back();
        }

        try {
            $this->flags->save($this->settingsFromForm($submitted));
            $this->addFlash('success', 'Feature flags saved. Changes apply to the active configuration.');
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (\Throwable $e) {
            $this->logFailure('save', $e);
            $this->addFlash('error', 'Feature flags could not be saved. Check the active YAML configuration and file permissions.');
        }

        return $this->back();
    }

    private function settingsFromForm(array $submitted): array
    {
        if (array_diff(array_keys($submitted), ['_csrf_token', 'feature_flags']) !== []
            || !is_array($submitted['feature_flags'] ?? null)) {
            throw new \InvalidArgumentException('The feature flags form contained missing or unexpected settings. Nothing was saved.');
        }

        $rows = $submitted['feature_flags'];
        $names = array_keys($this->flags->definitions());
        if (array_diff(array_keys($rows), $names) !== [] || array_diff($names, array_keys($rows)) !== []) {
            throw new \InvalidArgumentException('The feature flags form does not match the available features. Reload this page and try again. Nothing was saved.');
        }

        $settings = [];
        foreach ($rows as $name => $row) {
            if (!is_array($row) || array_diff(array_keys($row), ['enabled', 'hide_from_navigation']) !== []) {
                throw new \InvalidArgumentException('Each feature needs valid availability and navigation settings. Nothing was saved.');
            }
            foreach (['enabled', 'hide_from_navigation'] as $property) {
                if (!in_array($row[$property] ?? null, ['0', '1'], true)) {
                    throw new \InvalidArgumentException('Each feature needs valid availability and navigation settings. Nothing was saved.');
                }
                $settings[$name][$property] = $row[$property] === '1';
            }
        }

        return $settings;
    }

    private function back(): Response
    {
        return $this->privateResponse($this->redirectToRoute('app_feature_flags'));
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private function logFailure(string $operation, \Throwable $error): void
    {
        $this->logger->error('Feature flag configuration operation failed.', ['operation' => $operation, 'exception_class' => $error::class]);
    }

    private function denyUnlessAvailableToAdmin(): void
    {
        if (!$this->config->isDashboardEnabled()) {
            throw $this->createNotFoundException('Dashboard is disabled.');
        }
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
    }
}
