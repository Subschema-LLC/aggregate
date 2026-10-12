<?php

namespace App\Controller;

use App\Service\AggregateConfigLoader;
use App\Service\AnalyticsPrivacySettings;
use App\Service\AnonymousBiViewManager;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/** BI disclosure: the minimum event counts compiled into the anonymous BI views, saved to YAML before the views are regenerated. */
class PrivacySettingsController extends AbstractController
{
    use DashboardAccess;

    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly AnalyticsPrivacySettings $analyticsPrivacySettings,
        private readonly AnonymousBiViewManager $anonymousBiViewManager,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/dashboard/privacy', name: 'app_privacy_settings', methods: ['GET'])]
    public function privacySettings(): Response
    {
        $this->denyIfDashboardDisabled();
        $this->denyIfNotAdmin();

        $privacySettingsError = false;
        try {
            $minimumCellCounts = $this->analyticsPrivacySettings->getMinimumCellCounts();
        } catch (\Throwable $e) {
            $this->logger->error('Failed to load BI disclosure thresholds.', ['exception' => $e]);
            $minimumCellCounts = [
                'anonymous' => AnalyticsPrivacySettings::DEFAULT_MINIMUM_CELL_COUNT,
                'geo' => AnalyticsPrivacySettings::DEFAULT_GEO_MINIMUM_CELL_COUNT,
            ];
            $privacySettingsError = true;
        }

        return $this->renderDashboardPage('settings/privacy.html.twig', [
            'anonymous_min_cell_count' => $minimumCellCounts['anonymous'],
            'anonymous_geo_min_cell_count' => $minimumCellCounts['geo'],
            'analytics_privacy_settings_error' => $privacySettingsError,
        ]);
    }

    #[Route('/dashboard/settings/analytics-privacy', name: 'app_analytics_privacy_settings_save', methods: ['POST'])]
    public function saveAnalyticsPrivacySettings(Request $request): Response
    {
        $this->denyIfDashboardDisabled();
        $this->denyIfNotAdmin();

        $csrfToken = (string) $request->request->get('_csrf_token', '');
        if (!$this->isCsrfTokenValid('analytics_privacy_settings', $csrfToken)) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('app_privacy_settings');
        }

        $submittedSettings = $request->request->all();
        $minimumCellCount = $this->parseIntegerSetting(
            $submittedSettings['anonymous_min_cell_count'] ?? null,
        );
        $geoMinimumCellCount = $this->parseIntegerSetting(
            $submittedSettings['anonymous_geo_min_cell_count'] ?? null,
        );

        if ($minimumCellCount === null || $geoMinimumCellCount === null) {
            $this->addFlash('error', 'Both BI disclosure thresholds must be whole numbers.');
            return $this->redirectToRoute('app_privacy_settings');
        }

        if ($minimumCellCount < AnalyticsPrivacySettings::MINIMUM_CELL_COUNT
            || $minimumCellCount > AnalyticsPrivacySettings::MAXIMUM_CELL_COUNT) {
            $this->addFlash('error', 'Minimum BI cell count must be between 2 and 1000.');
            return $this->redirectToRoute('app_privacy_settings');
        }

        if ($geoMinimumCellCount < AnalyticsPrivacySettings::GEO_MINIMUM_CELL_COUNT
            || $geoMinimumCellCount > AnalyticsPrivacySettings::MAXIMUM_CELL_COUNT) {
            $this->addFlash('error', 'Geography minimum BI cell count must be between 10 and 1000.');
            return $this->redirectToRoute('app_privacy_settings');
        }

        try {
            $this->analyticsPrivacySettings->saveMinimumCellCounts($minimumCellCount, $geoMinimumCellCount);
            $effectiveMinimums = $this->analyticsPrivacySettings->getMinimumCellCounts();
            $this->anonymousBiViewManager->regenerate(
                $effectiveMinimums['anonymous'],
                $effectiveMinimums['geo'],
            );
            $this->addFlash('success', 'BI disclosure thresholds were saved to configuration and BI views were regenerated.');
            if ($this->analyticsPrivacySettings->hasAnonymousMinimumEnvironmentOverride()
                || $this->analyticsPrivacySettings->hasGeoMinimumEnvironmentOverride()) {
                $this->addFlash('warning', 'Environment variables override one or more saved BI threshold values.');
            }
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('Failed to save BI disclosure thresholds.', ['exception' => $e]);
            $this->addFlash('error', 'Failed to save BI disclosure thresholds in configuration or regenerate BI views. Check the application logs.');
        }

        return $this->redirectToRoute('app_privacy_settings');
    }

    private function parseIntegerSetting(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (!is_string($value) || preg_match('/^-?[0-9]+$/D', trim($value)) !== 1) {
            return null;
        }

        return (int) $value;
    }
}
