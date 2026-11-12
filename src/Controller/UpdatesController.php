<?php

declare(strict_types=1);

namespace App\Controller;

use App\EventSubscriber\AdminAuditSubscriber;
use App\Service\AggregateConfigLoader;
use App\Service\Alerts\AlertSettings;
use App\Service\ApplicationUpdateService;
use App\Service\Cluster\BalancerConfig;
use App\Service\Cluster\ClusterOverview;
use App\Service\Cluster\ClusterServerList;
use App\Service\Cluster\ClusterSettings;
use App\Service\Cluster\ServerRole;
use App\Service\FeatureFlags;
use App\Service\Update\ApplicationUpdater;
use App\Service\Update\BackgroundUpdate;
use App\Service\Update\DeploymentAction;
use App\Service\Update\SystemCheck;
use App\Service\UpdateSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class UpdatesController extends AbstractController
{
    use DashboardAccess {
        denyUnlessPermitted as private denyUnlessPagePermitted;
    }

    public const CSRF_TOKEN_ID = 'application_updates_refresh';
    public const INSTALL_CSRF_TOKEN_ID = 'application_updates_install';
    public const SETTINGS_CSRF_TOKEN_ID = 'application_updates_settings';
    public const UPLOAD_CSRF_TOKEN_ID = 'application_updates_upload';
    public const METHOD_CSRF_TOKEN_ID = 'application_updates_method';
    public const CLUSTER_CSRF_TOKEN_ID = 'application_updates_cluster';

    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly ApplicationUpdateService $updates,
        private readonly FeatureFlags $features,
        private readonly ApplicationUpdater $updater,
        private readonly BackgroundUpdate $background,
        private readonly SystemCheck $systemCheck,
        private readonly UpdateSettings $settings,
        private readonly ?DeploymentAction $deploymentAction = null,
        private readonly ?ClusterOverview $cluster = null,
        private readonly ?ServerRole $role = null,
        private readonly ?AlertSettings $alerts = null,
    ) {
    }

    #[Route('/dashboard/updates', name: 'app_updates', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyUnlessPermitted();

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
            'update_deployment_action' => ($status['installation_type'] ?? null) === 'deployment' ? $this->deploymentAction() : null,
            'update_cluster' => $this->clusterOverview(),
            'update_cluster_settings' => $this->clusterSettings(),
        ]);
    }

    #[Route('/dashboard/updates/refresh', name: 'app_updates_refresh', methods: ['POST'])]
    public function refresh(Request $request): Response
    {
        $this->denyUnlessPermitted();

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
        $this->denyUnlessPermitted();

        $submitted = $request->request->all();
        $csrfToken = $submitted['_csrf_token'] ?? null;
        if (!is_string($csrfToken) || !$this->isCsrfTokenValid(self::INSTALL_CSRF_TOKEN_ID, $csrfToken)) {
            $this->addFlash('error', 'Invalid security token. Please try again.');

            return $this->redirectToRoute('app_updates');
        }

        $status = $this->updates->check(true);
        if (($status['installation_type'] ?? null) === 'deployment') {
            $this->addFlash('error', 'This installation is deployed another way, so updates are installed with that tool, not from this page.');

            return $this->redirectToRoute('app_updates');
        }
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
        $this->denyUnlessPermitted();

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
            $staged = $this->background->stageUpload($files);
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

    /**
     * @param array<string, mixed> $submitted
     * @param list<string> $arguments
     */
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
            $problems = $this->background->problems();
        }

        return $problems === [] ? null
            : 'The update cannot start from the dashboard: '.implode(' ', $problems).' You can run php bin/console app:updates:apply on the server instead.';
    }

    /**
     * @param array<string, mixed> $submitted
     * @param list<string> $arguments
     */
    private function launch(array $submitted, array $arguments, string $prefix = ''): Response
    {
        if (($submitted['database_backup_confirmed'] ?? null) === '1') {
            array_unshift($arguments, '--database-backup-confirmed');
        }
        try {
            $this->background->start($arguments);
        } catch (\RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_updates');
        }
        $this->addFlash('success', $prefix.'The update has started. The site shows a maintenance page while files are replaced; reload this page to follow its progress.');

        return $this->redirectToRoute('app_updates');
    }

    /**
     * Save the update method an administrator chose (updates_method) to the
     * active YAML configuration: release ZIPs, the repository (advanced) or code
     * deployed another way. The Updates page then shows only that method.
     */
    #[Route('/dashboard/updates/method', name: 'app_updates_method', methods: ['POST'])]
    public function saveMethod(Request $request): Response
    {
        $this->denyUnlessPermitted();

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
        $label = match ($method) {
            UpdateSettings::METHOD_REPOSITORY => 'from the repository (advanced)',
            UpdateSettings::METHOD_DEPLOYMENT => 'by deploying the code another way',
            default => 'with release ZIPs',
        };
        $this->addFlash('success', 'This installation now updates '.$label.'. The choice is saved as updates_method in the YAML configuration.');
        if ($method === UpdateSettings::METHOD_DEPLOYMENT) {
            $this->addFlash('warning', 'After each deployment, run the post-deployment command shown on this page, or add it to your deployment tool\'s deployment action.');
        } elseif ($method !== $this->updates->detectedMethod()) {
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
        $this->denyUnlessPermitted();

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

    /** @return array<string, mixed>|null The deployment action for this server, or null when it cannot be built */
    private function deploymentAction(): ?array
    {
        try {
            return $this->deploymentAction?->build();
        } catch (\Throwable) {
            return null;
        }
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

    private function denyUnlessPermitted(): void
    {
        $this->denyUnlessPagePermitted();

        if (!$this->features->isEnabled('updates')) {
            throw $this->createNotFoundException('Updates are disabled.');
        }
    }

    #[Route('/dashboard/updates/cluster/settings', name: 'app_updates_cluster_settings', methods: ['POST'])]
    public function saveClusterSettings(Request $request): Response
    {
        $this->denyUnlessPermitted();
        if (!$this->validClusterToken($request)) {
            return $this->toCluster();
        }
        $form = $request->request;
        $destinations = match ($form->get('alert_destinations_mode')) {
            'all' => 'all',
            'none' => 'none',
            'selected' => array_values(array_filter($form->all()['alert_destinations'] ?? [], 'is_string')),
            default => null,
        };
        try {
            if ($destinations === null || $destinations === []) {
                throw new \InvalidArgumentException('Choose which alert destinations hear about a server that stops pulling: every one, none, or the ones you tick.');
            }
            $validated = ClusterSettings::validateSettings([
                ClusterSettings::MAX_AGE_KEY => trim((string) $form->get('max_age_minutes')),
                ClusterSettings::COLLECTION_PORT_KEY => trim((string) $form->get('collection_port')),
                ClusterSettings::ALERT_DESTINATIONS_KEY => $destinations,
            ], array_keys($this->alerts?->destinations() ?? []));
            $this->config->updateMany(static fn (): array => $validated);
            $this->addFlash('success', 'The cluster settings were saved. The other servers take them up on their next pull.');
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->toCluster();
    }

    #[Route('/dashboard/updates/cluster/servers/add', name: 'app_updates_cluster_server_add', methods: ['POST'])]
    public function addClusterServer(Request $request): Response
    {
        $this->denyUnlessPermitted();
        if (!$this->validClusterToken($request)) {
            return $this->toCluster();
        }
        $form = $request->request;
        try {
            $server = (new ClusterServerList($this->config))->add(
                trim((string) $form->get('name')),
                trim((string) $form->get('address')),
                (string) $form->get('role'),
                trim((string) $form->get('port')),
            );
            $request->attributes->set(AdminAuditSubscriber::SUBJECT, $server['name']);
            $this->addFlash('success', sprintf('%s (%s) may now pull configuration from %s.', $server['name'], $server['role'], $server['address']));
            if ($server['role'] === ServerRole::COLLECTION) {
                $this->addFlash('warning', 'Download the balancer configuration again and install it on the balancer, so it sends traffic to '.$server['name'].' too.');
            }
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->toCluster();
    }

    #[Route('/dashboard/updates/cluster/servers/{name}/remove', name: 'app_updates_cluster_server_remove', requirements: ['name' => '[a-z0-9][a-z0-9.-]{0,62}'], methods: ['POST'])]
    public function removeClusterServer(Request $request, string $name): Response
    {
        $this->denyUnlessPermitted();
        if (!$this->validClusterToken($request)) {
            return $this->toCluster();
        }
        try {
            (new ClusterServerList($this->config))->remove($name);
            $request->attributes->set(AdminAuditSubscriber::SUBJECT, $name);
            $this->addFlash('success', $name.' may no longer pull configuration. If it was a collection server, download the balancer configuration again and install it on the balancer.');
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->toCluster();
    }

    /** The balancer's configuration, as app:server:balancer-config prints it. */
    #[Route('/dashboard/updates/cluster/balancer/{format}', name: 'app_updates_cluster_balancer', requirements: ['format' => 'nginx|caddy|caddy-rate-limit'], methods: ['GET'])]
    public function balancerConfiguration(string $format): Response
    {
        $this->denyUnlessPermitted();
        try {
            $balancer = BalancerConfig::forInstallation($this->config);
            $text = $format === 'nginx' ? $balancer->nginx() : $balancer->caddy($format === 'caddy-rate-limit');
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->toCluster();
        }
        $response = new Response($text);
        $response->headers->set('Content-Type', 'text/plain; charset=UTF-8');
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition('attachment', $format === 'nginx' ? 'aggregate-balancer.conf' : 'Caddyfile'));
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    private function validClusterToken(Request $request): bool
    {
        $token = $request->request->get('_csrf_token');
        if (is_string($token) && $this->isCsrfTokenValid(self::CLUSTER_CSRF_TOKEN_ID, $token)) {
            return true;
        }
        $this->addFlash('error', 'Invalid security token. Please try again.');

        return false;
    }

    private function toCluster(): Response
    {
        return $this->redirect($this->generateUrl('app_updates').'#cluster-servers');
    }

    /**
     * The cluster's settings, on an administration server, for the Servers
     * panel's forms.
     *
     * @return array<string, mixed>|null
     */
    private function clusterSettings(): ?array
    {
        if ($this->role?->role !== ServerRole::ADMINISTRATION) {
            return null;
        }
        $settings = new ClusterSettings($this->config);
        try {
            $destinations = $this->alerts?->destinations() ?? [];
        } catch (\Throwable) {
            $destinations = [];
        }

        return [
            'max_age_minutes' => $settings->maxAgeMinutes(),
            'alert_destinations' => $settings->alertDestinations(),
            'collection_port' => $settings->collectionPort(),
            'destinations' => array_keys($destinations),
            'key_set' => $settings->key() !== null,
            'roles' => ClusterSettings::SERVER_ROLES,
        ];
    }

    /**
     * The other servers of a cluster, when this administration server has
     * any in its list, so the page shows which still need --files-only.
     *
     * @return array{release: ?string, version: ?string, servers: list<array<string, mixed>>}|null
     */
    private function clusterOverview(): ?array
    {
        $overview = $this->cluster?->overview();
        if ($overview === null) {
            return null;
        }

        // An administration server shows the panel with an empty list too,
        // so its first server can be added here.
        return $overview['servers'] !== [] || $this->role?->role === ServerRole::ADMINISTRATION ? $overview : null;
    }
}
