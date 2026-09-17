<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AggregateConfigLoader;
use App\Service\AnalyticsDataLifecyclePolicy;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class DataLifecycleController extends AbstractController
{
    public const CSRF_TOKEN_ID = 'data_lifecycle_settings';

    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly AnalyticsDataLifecyclePolicy $policy,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/dashboard/data-lifecycle', name: 'app_data_lifecycle', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyUnlessAvailableToAdmin();

        $configurationError = null;
        try {
            $settings = $this->policy->toArray();
        } catch (\Throwable $e) {
            $this->logger->error('Failed to load analytics data lifecycle settings.', ['exception' => $e]);
            $settings = AnalyticsDataLifecyclePolicy::validate([]);
            $configurationError = 'The active lifecycle configuration is invalid. Correct its YAML or environment values before maintenance is run.';
        }

        return $this->render('data_lifecycle/index.html.twig', [
            'lifecycle_settings' => $settings,
            'lifecycle_environment_overrides' => $this->policy->getEnvironmentOverrides(),
            'lifecycle_configuration_error' => $configurationError,
        ]);
    }

    #[Route('/dashboard/data-lifecycle/save', name: 'app_data_lifecycle_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $this->denyUnlessAvailableToAdmin();

        $submitted = $request->request->all();
        $csrfToken = $submitted['_csrf_token'] ?? null;
        if (!is_string($csrfToken) || !$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $csrfToken)) {
            $this->addFlash('error', 'Invalid security token. Please try again.');

            return $this->redirectToRoute('app_data_lifecycle');
        }

        $allowedKeys = [
            '_csrf_token' => true,
            AnalyticsDataLifecyclePolicy::KEY_ARCHIVING_ENABLED => true,
            AnalyticsDataLifecyclePolicy::KEY_ARCHIVE_AFTER_DAYS => true,
            AnalyticsDataLifecyclePolicy::KEY_RETENTION_ENABLED => true,
            AnalyticsDataLifecyclePolicy::KEY_ANONYMOUS_RETENTION_DAYS => true,
            AnalyticsDataLifecyclePolicy::KEY_ENHANCED_RETENTION_DAYS => true,
            AnalyticsDataLifecyclePolicy::KEY_ARCHIVE_RETENTION_DAYS => true,
            AnalyticsDataLifecyclePolicy::KEY_MAINTENANCE_BATCH_SIZE => true,
        ];
        $unknownKeys = array_diff_key($submitted, $allowedKeys);
        if ($unknownKeys !== []) {
            $this->addFlash('error', 'The lifecycle form contained an unexpected setting. Nothing was saved.');

            return $this->redirectToRoute('app_data_lifecycle');
        }

        try {
            $current = $this->policy->toArray();
            $overrides = $this->policy->getEnvironmentOverrides();
        } catch (\Throwable $e) {
            $this->logger->error('Failed to load analytics data lifecycle settings for update.', ['exception' => $e]);
            $this->addFlash('error', 'The active lifecycle configuration is invalid. Correct its YAML or environment values before saving.');

            return $this->redirectToRoute('app_data_lifecycle');
        }

        $candidate = $current;
        foreach (array_keys($current) as $key) {
            if ($overrides[$key]) {
                continue;
            }

            if (!array_key_exists($key, $submitted) || !is_scalar($submitted[$key])) {
                $this->addFlash('error', 'Every lifecycle setting not controlled by the environment must be submitted. Nothing was saved.');

                return $this->redirectToRoute('app_data_lifecycle');
            }

            $candidate[$key] = $submitted[$key];
        }

        try {
            $validated = AnalyticsDataLifecyclePolicy::validate($candidate);
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_data_lifecycle');
        }

        $valuesToSave = [];
        foreach ($validated as $key => $value) {
            if (!$overrides[$key]) {
                $valuesToSave[$key] = $value;
            }
        }

        if ($valuesToSave === []) {
            $this->addFlash('warning', 'All lifecycle settings are controlled by environment variables; no YAML values were changed.');

            return $this->redirectToRoute('app_data_lifecycle');
        }

        try {
            $this->config->setMany($valuesToSave);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to save analytics data lifecycle settings.', ['exception' => $e]);
            $this->addFlash('error', 'Lifecycle settings could not be saved. Check the application logs and configuration-file permissions.');

            return $this->redirectToRoute('app_data_lifecycle');
        }

        if (in_array(true, $overrides, true)) {
            $this->addFlash('warning', 'Environment-controlled lifecycle settings were left unchanged.');
        }
        $this->addFlash('success', 'Analytics archiving and retention settings were saved.');

        return $this->redirectToRoute('app_data_lifecycle');
    }

    private function denyUnlessAvailableToAdmin(): void
    {
        if (!$this->config->isDashboardEnabled()) {
            throw $this->createNotFoundException('Dashboard is disabled.');
        }

        $this->denyAccessUnlessGranted('ROLE_ADMIN');
    }
}
