<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AggregateConfigLoader;
use App\Service\ApplicationUpdateService;
use App\Service\FeatureFlags;
use App\Service\Update\ApplicationUpdater;
use App\Service\Update\SystemCheck;
use App\Service\UpdateSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class UpdatesController extends AbstractController
{
    public const CSRF_TOKEN_ID = 'application_updates_refresh';
    public const INSTALL_CSRF_TOKEN_ID = 'application_updates_install';
    public const SETTINGS_CSRF_TOKEN_ID = 'application_updates_settings';

    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly ApplicationUpdateService $updates,
        private readonly FeatureFlags $features,
        private readonly ApplicationUpdater $updater,
        private readonly SystemCheck $systemCheck,
        private readonly UpdateSettings $settings,
    ) {
    }

    #[Route('/dashboard/updates', name: 'app_updates', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyUnlessAvailableToAdmin();

        $status = $this->updates->check();
        $checks = $this->systemCheck->run();

        return $this->render('updates/index.html.twig', [
            'update_status' => $status,
            'update_repository' => $status['repository'] ?? ApplicationUpdateService::REPOSITORY,
            'update_repository_url' => $status['repository_url'] ?? ApplicationUpdateService::REPOSITORY_URL,
            'update_official_repository' => strcasecmp((string) ($status['repository'] ?? ApplicationUpdateService::REPOSITORY), UpdateSettings::DEFAULT_REPOSITORY) === 0,
            'update_run' => $this->updater->status(),
            'update_database_sqlite' => $this->updater->isSqlite(),
            'update_checks' => $checks,
            'update_blocking_problems' => $this->systemCheck->problems(dashboard: true, checks: $checks),
            'update_source_setting' => $status['source_setting'] ?? 'auto',
        ]);
    }

    #[Route('/dashboard/updates/refresh', name: 'app_updates_refresh', methods: ['POST'])]
    public function refresh(Request $request): Response
    {
        $this->denyUnlessAvailableToAdmin();

        $submitted = $request->request->all();
        $csrfToken = $submitted['_csrf_token'] ?? null;
        if (!is_string($csrfToken) || !$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $csrfToken)) {
            $this->addFlash('error', 'Invalid security token. Please try again.');

            return $this->redirectToRoute('app_updates');
        }

        $this->updates->check(true);

        return $this->redirectToRoute('app_updates');
    }

    /**
     * Start the same app:updates:apply command the CLI uses, in a background
     * process. Only an available official update can be installed this way.
     */
    #[Route('/dashboard/updates/install', name: 'app_updates_install', methods: ['POST'])]
    public function install(Request $request): Response
    {
        $this->denyUnlessAvailableToAdmin();

        $submitted = $request->request->all();
        $csrfToken = $submitted['_csrf_token'] ?? null;
        if (!is_string($csrfToken) || !$this->isCsrfTokenValid(self::INSTALL_CSRF_TOKEN_ID, $csrfToken)) {
            $this->addFlash('error', 'Invalid security token. Please try again.');

            return $this->redirectToRoute('app_updates');
        }

        $status = $this->updates->check(true);
        if (($status['state'] ?? null) !== 'available') {
            $this->addFlash('error', 'No installable update is available. '.($status['message'] ?? ''));

            return $this->redirectToRoute('app_updates');
        }
        $confirmed = ($submitted['database_backup_confirmed'] ?? null) === '1';
        if (!$this->updater->isSqlite() && !$confirmed) {
            $this->addFlash('error', 'Confirm that you have a current database backup before installing the update.');

            return $this->redirectToRoute('app_updates');
        }

        $problems = $this->systemCheck->problems(dashboard: true);
        if ($problems === []) {
            $problems = $this->updater->backgroundProblems();
        }
        if ($problems !== []) {
            $this->addFlash('error', 'The update cannot start from the dashboard: '.implode(' ', $problems).' You can run php bin/console app:updates:apply on the server instead.');

            return $this->redirectToRoute('app_updates');
        }

        $arguments = [];
        if ($confirmed) {
            $arguments[] = '--database-backup-confirmed';
        }
        if (($status['installation_type'] ?? null) === 'release' && is_string($status['latest_version'] ?? null)) {
            $arguments[] = '--release='.$status['latest_version'];
        }
        try {
            $this->updater->startInBackground($arguments);
        } catch (\RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_updates');
        }
        $this->addFlash('success', 'The update has started. The site shows a maintenance page while files are replaced; reload this page to follow its progress.');

        return $this->redirectToRoute('app_updates');
    }

    /**
     * Save updates_source and updates_branch to the active YAML configuration,
     * the same settings operators can edit in config/aggregate.yaml.
     */
    #[Route('/dashboard/updates/settings', name: 'app_updates_settings', methods: ['POST'])]
    public function saveSettings(Request $request): Response
    {
        $this->denyUnlessAvailableToAdmin();

        $submitted = $request->request->all();
        $csrfToken = $submitted['_csrf_token'] ?? null;
        if (!is_string($csrfToken) || !$this->isCsrfTokenValid(self::SETTINGS_CSRF_TOKEN_ID, $csrfToken)) {
            $this->addFlash('error', 'Invalid security token. Please try again.');

            return $this->redirectToRoute('app_updates');
        }
        $source = $submitted['updates_source'] ?? null;
        $branch = is_string($submitted['updates_branch'] ?? null) ? trim($submitted['updates_branch']) : null;
        try {
            $this->settings->save(UpdateSettings::validateSource($source), (string) UpdateSettings::validateBranch($branch));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_updates');
        } catch (\RuntimeException) {
            $this->addFlash('error', 'The update settings could not be saved. Check that config/aggregate.yaml is valid and writable, or edit updates_source and updates_branch there directly.');

            return $this->redirectToRoute('app_updates');
        }
        $this->updates->check(true);
        $this->addFlash('success', 'Update settings saved to the YAML configuration.');

        return $this->redirectToRoute('app_updates');
    }

    private function denyUnlessAvailableToAdmin(): void
    {
        if (!$this->config->isDashboardEnabled()) {
            throw $this->createNotFoundException('Dashboard is disabled.');
        }

        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->features->isEnabled('updates')) {
            throw $this->createNotFoundException('Updates are disabled.');
        }
    }
}
