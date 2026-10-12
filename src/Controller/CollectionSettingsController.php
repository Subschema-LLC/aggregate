<?php

namespace App\Controller;

use App\Service\AggregateConfigLoader;
use App\Service\CollectionControlsForm;
use App\Service\CollectionProfile;
use App\Service\CustomDataSettings;
use App\Service\Operations\TaskTrigger;
use App\Service\TrackerBuilds;
use App\Service\TrackingFailureRetryRunner;
use App\Service\TrackingFailureSettings;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/** Collection controls: profile, kill switch, excluded paths, geography, Google Consent Mode signals and tracking retries. */
class CollectionSettingsController extends AbstractController
{
    use DashboardAccess;

    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly TrackingFailureSettings $trackingFailureSettings,
        private readonly TrackingFailureRetryRunner $trackingFailureRetryRunner,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/dashboard/collection', name: 'app_collection_settings', methods: ['GET'])]
    public function collectionSettings(): Response
    {
        $this->denyIfDashboardDisabled();
        $this->denyIfNotAdmin();

        $excludedPaths = $this->config->getWithEnvFallback('anonymous_excluded_paths', []);
        if (is_string($excludedPaths)) {
            $excludedPaths = preg_split('/[\r\n,]+/', $excludedPaths) ?: [];
        }
        if (!is_array($excludedPaths)) {
            $excludedPaths = [];
        }
        $geoLevel = $this->config->getWithEnvFallback('anonymous_geo_level', 'macro_region');
        $geoLevel = is_string($geoLevel) ? strtolower(trim($geoLevel)) : 'macro_region';
        if (!in_array($geoLevel, ['macro_region', 'country'], true)) {
            $geoLevel = 'macro_region';
        }
        $geoDatabasePath = $this->config->getWithEnvFallback('anonymous_geo_database_path', '');
        $collectionProfile = new CollectionProfile($this->config);
        $customData = new CustomDataSettings($this->config);
        $trackingRetrySettings = TrackingFailureSettings::defaults();
        $trackingRetryOverrides = [
            TrackingFailureSettings::KEY_ENABLED => false,
            TrackingFailureSettings::KEY_BATCH_SIZE => false,
        ];
        try {
            $trackingRetrySettings = $this->trackingFailureSettings->toArray();
            $trackingRetryOverrides = $this->trackingFailureSettings->getEnvironmentOverrides();
        } catch (\Throwable $error) {
            $this->logger->error('Failed to load tracking retry settings.', ['exception' => $error]);
        }
        // The failure status comes from the database, so the page loads it
        // afterwards from app_tracking_failure_status: collection controls,
        // including the kill switch, must open while the database is down.

        return $this->renderDashboardPage('settings/collection.html.twig', [
            'collection_profile' => $collectionProfile->name(),
            'collection_profile_env_override' => $collectionProfile->hasEnvironmentOverride(),
            'tracker_omit_unused_features' => (new TrackerBuilds($this->config))->enabled(),
            'anonymous_tracking_enabled' => $this->config->getBoolWithEnvFallback('anonymous_tracking_enabled', true),
            'anonymous_excluded_paths' => array_values(array_filter($excludedPaths, 'is_string')),
            'anonymous_geo_enabled' => $this->config->getBoolWithEnvFallback('anonymous_geo_enabled', false),
            'anonymous_geo_level' => $geoLevel,
            'anonymous_geo_database_path' => is_string($geoDatabasePath) ? trim($geoDatabasePath) : '',
            'tracking_retry_settings' => $trackingRetrySettings,
            'tracking_retry_environment_overrides' => $trackingRetryOverrides,
            'google_consent_mode' => $customData->googleConsentMode(),
            'google_consent_mode_invalid' => $customData->hasInvalidGoogleConsentMode(),
        ]);
    }

