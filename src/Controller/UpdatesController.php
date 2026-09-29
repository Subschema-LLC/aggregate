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
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class UpdatesController extends AbstractController
{
    public const CSRF_TOKEN_ID = 'application_updates_refresh';
    public const INSTALL_CSRF_TOKEN_ID = 'application_updates_install';
    public const SETTINGS_CSRF_TOKEN_ID = 'application_updates_settings';
    public const UPLOAD_CSRF_TOKEN_ID = 'application_updates_upload';
    public const METHOD_CSRF_TOKEN_ID = 'application_updates_method';

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
            'update_upload_limit' => SystemCheck::uploadLimit(),
            'update_method' => $this->chosenMethod(),
            'update_detected_method' => $this->updates->detectedMethod(),
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
        $method = $submitted['method'] ?? null;
        if ($method !== null && $method !== ($status['installation_type'] ?? null)) {
            $this->addFlash('error', 'This installation cannot use that update method. Reload the page and use the enabled option.');

            return $this->redirectToRoute('app_updates');
        }
        $arguments = [];
        if (($status['installation_type'] ?? null) === 'release' && is_string($status['latest_version'] ?? null)) {
            $arguments[] = '--release='.$status['latest_version'];
        }

        return $this->startUpdate($submitted, $arguments);
    }

    /**
     * Install a release ZIP uploaded with its manifest and signature. The files
     * are verified with the installation's trusted key before the update starts.
     */
    #[Route('/dashboard/updates/upload', name: 'app_updates_upload', methods: ['POST'])]
    public function upload(Request $request): Response
    {
        $this->denyUnlessAvailableToAdmin();

        $limit = SystemCheck::uploadLimit();
        $length = (int) $request->server->get('CONTENT_LENGTH', 0);
        if ($request->request->count() === 0 && $request->files->count() === 0 && $length > 0) {
            // PHP discards the whole request when it exceeds post_max_size.
            $this->addFlash('error', 'The upload was larger than this server accepts'.($limit > 0 && $limit < PHP_INT_MAX ? ' ('.round($limit / 1048576).' MB)' : '').'. Use Download from GitHub, or raise upload_max_filesize and post_max_size.');

            return $this->redirectToRoute('app_updates');
        }
        $submitted = $request->request->all();
        $csrfToken = $submitted['_csrf_token'] ?? null;
        if (!is_string($csrfToken) || !$this->isCsrfTokenValid(self::UPLOAD_CSRF_TOKEN_ID, $csrfToken)) {
            $this->addFlash('error', 'Invalid security token. Please try again.');

            return $this->redirectToRoute('app_updates');
        }

        $uploads = $request->files->all()['release_files'] ?? [];
        $files = [];
        foreach (is_array($uploads) ? $uploads : [$uploads] as $upload) {
            if (!$upload instanceof UploadedFile) {
                continue;
            }
            if (!$upload->isValid()) {
                $this->addFlash('error', 'The upload failed: '.$upload->getErrorMessage());

                return $this->redirectToRoute('app_updates');
            }
            $files[$upload->getClientOriginalName()] = $upload->getPathname();
        }
        if ($files === []) {
            $this->addFlash('error', 'Choose the release ZIP, aggregate-release.json and aggregate-release.json.sig to upload.');

            return $this->redirectToRoute('app_updates');
        }
        $error = $this->startError($submitted);
        if ($error !== null) {
            $this->addFlash('error', $error);

            return $this->redirectToRoute('app_updates');
        }
        try {
            $staged = $this->updater->stageUpload($files);
        } catch (\RuntimeException $e) {
            $this->addFlash('error', 'The uploaded release was not installed: '.$e->getMessage());

            return $this->redirectToRoute('app_updates');
        }

        return $this->launch($submitted, [
            '--package='.$staged['package'],
            '--manifest='.$staged['manifest'],
            '--signature='.$staged['signature'],
        ], 'Verified release '.$staged['version'].'. ');
    }

    /** @param array<string, mixed> $submitted @param list<string> $arguments */
    private function startUpdate(array $submitted, array $arguments, string $prefix = ''): Response
    {
        $error = $this->startError($submitted);
        if ($error !== null) {
            $this->addFlash('error', $error);

            return $this->redirectToRoute('app_updates');
        }

        return $this->launch($submitted, $arguments, $prefix);
    }

    /** Why the dashboard cannot start an update now, or null. @param array<string, mixed> $submitted */
    private function startError(array $submitted): ?string
    {
        if ($this->chosenMethod() === null) {
            return 'Choose an update method on this page before installing updates from the dashboard.';
        }
        if (!$this->updater->isSqlite() && ($submitted['database_backup_confirmed'] ?? null) !== '1') {
            return 'Confirm that you have a current database backup before installing the update.';
        }
        $problems = $this->systemCheck->problems(dashboard: true);
        if ($problems === []) {
            $problems = $this->updater->backgroundProblems();
        }

        return $problems === [] ? null
            : 'The update cannot start from the dashboard: '.implode(' ', $problems).' You can run php bin/console app:updates:apply on the server instead.';
    }

    /** @param array<string, mixed> $submitted @param list<string> $arguments */
    private function launch(array $submitted, array $arguments, string $prefix = ''): Response
    {
        if (($submitted['database_backup_confirmed'] ?? null) === '1') {
            array_unshift($arguments, '--database-backup-confirmed');
        }
        try {
            $this->updater->startInBackground($arguments);
        } catch (\RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_updates');
        }
        $this->addFlash('success', $prefix.'The update has started. The site shows a maintenance page while files are replaced; reload this page to follow its progress.');

        return $this->redirectToRoute('app_updates');
    }

    /**
     * Save the update method an administrator chose (updates_method) to the
     * active YAML configuration: release ZIPs, or the repository (advanced).
     * The Updates page then shows only that method.
     */
    #[Route('/dashboard/updates/method', name: 'app_updates_method', methods: ['POST'])]
    public function saveMethod(Request $request): Response
    {
        $this->denyUnlessAvailableToAdmin();

        $submitted = $request->request->all();
        $csrfToken = $submitted['_csrf_token'] ?? null;
        if (!is_string($csrfToken) || !$this->isCsrfTokenValid(self::METHOD_CSRF_TOKEN_ID, $csrfToken)) {
            $this->addFlash('error', 'Invalid security token. Please try again.');

            return $this->redirectToRoute('app_updates');
        }
        $problem = $this->updater->methodChangeProblem();
        if ($problem !== null) {
            $this->addFlash('error', $problem);

            return $this->redirectToRoute('app_updates');
        }
        try {
            $method = UpdateSettings::validateMethod($submitted['updates_method'] ?? null);
            $this->settings->saveMethod($method);
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_updates');
        } catch (\RuntimeException) {
            $this->addFlash('error', 'The update method could not be saved. Check that config/aggregate.yaml is valid and writable, or set updates_method there directly.');

            return $this->redirectToRoute('app_updates');
        }
        $this->updates->check(true);
        $label = $method === UpdateSettings::METHOD_REPOSITORY ? 'from the repository (advanced)' : 'with release ZIPs';
        $this->addFlash('success', 'This installation now updates '.$label.'. The choice is saved as updates_method in the YAML configuration.');
        if ($method !== $this->updates->detectedMethod()) {
            $this->addFlash('warning', $method === UpdateSettings::METHOD_REPOSITORY
                ? 'This directory is not a Git clone yet, so repository updates cannot run until it is one. The system check explains what to do; you can switch back to release ZIPs at any time.'
                : 'This directory is a Git clone, so release ZIPs cannot be installed over it. Install a release ZIP into a new directory, or switch back to repository updates.');
        }

        return $this->redirectToRoute('app_updates');
    }

    /**
     * Save updates_branch to the active YAML configuration, the same setting
     * operators can edit in config/aggregate.yaml.
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
        $branch = is_string($submitted['updates_branch'] ?? null) ? trim($submitted['updates_branch']) : null;
        try {
            $this->settings->saveBranch(UpdateSettings::validateBranch($branch));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_updates');
        } catch (\RuntimeException) {
            $this->addFlash('error', 'The update settings could not be saved. Check that config/aggregate.yaml is valid and writable, or edit updates_branch there directly.');

            return $this->redirectToRoute('app_updates');
        }
        $this->updates->check(true);
        $this->addFlash('success', 'Update settings saved to the YAML configuration.');

        return $this->redirectToRoute('app_updates');
    }

    /** The method an administrator chose, or null when none is chosen or the setting is invalid (the status explains it). */
    private function chosenMethod(): ?string
    {
        try {
            return $this->settings->method();
        } catch (\InvalidArgumentException|\RuntimeException) {
            return null;
        }
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
