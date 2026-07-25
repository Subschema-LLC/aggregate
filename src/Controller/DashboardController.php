<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\AggregateConfigLoader;
use App\Service\AnalyticsPrivacySettings;
use App\Service\WebsiteConfigManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
    ) {}

    #[Route('/dashboard', name: 'app_dashboard')]
    public function index(): Response
    {
        $this->denyIfDashboardDisabled();

        $websites = $this->websiteManager->getWebsites();
        $appHost = $this->config->getWithEnvFallback('app_host', 'http://localhost:8000');
        $jsNamespace = $this->config->getWithEnvFallback('js_namespace', 'Aggregate');
        $rateLimit = $this->config->getWithEnvFallback('rate_limit_per_minute', 100);
        $anonymousTrackingEnabled = $this->config->getBoolWithEnvFallback('anonymous_tracking_enabled', true);
        $anonymousExcludedPaths = $this->config->getWithEnvFallback('anonymous_excluded_paths', []);
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
        $anonymousGeoEnabled = $this->config->getBoolWithEnvFallback('anonymous_geo_enabled', false);
        $anonymousGeoLevel = strtolower(trim((string) $this->config->getWithEnvFallback('anonymous_geo_level', 'macro_region')));
        if (!in_array($anonymousGeoLevel, ['macro_region', 'country'], true)) {
            $anonymousGeoLevel = 'macro_region';
        }
        $anonymousGeoDatabasePath = $this->config->getWithEnvFallback('anonymous_geo_database_path', '');
        if (!is_string($anonymousGeoDatabasePath)) {
            $anonymousGeoDatabasePath = '';
        } else {
            $anonymousGeoDatabasePath = trim($anonymousGeoDatabasePath);
        }
        if (is_string($anonymousExcludedPaths)) {
            $anonymousExcludedPaths = preg_split('/[\r\n,]+/', $anonymousExcludedPaths) ?: [];
        }
        if (!is_array($anonymousExcludedPaths)) {
            $anonymousExcludedPaths = [];
        }
        $users = $this->isGranted('ROLE_ADMIN') ? $this->userRepository->findBy([], ['createdAt' => 'ASC']) : [];

        return $this->render('dashboard/index.html.twig', [
            'websites' => $websites,
            'app_host' => $appHost,
            'js_namespace' => $jsNamespace,
            'rate_limit' => $rateLimit,
            'anonymous_tracking_enabled' => $anonymousTrackingEnabled,
            'anonymous_excluded_paths' => array_values(array_filter(array_map('strval', $anonymousExcludedPaths))),
            'anonymous_min_cell_count' => $minimumCellCounts['anonymous'],
            'anonymous_geo_enabled' => $anonymousGeoEnabled,
            'anonymous_geo_level' => $anonymousGeoLevel,
            'anonymous_geo_database_path' => $anonymousGeoDatabasePath,
            'anonymous_geo_min_cell_count' => $minimumCellCounts['geo'],
            'analytics_privacy_settings_error' => $privacySettingsError,
            'users' => $users,
        ]);
    }

    #[Route('/dashboard/website/create', name: 'app_website_create', methods: ['POST'])]
    public function createWebsite(Request $request): Response
    {
        $this->denyIfDashboardDisabled();

        if (!$this->isCsrfTokenValid('create_website', (string) $request->request->get('_csrf_token', ''))) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('app_dashboard');
        }

        $name = trim($request->request->get('name', ''));
        $domain = trim($request->request->get('domain', ''));

        // Validate
        if (empty($name)) {
            $this->addFlash('error', 'Website name is required');
            return $this->redirectToRoute('app_dashboard');
        }

        if (empty($domain)) {
            $this->addFlash('error', 'Domain is required');
            return $this->redirectToRoute('app_dashboard');
        }

        // Normalize domain (remove protocol, trailing slash)
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = rtrim($domain, '/');

        try {
            $success = $this->websiteManager->addWebsite($name, $domain);

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
    public function saveSettings(Request $request): Response
    {
        $this->denyIfDashboardDisabled();

        if (!$this->isCsrfTokenValid('app_settings', (string) $request->request->get('_csrf_token', ''))) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('app_dashboard');
        }

        $appHost = trim($request->request->get('app_host', ''));
        $jsNamespace = trim($request->request->get('js_namespace', 'Aggregate'));
        $rateLimit = (int) $request->request->get('rate_limit', 100);

        if (empty($appHost)) {
            $this->addFlash('error', 'App Host is required.');
            return $this->redirectToRoute('app_dashboard');
        }

        try {
            $this->config->set('app_host', $appHost);
            $this->config->set('js_namespace', $jsNamespace);
            $this->config->set('rate_limit_per_minute', $rateLimit);

            $this->addFlash('success', 'Settings updated successfully in config/aggregate.yaml!');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to save settings: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_dashboard');
    }

    #[Route('/dashboard/settings/anonymous', name: 'app_anonymous_settings_save', methods: ['POST'])]
    public function saveAnonymousSettings(Request $request): Response
    {
        $this->denyIfDashboardDisabled();
        $this->denyIfNotAdmin();

        $csrfToken = (string) $request->request->get('_csrf_token', '');
        if (!$this->isCsrfTokenValid('anonymous_settings', $csrfToken)) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('app_dashboard');
        }

        $enabled = $request->request->getBoolean('anonymous_tracking_enabled');
        $rawPaths = (string) $request->request->get('anonymous_excluded_paths', '');
        $geoEnabled = $request->request->getBoolean('anonymous_geo_enabled');
        $geoLevel = trim((string) $request->request->get('anonymous_geo_level', 'macro_region'));
        $geoDatabasePath = trim((string) $request->request->get('anonymous_geo_database_path', ''));

        if (!in_array($geoLevel, ['macro_region', 'country'], true)) {
            $this->addFlash('error', 'Geography level must be macro-region or country.');
            return $this->redirectToRoute('app_dashboard');
        }

        if (strlen($geoDatabasePath) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $geoDatabasePath) === 1) {
            $this->addFlash('error', 'The GeoIP database path is invalid or too long.');
            return $this->redirectToRoute('app_dashboard');
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
                return $this->redirectToRoute('app_dashboard');
            }
        }

        if (strlen($rawPaths) > 25_600) {
            $this->addFlash('error', 'Excluded paths are too long.');
            return $this->redirectToRoute('app_dashboard');
        }

        $paths = preg_split('/\R/', $rawPaths) ?: [];
        $paths = array_values(array_unique(array_filter(array_map('trim', $paths), static fn (string $path): bool => $path !== '')));

        if (count($paths) > 100) {
            $this->addFlash('error', 'Use no more than 100 anonymous tracking exclusions.');
            return $this->redirectToRoute('app_dashboard');
        }

        foreach ($paths as $path) {
            if (strlen($path) > 512 || !str_starts_with($path, '/') || str_contains($path, '?') || str_contains($path, '#')) {
                $this->addFlash('error', sprintf('Invalid excluded path "%s". Use a path beginning with /, omit query strings/fragments, and keep it at most 512 characters.', $path));
                return $this->redirectToRoute('app_dashboard');
            }
        }

        try {
            $this->config->set('anonymous_tracking_enabled', $enabled);
            $this->config->set('anonymous_excluded_paths', $paths);
            $this->config->set('anonymous_geo_enabled', $geoEnabled);
            $this->config->set('anonymous_geo_level', $geoLevel);
            $this->config->set('anonymous_geo_database_path', $geoDatabasePath);
            $effectiveGeoEnabled = $this->config->getBoolWithEnvFallback('anonymous_geo_enabled', false);
            $effectiveGeoPath = $this->config->getWithEnvFallback('anonymous_geo_database_path', '');
            if ($effectiveGeoEnabled && (!is_string($effectiveGeoPath) || trim($effectiveGeoPath) === '')) {
                $this->addFlash('warning', 'Coarse geography is enabled without a local GeoIP database path. Events will continue with no geography until one is configured.');
            }
            $this->addFlash('success', 'Analytics collection settings updated successfully.');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to save analytics privacy settings: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_dashboard');
    }

    #[Route('/dashboard/settings/analytics-privacy', name: 'app_analytics_privacy_settings_save', methods: ['POST'])]
    public function saveAnalyticsPrivacySettings(Request $request): Response
    {
        $this->denyIfDashboardDisabled();
        $this->denyIfNotAdmin();

        $csrfToken = (string) $request->request->get('_csrf_token', '');
        if (!$this->isCsrfTokenValid('analytics_privacy_settings', $csrfToken)) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('app_dashboard');
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
            return $this->redirectToRoute('app_dashboard');
        }

        if ($minimumCellCount < AnalyticsPrivacySettings::MINIMUM_CELL_COUNT
            || $minimumCellCount > AnalyticsPrivacySettings::MAXIMUM_CELL_COUNT) {
            $this->addFlash('error', 'Minimum BI cell count must be between 2 and 1000.');
            return $this->redirectToRoute('app_dashboard');
        }

        if ($geoMinimumCellCount < AnalyticsPrivacySettings::GEO_MINIMUM_CELL_COUNT
            || $geoMinimumCellCount > AnalyticsPrivacySettings::MAXIMUM_CELL_COUNT) {
            $this->addFlash('error', 'Geography minimum BI cell count must be between 10 and 1000.');
            return $this->redirectToRoute('app_dashboard');
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

        return $this->redirectToRoute('app_dashboard');
    }

    #[Route('/dashboard/users/create', name: 'app_user_create', methods: ['POST'])]
    public function createUser(Request $request): Response
    {
        $this->denyIfDashboardDisabled();
        $this->denyIfNotAdmin();

        $csrfToken = (string) $request->request->get('_csrf_token', '');
        if (!$this->isCsrfTokenValid('create_user', $csrfToken)) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('app_dashboard');
        }

        $username = trim((string) $request->request->get('username', ''));
        $password = (string) $request->request->get('password', '');
        $isAdmin = $request->request->getBoolean('is_admin');

        if ($username === '' || $password === '') {
            $this->addFlash('error', 'Username and password are required.');
            return $this->redirectToRoute('app_dashboard');
        }

        if (strlen($username) > 180) {
            $this->addFlash('error', 'Username must be 180 characters or fewer.');
            return $this->redirectToRoute('app_dashboard');
        }

        if (strlen($password) < 8) {
            $this->addFlash('error', 'Password must be at least 8 characters.');
            return $this->redirectToRoute('app_dashboard');
        }

        if ($this->userRepository->findOneBy(['username' => $username]) instanceof User) {
            $this->addFlash('error', sprintf('User "%s" already exists.', $username));
            return $this->redirectToRoute('app_dashboard');
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

        return $this->redirectToRoute('app_dashboard');
    }

    #[Route('/dashboard/users/{id}/password', name: 'app_user_password_update', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function updateUserPassword(Request $request, int $id): Response
    {
        $this->denyIfDashboardDisabled();
        $this->denyIfNotAdmin();

        $csrfToken = (string) $request->request->get('_csrf_token', '');
        if (!$this->isCsrfTokenValid('update_user_password_' . $id, $csrfToken)) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('app_dashboard');
        }

        $newPassword = (string) $request->request->get('new_password', '');
        if ($newPassword === '') {
            $this->addFlash('error', 'New password is required.');
            return $this->redirectToRoute('app_dashboard');
        }

        if (strlen($newPassword) < 8) {
            $this->addFlash('error', 'New password must be at least 8 characters.');
            return $this->redirectToRoute('app_dashboard');
        }

        $user = $this->userRepository->find($id);
        if (!$user instanceof User) {
            $this->addFlash('error', 'User not found.');
            return $this->redirectToRoute('app_dashboard');
        }

        try {
            $user->setPassword($this->passwordHasher->hashPassword($user, $newPassword));
            $this->em->flush();

            $this->addFlash('success', sprintf('Password updated for "%s".', $user->getUsername()));
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to update password: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_dashboard');
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
