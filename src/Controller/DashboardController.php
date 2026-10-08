<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\AggregateConfigLoader;
use App\Service\AnalyticsPrivacySettings;
use App\Service\BrandingLogoManager;
use App\Service\BrandingTheme;
use App\Service\CollectionProfile;
use App\Service\DocumentationLinks;
use App\Service\BrowserScriptCache;
use App\Service\DropInScripts;
use App\Service\TrackerBuilds;
use App\Service\TrackerScript;
use App\Service\TrackingAttributes;
use App\Service\WebsiteConfigManager;
use App\Service\WebsiteDomainPolicy;
use App\Service\WebsiteActivityService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;

class DashboardController extends AbstractController
{
    public function __construct(
        private readonly WebsiteConfigManager $websiteManager,
        private readonly AggregateConfigLoader $config,
        private readonly AnalyticsPrivacySettings $analyticsPrivacySettings,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly BrandingLogoManager $brandingLogoManager,
        private readonly WebsiteDomainPolicy $websiteDomainPolicy = new WebsiteDomainPolicy(),
    ) {}

    #[Route('/dashboard', name: 'app_dashboard', methods: ['GET'])]
    public function index(Request $request, DropInScripts $scripts, ?WebsiteActivityService $activityService = null): Response
    {
        $this->denyIfDashboardDisabled();
        $query = $request->query->all();
        $format = ($query['format'] ?? null) === 'query' ? 'query' : 'window';
        $withTags = ($query['tags'] ?? null) === '1';

        $defaultCmp = true;
        if ($this->config->has('consent_manager')) {
            $cm = $this->config->get('consent_manager');
            if (is_array($cm) && array_key_exists('enabled', $cm) && is_bool($cm['enabled'])) {
                $defaultCmp = $cm['enabled'];
            }
        }
        $cmpParam = $query['cmp'] ?? null;
        if ($cmpParam === '0' || $cmpParam === 'false' || ($query['consent_option'] ?? null) === 'external') {
            $includeCmp = false;
        } elseif ($cmpParam === '1' || $cmpParam === 'true' || ($query['consent_option'] ?? null) === 'builtin') {
            $includeCmp = true;
        } else {
            $includeCmp = $defaultCmp;
        }
        $consentOption = $includeCmp ? 'builtin' : 'external';

        return $this->renderDashboardPage('websites/index.html.twig', [
            'websites' => array_map(function (array $website) use ($scripts, $format, $withTags, $consentOption, $activityService): array {
                try {
                    $website['domain_settings'] = $this->websiteDomainPolicy->resolve($website);
                    $website['domain_settings_invalid'] = false;
                } catch (\InvalidArgumentException) {
                    $website['domain_settings'] = ['mode' => 'restricted', 'domains' => []];
                    $website['domain_settings_invalid'] = true;
                }
                $website['integration_code'] = null;
                $website['tracker_url'] = null;
                try {
                    $website['integration_code'] = $scripts->snippet($website['token'], $withTags, $format, $consentOption);
                    if ($withTags) {
                        $website['tracker_url'] = $scripts->trackerUrl($website['token']);
                    }
                } catch (\Throwable) {
                    // A broken site's script settings must not hide other sites
                    // or prevent the operator from repairing its domain rules.
                    $website['integration_code'] = null;
                }
                // Only a status already computed: the page never waits on the
                // events database. The page loads fresh ones from app_website_activity.
                $website['activity'] = $activityService?->cachedStatusForToken((string) ($website['token'] ?? ''));

                return $website;
            }, $this->websiteManager->getWebsites()),
            'snippet_format' => $format,
            'include_tags' => $withTags,
            'include_cmp' => $includeCmp,
            'js_namespace' => $this->config->getWithEnvFallback('js_namespace', 'Aggregate'),
            'tracking_attributes' => TrackingAttributes::names($this->config->getWithEnvFallback('js_namespace', 'Aggregate')),
        ]);
    }