    /**
     * The last tracking failures and retry run, read from processing tasks
     * after the Collection controls page has rendered. The status is rendered
     * here from the Twig partial, so the browser only swaps it in.
     */
    #[Route('/dashboard/collection/tracking-status', name: 'app_tracking_failure_status', methods: ['GET'])]
    public function trackingFailureStatus(): JsonResponse
    {
        $this->denyIfDashboardDisabled();
        $this->denyIfNotAdmin();
        $headers = ['Cache-Control' => 'private, no-store, max-age=0'];

        try {
            $status = [
                'tracking_last_failure' => $this->trackingFailureRetryRunner->latestFailure(),
                'tracking_last_ingestion_failure' => $this->trackingFailureRetryRunner->latestIngestionFailure(),
                'tracking_last_retry' => $this->trackingFailureRetryRunner->latestRetry(),
            ];
        } catch (\Throwable $error) {
            $this->logger->warning('Tracking failure status could not be read.', ['exception' => $error]);

            return new JsonResponse(['error' => 'Tracking failure status is unavailable.'], Response::HTTP_SERVICE_UNAVAILABLE, $headers);
        }

        return new JsonResponse(['html' => $this->renderView('settings/_tracking_failure_status.html.twig', $status)], Response::HTTP_OK, $headers);
    }

    #[Route('/dashboard/settings/anonymous', name: 'app_anonymous_settings_save', methods: ['POST'])]
    public function saveAnonymousSettings(Request $request): Response
    {
        $this->denyIfDashboardDisabled();
        $this->denyIfNotAdmin();

        $csrfToken = (string) $request->request->get('_csrf_token', '');
        if (!$this->isCsrfTokenValid('anonymous_settings', $csrfToken)) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('app_collection_settings');
        }

        try {
            $form = CollectionControlsForm::fromSubmission($request->request, new CustomDataSettings($this->config));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute('app_collection_settings');
        }
        $collectionProfile = $form['collection_profile'];

        try {
            $this->config->setMany($form['settings']);
            $this->trackingFailureSettings->save($form['retry']);
            $effectiveGeoEnabled = $this->config->getBoolWithEnvFallback('anonymous_geo_enabled', false);
            $effectiveGeoPath = $this->config->getWithEnvFallback('anonymous_geo_database_path', '');
            $effectiveProfile = new CollectionProfile($this->config);
            if ($effectiveProfile->hasEnvironmentOverride() && $effectiveProfile->name() !== $collectionProfile) {
                $this->addFlash('warning', sprintf('The COLLECTION_PROFILE environment variable keeps the %s profile in effect. The saved YAML value applies only when that variable is removed.', $effectiveProfile->name()));
            }
            if ($effectiveGeoEnabled && !$effectiveProfile->isStrict() && (!is_string($effectiveGeoPath) || trim($effectiveGeoPath) === '')) {
                $this->addFlash('warning', 'Coarse geography is enabled without a local GeoIP database path. Events will continue with no geography until one is configured.');
            }
            $this->addFlash('success', 'Analytics collection settings updated successfully.');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to save analytics privacy settings: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_collection_settings');
    }

    #[Route('/dashboard/settings/tracking/retry', name: 'app_tracking_retry_now', methods: ['POST'])]
    public function retryTrackingFailures(Request $request): Response
    {
        $this->denyIfDashboardDisabled();
        $this->denyIfNotAdmin();

        $csrfToken = (string) $request->request->get('_csrf_token', '');
        if (!$this->isCsrfTokenValid('tracking_retry_now', $csrfToken)) {
            $this->addFlash('error', 'Invalid security token. Please try again.');

            return $this->redirectToRoute('app_collection_settings');
        }

        try {
            $result = $this->trackingFailureRetryRunner->retryNow(
                TaskTrigger::dashboard($this->getUser()?->getUserIdentifier() ?? 'administrator'),
            );
        } catch (\InvalidArgumentException $error) {
            $this->addFlash('error', $error->getMessage());

            return $this->redirectToRoute('app_collection_settings');
        } catch (\Throwable $error) {
            $this->logger->error('Retrying failed tracking messages from the dashboard failed.', ['exception' => $error]);
            $this->addFlash('error', 'Tracking retries could not start; see the application log.');

            return $this->redirectToRoute('app_collection_settings');
        }

        if ($result['status'] === 'disabled') {
            $this->addFlash('warning', $result['message']);
        } elseif ($result['status'] === 'busy') {
            $this->addFlash('warning', $result['message']);
        } elseif ($result['status'] === 'failed') {
            $this->addFlash('error', $result['message']);
        } else {
            $this->addFlash('success', $result['message']);
        }

        return $this->redirectToRoute('app_collection_settings');
    }
}
