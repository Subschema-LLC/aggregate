<?php

namespace App\Controller;

use App\Service\AggregateConfigLoader;
use App\Service\BrowserScriptCache;
use App\Service\DocumentationLinks;
use App\Service\TrackerBuilds;
use App\Service\TrackerScript;
use App\Service\TrackingAttributes;
use App\Service\WebsiteActivityService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/** Application settings: host, tracker namespace, rate limit, activity thresholds, documentation address and page speed. */
class ApplicationSettingsController extends AbstractController
{
    use DashboardAccess;

    public function __construct(
        private readonly AggregateConfigLoader $config,
    ) {}

    #[Route('/dashboard/settings', name: 'app_application_settings', methods: ['GET'])]
    public function applicationSettings(DocumentationLinks $documentation, TrackerBuilds $trackerBuilds, TrackerScript $tracker): Response
    {
        $this->denyIfDashboardDisabled();
        $this->denyIfNotAdmin();

        try {
            $sizes = $tracker->sizes();
        } catch (\Throwable) {
            // Invalid collection settings stop the tracker from being served;
            // the page still opens so they can be repaired.
            $sizes = null;
        }

        return $this->renderDashboardPage('settings/application.html.twig', [
            'app_host' => $this->config->getWithEnvFallback('app_host', 'http://localhost:8000'),
            'js_namespace' => $this->config->getWithEnvFallback('js_namespace', 'Aggregate'),
            'tracking_attributes' => TrackingAttributes::names($this->config->getWithEnvFallback('js_namespace', 'Aggregate')),
            'rate_limit' => $this->config->getWithEnvFallback('rate_limit_per_minute', 100),
            'website_activity_active_days' => (int) $this->config->getWithEnvFallback('website_activity_active_days', 1),
            'website_activity_stale_days' => (int) $this->config->getWithEnvFallback('website_activity_stale_days', 3),
            'documentation_url' => $documentation->configuredValue(),
            'documentation_url_overridden' => $documentation->hasEnvironmentOverride(),
            'documentation_default_url' => DocumentationLinks::DEFAULT_URL,
            'page_speed' => [
                'omit_unused_features' => $trackerBuilds->enabled(),
                'omit_unused_features_overridden' => $trackerBuilds->hasEnvironmentOverride(),
                'tracker' => $sizes,
                'build_label' => $sizes !== null ? TrackerBuilds::describe($sizes['build']) : null,
                'cache_minutes' => intdiv(BrowserScriptCache::MAX_AGE, 60),
            ],
        ]);
    }

    /** The page speed setting; also YAML tracker_omit_unused_features or the environment. */
    #[Route('/dashboard/settings/page-speed', name: 'app_page_speed_save', methods: ['POST'])]
    public function savePageSpeed(Request $request, TrackerBuilds $trackerBuilds): Response
    {
        $this->denyIfDashboardDisabled();
        $this->denyIfNotAdmin();

        if (!$this->isCsrfTokenValid('page_speed', (string) $request->request->get('_csrf_token', ''))) {
            $this->addFlash('error', 'Invalid security token. Please try again.');

            return $this->redirect($this->generateUrl('app_application_settings').'#page-speed');
        }
        if ($trackerBuilds->hasEnvironmentOverride()) {
            $this->addFlash('error', 'Leave out unused tracker features is set by the TRACKER_OMIT_UNUSED_FEATURES environment variable, which takes precedence over this page.');

            return $this->redirect($this->generateUrl('app_application_settings').'#page-speed');
        }

        try {
            $enabled = TrackerBuilds::normalize($request->request->get(TrackerBuilds::CONFIG_KEY, '0'));
            $this->config->set(TrackerBuilds::CONFIG_KEY, $enabled);
            $this->addFlash('success', $enabled
                ? 'Visitors now get the smallest tracker for your settings. Browsers that already have the tracker receive it within five minutes.'
                : 'Every visitor now gets the full tracker. Browsers that already have the tracker receive it within five minutes.');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to save the page speed setting: '.$e->getMessage());
        }

        return $this->redirect($this->generateUrl('app_application_settings').'#page-speed');
    }

    #[Route('/dashboard/settings/save', name: 'app_settings_save', methods: ['POST'])]
    public function saveSettings(Request $request, DocumentationLinks $documentation, ?WebsiteActivityService $activityService = null): Response
    {
        $this->denyIfDashboardDisabled();
        $this->denyIfNotAdmin();

        if (!$this->isCsrfTokenValid('app_settings', (string) $request->request->get('_csrf_token', ''))) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('app_application_settings');
        }

        $appHost = trim($request->request->get('app_host', ''));
        $jsNamespace = trim($request->request->get('js_namespace', 'Aggregate'));
        $rateLimit = (int) $request->request->get('rate_limit', 100);
        $activeDays = max(1, min(365, (int) $request->request->get('website_activity_active_days', 1)));
        $staleDays = max($activeDays, min(365, (int) $request->request->get('website_activity_stale_days', 3)));

        if (empty($appHost)) {
            $this->addFlash('error', 'App Host is required.');
            return $this->redirectToRoute('app_application_settings');
        }

        $settings = [
            'app_host' => $appHost,
            'js_namespace' => $jsNamespace,
            'rate_limit_per_minute' => $rateLimit,
            'website_activity_active_days' => $activeDays,
            'website_activity_stale_days' => $staleDays,
        ];

        // DOCUMENTATION_URL takes precedence and its field is disabled. Save only a
        // changed value, so saving other settings does not pin the default address.
        if (!$documentation->hasEnvironmentOverride() && $request->request->has('documentation_url')) {
            try {
                $documentationUrl = DocumentationLinks::normalize($request->request->get('documentation_url'));
            } catch (\InvalidArgumentException $exception) {
                $this->addFlash('error', $exception->getMessage());
                return $this->redirectToRoute('app_application_settings');
            }
            if ($documentationUrl !== $documentation->configuredValue()) {
                $settings[DocumentationLinks::CONFIG_KEY] = $documentationUrl;
            }
        }

        try {
            $this->config->setMany($settings);
            // New thresholds apply when the Websites page next loads statuses.
            $activityService?->forget();

            $this->addFlash('success', 'Settings updated successfully in config/aggregate.yaml!');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to save settings: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_application_settings');
    }
}