    /**
     * Each website's data reception status, which the Websites page loads after
     * it renders. The badge and banner are rendered here from the same Twig
     * partials the page uses, so the browser only swaps them in.
     */
    #[Route('/dashboard/websites/activity', name: 'app_website_activity', methods: ['GET'])]
    public function websiteActivity(WebsiteActivityService $activityService): JsonResponse
    {
        $this->denyIfDashboardDisabled();
        $headers = ['Cache-Control' => 'private, no-store, max-age=0'];

        try {
            $statuses = $activityService->getStatuses();
        } catch (\Throwable $error) {
            $this->logger->warning('Website activity could not be read from the events table.', ['exception' => $error]);

            return new JsonResponse(['error' => 'Data reception status is unavailable.'], Response::HTTP_SERVICE_UNAVAILABLE, $headers);
        }

        $websites = [];
        foreach ($this->websiteManager->getWebsites() as $website) {
            $token = (string) ($website['token'] ?? '');
            if ($token === '') {
                continue;
            }
            $activity = $statuses[$token] ?? $activityService->getStatusForToken($token);
            $websites[$token] = [
                'status' => $activity['status'],
                'badge' => $this->renderView('components/Websites/_activity_badge.html.twig', ['activity' => $activity, 'token' => $token]),
                'banner' => $this->renderView('components/Websites/_activity_banner.html.twig', ['activity' => $activity, 'token' => $token, 'website' => $website]),
            ];
        }

        return new JsonResponse(['websites' => $websites], Response::HTTP_OK, $headers);
    }

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

    #[Route('/dashboard/branding', name: 'app_branding_settings', methods: ['GET'])]
    public function brandingSettings(): Response
    {
        $this->denyIfDashboardDisabled();
        $this->denyIfNotAdmin();

        return $this->renderDashboardPage('settings/branding.html.twig');
    }

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

        return $this->renderDashboardPage('settings/collection.html.twig', [
            'collection_profile' => $collectionProfile->name(),
            'collection_profile_env_override' => $collectionProfile->hasEnvironmentOverride(),
            'tracker_omit_unused_features' => (new TrackerBuilds($this->config))->enabled(),
            'anonymous_tracking_enabled' => $this->config->getBoolWithEnvFallback('anonymous_tracking_enabled', true),
            'anonymous_excluded_paths' => array_values(array_filter($excludedPaths, 'is_string')),
            'anonymous_geo_enabled' => $this->config->getBoolWithEnvFallback('anonymous_geo_enabled', false),
            'anonymous_geo_level' => $geoLevel,
            'anonymous_geo_database_path' => is_string($geoDatabasePath) ? trim($geoDatabasePath) : '',
        ]);
    }

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

    #[Route('/dashboard/users', name: 'app_users', methods: ['GET'])]
    public function users(): Response
    {
        $this->denyIfDashboardDisabled();
        $this->denyIfNotAdmin();

        return $this->renderDashboardPage('users/index.html.twig', [
            'users' => $this->userRepository->findBy([], ['createdAt' => 'ASC']),
        ]);
    }

    /** @param array<string, mixed> $parameters */
    private function renderDashboardPage(string $template, array $parameters = []): Response
    {
        return $this->render($template, $parameters, new Response(headers: [
            'Cache-Control' => 'private, no-store, max-age=0',
        ]));
    }

    #[Route('/dashboard/website/create', name: 'app_website_create', methods: ['POST'])]
    public function createWebsite(Request $request): Response
    {
        $this->denyIfDashboardDisabled();

        if (!$this->isCsrfTokenValid('create_website', (string) $request->request->get('_csrf_token', ''))) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('app_dashboard');
        }

        $values = $request->request->all();
        $name = is_string($values['name'] ?? null) ? trim($values['name']) : '';
        $domain = is_string($values['domain'] ?? null) ? trim($values['domain']) : '';

        // Validate
        if (empty($name)) {
            $this->addFlash('error', 'Website name is required');
            return $this->redirectToRoute('app_dashboard');
        }

        if (empty($domain)) {
            $this->addFlash('error', 'Domain is required');
            return $this->redirectToRoute('app_dashboard');
        }

        try {
            $domain = $this->websiteDomainPolicy->normalizePrimaryDomain($domain);
            $policy = $this->submittedDomainPolicy($request, $domain);
            $success = $this->websiteManager->addWebsite($name, $domain, domainPolicy: $policy);

            if ($success) {
                $this->addFlash('success', "Website '{$name}' created successfully!");
            } else {
                $this->addFlash('error', 'Failed to save to config/websites.yaml');
            }
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to create website: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_dashboard');
    }

    #[Route('/dashboard/website/{token}/domains', name: 'app_website_domains', methods: ['POST'])]
    public function saveWebsiteDomains(string $token, Request $request): Response
    {
        $this->denyIfDashboardDisabled();

        if (!$this->isCsrfTokenValid('website_domains_'.$token, (string) $request->request->get('_csrf_token', ''))) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('app_dashboard');
        }

        try {
            $policy = $this->submittedDomainPolicy($request);
            if ($policy === null) {
                throw new \InvalidArgumentException('Choose a domain restriction mode.');
            }
            if ($this->websiteManager->updateDomainPolicy($token, $policy)) {
                $this->addFlash('success', 'Website domain rules saved. The existing website token is unchanged.');
            } else {
                $this->addFlash('error', 'Website not found or config/websites.yaml could not be saved.');
            }
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (\Exception) {
            $this->addFlash('error', 'Could not save website domain rules. Check config/websites.yaml and its permissions.');
        }

        return $this->redirectToRoute('app_dashboard');
    }

    private function submittedDomainPolicy(Request $request, ?string $newWebsiteDomain = null): ?array
    {
        $values = $request->request->all();
        // Preserve old clients that create registrations without the new fields.
        if (!array_key_exists('domain_mode', $values) && !array_key_exists('allowed_domains', $values)) {
            return null;
        }
        $mode = $values['domain_mode'] ?? null;
        $text = $values['allowed_domains'] ?? '';
        if (!is_string($mode) || !is_string($text) || strlen($text) > 16_384) {
            throw new \InvalidArgumentException('Choose a domain restriction mode and enter at most 32 domains, one per line.');
        }
        $domains = array_values(array_filter(
            array_map('trim', preg_split('/\r\n|\r|\n/', $text) ?: []),
            static fn (string $domain): bool => $domain !== '',
        ));
        if ($mode === 'restricted' && $domains === [] && $newWebsiteDomain !== null) {
            $domains = [$newWebsiteDomain];
        }

        return $this->websiteDomainPolicy->normalize(['mode' => $mode, 'domains' => $domains]);
    }

    #[Route('/dashboard/website/delete/{token}', name: 'app_website_delete', methods: ['POST'])]
    public function deleteWebsite(string $token, Request $request): Response
    {
        $this->denyIfDashboardDisabled();

        if (!$this->isCsrfTokenValid('delete_website_'.$token, (string) $request->request->get('_csrf_token', ''))) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('app_dashboard');
        }

        try {
            $success = $this->websiteManager->removeWebsite($token);

            if ($success) {
                $this->addFlash('success', 'Website deleted successfully!');
            } else {
                $this->addFlash('error', 'Website not found or could not be deleted.');
            }
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to delete website: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_dashboard');
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

    #[Route('/dashboard/settings/branding', name: 'app_branding_settings_save', methods: ['POST'])]
    public function saveBrandingSettings(Request $request): Response
    {
        $this->denyIfDashboardDisabled();
        $this->denyIfNotAdmin();

        $csrfToken = (string) $request->request->get('_csrf_token', '');
        if (!$this->isCsrfTokenValid('branding_settings', $csrfToken)) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('app_branding_settings');
        }

        $brandName = trim((string) $request->request->get('brand_name', ''));
        $logoText = trim((string) $request->request->get('brand_logo_text', ''));
        $removeLogo = $request->request->getBoolean('remove_brand_logo');
        $uploadedLogo = $request->files->get('brand_logo');
        $brandNameOverridden = $this->config->hasEnvironmentOverride('brand_name', allowEmpty: true);
        $logoTextOverridden = $this->config->hasEnvironmentOverride('brand_logo_text', allowEmpty: true);
        $brandNameChanged = !$brandNameOverridden
            && $this->submittedBrandingValueChanged($request, 'brand_name', $brandName);
        $logoTextChanged = !$logoTextOverridden
            && $this->submittedBrandingValueChanged($request, 'brand_logo_text', $logoText);
        $themeSettings = [];

        if (!$brandNameOverridden && $brandName === '') {
            $this->addFlash('error', 'Brand name is required.');
            return $this->redirectToRoute('app_branding_settings');
        }

        if (!$brandNameOverridden && (preg_match('//u', $brandName) !== 1
            || mb_strlen($brandName) > 100
            || preg_match('/[\x00-\x1F\x7F]/u', $brandName) === 1)) {
            $this->addFlash('error', 'Brand name must be at most 100 characters and cannot contain control characters.');
            return $this->redirectToRoute('app_branding_settings');
        }

        if (!$logoTextOverridden && (preg_match('//u', $logoText) !== 1
            || mb_strlen($logoText) > 100
            || preg_match('/[\x00-\x1F\x7F]/u', $logoText) === 1)) {
            $this->addFlash('error', 'Logo text must be at most 100 characters and cannot contain control characters.');
            return $this->redirectToRoute('app_branding_settings');
        }

        $themeColors = [
            'brand_primary_color' => 'Primary color',
            'brand_accent_color' => 'Accent color',
            'brand_navbar_color' => 'Navigation color',
            'brand_background_color' => 'Page background color',
            'brand_surface_color' => 'Surface color',
            'brand_text_color' => 'Text color',
        ];
        foreach ($themeColors as $key => $label) {
            if (!$request->request->has($key)
                || $this->config->hasEnvironmentOverride($key, allowEmpty: true)) {
                continue;
            }

            $color = BrandingTheme::normalizeHexColor($request->request->get($key));
            if ($color === null) {
                $this->addFlash('error', $label.' must use #RGB or #RRGGBB hexadecimal notation.');
                return $this->redirectToRoute('app_branding_settings');
            }

            $originalColor = BrandingTheme::normalizeHexColor(
                $request->request->get('_original_'.$key),
            );
            if ($request->request->has('_original_'.$key) && $color === $originalColor) {
                continue;
            }
            $themeSettings[$key] = $color;
        }

        $themeFonts = [
            'brand_font_family' => 'Interface font stack',
            'brand_heading_font_family' => 'Heading font stack',
        ];
        foreach ($themeFonts as $key => $label) {
            if (!$request->request->has($key)
                || $this->config->hasEnvironmentOverride($key, allowEmpty: true)) {
                continue;
            }

            $font = BrandingTheme::normalizeFontFamily($request->request->get($key));
            if ($font === null) {
                $this->addFlash('error', $label.' must contain one to eight comma-separated local/system font family names using only letters, numbers, spaces, underscores, or hyphens.');
                return $this->redirectToRoute('app_branding_settings');
            }

            $originalFont = BrandingTheme::normalizeFontFamily(
                $request->request->get('_original_'.$key),
            );
            if ($request->request->has('_original_'.$key)
                && $originalFont !== null
                && $font['value'] === $originalFont['value']) {
                continue;
            }
            $themeSettings[$key] = $font['value'];
        }

        $contrastKeys = [
            'brand_background_color' => 'background_color',
            'brand_surface_color' => 'surface_color',
            'brand_text_color' => 'text_color',
        ];
        if (array_intersect_key($themeSettings, $contrastKeys) !== []) {
            $currentTheme = (new BrandingTheme($this->config))->getConfiguredContrastColors();
            $proposedContrastColors = [];
            foreach ($contrastKeys as $configKey => $themeKey) {
                $proposedContrastColors[$themeKey] = $themeSettings[$configKey] ?? $currentTheme[$themeKey];
            }
            if (!BrandingTheme::hasReadableTextContrast(
                $proposedContrastColors['text_color'],
                $proposedContrastColors['background_color'],
                $proposedContrastColors['surface_color'],
            )) {
                $this->addFlash('error', 'Text color must have at least 4.5:1 contrast against both the page background and surface colors.');
                return $this->redirectToRoute('app_branding_settings');
            }
        }

        if ($uploadedLogo !== null && !$uploadedLogo instanceof UploadedFile) {
            $this->addFlash('error', 'The submitted logo upload is invalid.');
            return $this->redirectToRoute('app_branding_settings');
        }

        if ($uploadedLogo instanceof UploadedFile && $removeLogo) {
            $this->addFlash('error', 'Choose either a new logo or remove the current logo, not both.');
            return $this->redirectToRoute('app_branding_settings');
        }

        $hasLogoMutation = $uploadedLogo instanceof UploadedFile || $removeLogo;
        if ($hasLogoMutation && $this->config->hasEnvironmentOverride('brand_logo_path', allowEmpty: true)) {
            $this->addFlash('error', 'The logo path is controlled by BRAND_LOGO_PATH. Change or remove that environment override before using logo upload controls.');
            return $this->redirectToRoute('app_branding_settings');
        }

        $oldLogoPath = '';
        $newLogoPath = '';
        if ($hasLogoMutation) {
            $configuredPath = $this->config->get('brand_logo_path', '');
            $oldLogoPath = is_string($configuredPath) ? trim($configuredPath) : '';
            $newLogoPath = $removeLogo ? '' : $oldLogoPath;
        }
        $storedLogoPath = null;

        if ($uploadedLogo instanceof UploadedFile) {
            try {
                $storedLogoPath = $this->brandingLogoManager->store($uploadedLogo);
                $newLogoPath = $storedLogoPath;
            } catch (\InvalidArgumentException $e) {
                $this->addFlash('error', $e->getMessage());
                return $this->redirectToRoute('app_branding_settings');
            } catch (\Throwable $e) {
                $this->logger->error('Failed to store a branding logo.', ['exception' => $e]);
                $this->addFlash('error', 'The logo could not be stored. Check the application logs and var/branding permissions.');
                return $this->redirectToRoute('app_branding_settings');
            }
        }

        try {
            $settings = $themeSettings;
            if ($brandNameChanged) {
                $settings['brand_name'] = $brandName;
            }
            if ($logoTextChanged) {
                $settings['brand_logo_text'] = $logoText;
            }
            if ($hasLogoMutation) {
                $settings['brand_logo_path'] = $newLogoPath;
            }
            if ($settings === []) {
                $this->addFlash('warning', 'No branding changes were submitted; environment-controlled values were left unchanged.');
                return $this->redirectToRoute('app_branding_settings');
            }
            $this->config->setMany($settings);
        } catch (\Throwable $e) {
            if ($storedLogoPath !== null) {
                try {
                    $this->brandingLogoManager->removeManaged($storedLogoPath);
                } catch (\Throwable $cleanupError) {
                    $this->logger->warning('Failed to remove an uncommitted branding logo.', ['exception' => $cleanupError]);
                }
            }

            $this->logger->error('Failed to save branding settings.', ['exception' => $e]);
            $this->addFlash('error', 'Branding settings could not be saved. Check the application logs.');
            return $this->redirectToRoute('app_branding_settings');
        }

        if ($hasLogoMutation && $oldLogoPath !== '' && $oldLogoPath !== $newLogoPath) {
            try {
                $this->brandingLogoManager->removeManaged($oldLogoPath);
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to remove the previous managed branding logo.', ['exception' => $e]);
                $this->addFlash('warning', 'Branding was saved, but the previous managed logo could not be removed.');
            }
        }

        $this->addFlash('success', 'Branding updated successfully.');

        return $this->redirectToRoute('app_branding_settings');
    }

    private function submittedBrandingValueChanged(Request $request, string $key, string $value): bool
    {
        $originalKey = '_original_'.$key;
        if (!$request->request->has($originalKey)) {
            return true;
        }

        return trim((string) $request->request->get($originalKey)) !== $value;
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

        $enabled = $request->request->getBoolean('anonymous_tracking_enabled');
        $rawPaths = (string) $request->request->get('anonymous_excluded_paths', '');
        $geoEnabled = $request->request->getBoolean('anonymous_geo_enabled');
        $geoLevel = trim((string) $request->request->get('anonymous_geo_level', 'macro_region'));
        $geoDatabasePath = trim((string) $request->request->get('anonymous_geo_database_path', ''));
        $collectionProfile = CollectionProfile::normalize($request->request->get(CollectionProfile::KEY, CollectionProfile::STANDARD));

        if ($collectionProfile === null) {
            $this->addFlash('error', 'Collection profile must be standard or strict.');
            return $this->redirectToRoute('app_collection_settings');
        }

        if (!in_array($geoLevel, ['macro_region', 'country'], true)) {
            $this->addFlash('error', 'Geography level must be macro-region or country.');
            return $this->redirectToRoute('app_collection_settings');
        }

        if (strlen($geoDatabasePath) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $geoDatabasePath) === 1) {
            $this->addFlash('error', 'The GeoIP database path is invalid or too long.');
            return $this->redirectToRoute('app_collection_settings');
        }

        if ($geoDatabasePath !== '') {
            $isWindowsDrivePath = preg_match('/^[A-Za-z]:[\\\\\/]/D', $geoDatabasePath) === 1;
            $isAbsolutePath = str_starts_with($geoDatabasePath, '/')
                || $isWindowsDrivePath;
            $pathSegments = preg_split('#[\\\\/]#', $geoDatabasePath) ?: [];
            if ((!$isWindowsDrivePath && preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/D', $geoDatabasePath) === 1)
                || str_contains($geoDatabasePath, '://')
                || str_starts_with($geoDatabasePath, '\\\\')
                || str_starts_with($geoDatabasePath, '//')
                || !str_ends_with(strtolower($geoDatabasePath), '.mmdb')
                || (!$isAbsolutePath && in_array('..', $pathSegments, true))) {
                $this->addFlash('error', 'Use a local filesystem path ending in .mmdb; URI, stream-wrapper, UNC/network, and project-root escape paths are not allowed.');
                return $this->redirectToRoute('app_collection_settings');
            }
        }

        if (strlen($rawPaths) > 25_600) {
            $this->addFlash('error', 'Excluded paths are too long.');
            return $this->redirectToRoute('app_collection_settings');
        }

        $paths = preg_split('/\R/', $rawPaths) ?: [];
        $paths = array_values(array_unique(array_filter(array_map('trim', $paths), static fn (string $path): bool => $path !== '')));

        if (count($paths) > 100) {
            $this->addFlash('error', 'Use no more than 100 anonymous tracking exclusions.');
            return $this->redirectToRoute('app_collection_settings');
        }

        foreach ($paths as $path) {
            if (strlen($path) > 512 || !str_starts_with($path, '/') || str_contains($path, '?') || str_contains($path, '#')) {
                $this->addFlash('error', sprintf('Invalid excluded path "%s". Use a path beginning with /, omit query strings/fragments, and keep it at most 512 characters.', $path));
                return $this->redirectToRoute('app_collection_settings');
            }
        }

        try {
            $this->config->setMany([
                CollectionProfile::KEY => $collectionProfile,
                'anonymous_tracking_enabled' => $enabled,
                'anonymous_excluded_paths' => $paths,
                'anonymous_geo_enabled' => $geoEnabled,
                'anonymous_geo_level' => $geoLevel,
                'anonymous_geo_database_path' => $geoDatabasePath,
            ]);
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
            $this->analyticsPrivacySettings->saveMinimumCellCounts(
                $minimumCellCount,
                $geoMinimumCellCount,
            );
            $this->addFlash('success', 'BI disclosure thresholds updated successfully.');
        } catch (\Throwable $e) {
            $this->logger->error('Failed to save BI disclosure thresholds.', ['exception' => $e]);
            $this->addFlash('error', 'Failed to save BI disclosure thresholds. Check the application logs.');
        }

        return $this->redirectToRoute('app_privacy_settings');
    }

    #[Route('/dashboard/users/create', name: 'app_user_create', methods: ['POST'])]
    public function createUser(Request $request): Response
    {
        $this->denyIfDashboardDisabled();
        $this->denyIfNotAdmin();

        $csrfToken = (string) $request->request->get('_csrf_token', '');
        if (!$this->isCsrfTokenValid('create_user', $csrfToken)) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('app_users');
        }

        $username = trim((string) $request->request->get('username', ''));
        $password = (string) $request->request->get('password', '');
        $isAdmin = $request->request->getBoolean('is_admin');

        if ($username === '' || $password === '') {
            $this->addFlash('error', 'Username and password are required.');
            return $this->redirectToRoute('app_users');
        }

        if (strlen($username) > 180) {
            $this->addFlash('error', 'Username must be 180 characters or fewer.');
            return $this->redirectToRoute('app_users');
        }

        if (strlen($password) < 8) {
            $this->addFlash('error', 'Password must be at least 8 characters.');
            return $this->redirectToRoute('app_users');
        }

        if ($this->userRepository->findOneBy(['username' => $username]) instanceof User) {
            $this->addFlash('error', sprintf('User "%s" already exists.', $username));
            return $this->redirectToRoute('app_users');
        }

        try {
            $user = new User();
            $user->setUsername($username);
            $user->setRoles($isAdmin ? ['ROLE_ADMIN', 'ROLE_USER'] : ['ROLE_USER']);
            $user->setPassword($this->passwordHasher->hashPassword($user, $password));

            $this->em->persist($user);
            $this->em->flush();

            $this->addFlash('success', sprintf('User "%s" created successfully.', $username));
        } catch (UniqueConstraintViolationException) {
            $this->addFlash('error', sprintf('User "%s" already exists.', $username));
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to create user: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_users');
    }

    #[Route('/dashboard/users/{id}/password', name: 'app_user_password_update', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function updateUserPassword(Request $request, int $id): Response
    {
        $this->denyIfDashboardDisabled();
        $this->denyIfNotAdmin();

        $csrfToken = (string) $request->request->get('_csrf_token', '');
        if (!$this->isCsrfTokenValid('update_user_password_' . $id, $csrfToken)) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('app_users');
        }

        $newPassword = (string) $request->request->get('new_password', '');
        if ($newPassword === '') {
            $this->addFlash('error', 'New password is required.');
            return $this->redirectToRoute('app_users');
        }

        if (strlen($newPassword) < 8) {
            $this->addFlash('error', 'New password must be at least 8 characters.');
            return $this->redirectToRoute('app_users');
        }

        $user = $this->userRepository->find($id);
        if (!$user instanceof User) {
            $this->addFlash('error', 'User not found.');
            return $this->redirectToRoute('app_users');
        }

        try {
            $user->setPassword($this->passwordHasher->hashPassword($user, $newPassword));
            $this->em->flush();

            $this->addFlash('success', sprintf('Password updated for "%s".', $user->getUsername()));
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to update password: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_users');
    }

    private function denyIfDashboardDisabled(): void
    {
        if (!$this->config->isDashboardEnabled()) {
            throw $this->createNotFoundException('Dashboard is disabled.');
        }
    }

    private function denyIfNotAdmin(): void
    {
        if (!$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException('Only administrators can perform this action.');
        }
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
