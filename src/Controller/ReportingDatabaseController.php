<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AggregateConfigLoader;
use App\Service\BigQuery\BigQueryViewCatalog;
use App\Service\ReportingDatabase\ReportingDatabaseBackgroundSync;
use App\Service\ReportingDatabase\ReportingDatabaseConnection;
use App\Service\ReportingDatabase\ReportingDatabaseException;
use App\Service\ReportingDatabase\ReportingDatabaseSettings;
use App\Service\ReportingDatabase\ReportingDatabaseSyncRunner;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Admin page for the reporting database sync. The settings it saves are the
 * same YAML keys app:reporting-database:sync reads. The connection is
 * REPORTING_DATABASE_URL in the server environment and is only described here,
 * never edited or shown with its credentials.
 */
final class ReportingDatabaseController extends AbstractController
{
    use DashboardAccess;

    public const CSRF_TOKEN_ID = 'reporting_database_settings';
    private const SESSION_SYNC_REQUESTED = 'reporting_database_sync_requested_at';

    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly ReportingDatabaseSettings $settings,
        private readonly ReportingDatabaseConnection $database,
        private readonly ReportingDatabaseSyncRunner $runner,
        private readonly ReportingDatabaseBackgroundSync $background,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/dashboard/reporting-database', name: 'app_reporting_database', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyUnlessAvailableToAdmin();
        $error = null;
        try {
            $settings = $this->settings->toArray();
        } catch (\Throwable $e) {
            $this->logger->error('Failed to load reporting database settings.', ['exception' => $e]);
            $settings = ReportingDatabaseSettings::validate([]);
            $error = 'The reporting database settings in YAML or the environment are invalid: '.($e instanceof \InvalidArgumentException ? $e->getMessage() : 'see the application log.').' Correct them before syncing.';
        }

        $location = null;
        $connectionError = null;
        try {
            $location = ReportingDatabaseConnection::label($this->database->describe());
        } catch (ReportingDatabaseException $e) {
            $connectionError = $e->getMessage();
        }
        try {
            $status = $this->runner->status($settings);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to read reporting database sync status.', ['exception' => $e]);
            $status = ['last_run' => null, 'views' => [], 'scheduler_late' => false];
            $error ??= 'The sync status could not be read. Run the database migrations (php bin/console doctrine:migrations:migrate).';
        }
        // After “Sync now”, the page waits for the background run to start and finish.
        $requested = $request->getSession()->get(self::SESSION_SYNC_REQUESTED);
        $lastRun = $status['last_run'];
        if (!is_int($requested) || $requested < time() - 600 || ($lastRun !== null && $lastRun->getTimestamp() >= $requested && !in_array('running', array_column($status['views'], 'status'), true))) {
            $request->getSession()->remove(self::SESSION_SYNC_REQUESTED);
            $requested = null;
        }

        return $this->render('reporting_database/index.html.twig', [
            'settings' => $settings,
            'overrides' => $this->settings->getEnvironmentOverrides(),
            'configuration_error' => $error,
            'approved_views' => BigQueryViewCatalog::APPROVED,
            'private_views' => BigQueryViewCatalog::PRIVATE,
            'intervals' => ReportingDatabaseSettings::INTERVALS,
            'location' => $location,
            'connection_error' => $connectionError,
            'status' => $status,
            'sync_requested_at' => $requested,
            'background_problem' => $this->background->problem(),
            'cron_line' => $this->background->cronLine(),
        ]);
    }

    #[Route('/dashboard/reporting-database/status', name: 'app_reporting_database_status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        $this->denyUnlessAvailableToAdmin();
        try {
            $status = $this->runner->status($this->settings->toArray());
        } catch (\Throwable) {
            return new JsonResponse(['running' => false, 'last_run' => null, 'views' => []], headers: ['Cache-Control' => 'no-store']);
        }

        return new JsonResponse([
            'running' => in_array('running', array_column($status['views'], 'status'), true),
            'last_run' => $status['last_run']?->getTimestamp(),
            'views' => array_map(static fn (array $view): array => ['view' => $view['view'], 'status' => $view['status']], $status['views']),
        ], headers: ['Cache-Control' => 'no-store']);
    }

    #[Route('/dashboard/reporting-database/views', name: 'app_reporting_database_views', methods: ['POST'])]
    public function saveViews(Request $request): Response
    {
        if (($response = $this->guard($request, ['views', 'private_views', 'private_views_confirmed'])) !== null) {
            return $response;
        }
        $views = $request->request->all()['views'] ?? [];
        $private = $request->request->all()['private_views'] ?? [];
        if (!is_array($views) || !is_array($private)) {
            $this->addFlash('error', 'The form contained an unexpected value. Nothing was saved.');

            return $this->redirectToRoute('app_reporting_database');
        }
        try {
            $current = $this->settings->toArray()[ReportingDatabaseSettings::KEY_PRIVATE_VIEWS];
            $added = array_diff(array_map('strval', $private), $current);
            if ($added !== [] && $request->request->get('private_views_confirmed') !== '1') {
                throw new \InvalidArgumentException('Confirm that the private views you selected may be copied to the reporting database. They hold row-level or unsuppressed data.');
            }
            $this->settings->save([
                ReportingDatabaseSettings::KEY_VIEWS => array_values(array_map('strval', $views)),
                ReportingDatabaseSettings::KEY_PRIVATE_VIEWS => array_values(array_map('strval', $private)),
            ]);
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_reporting_database');
        } catch (\Throwable $e) {
            $this->logger->error('Failed to save reporting database views.', ['exception' => $e]);
            $this->addFlash('error', 'The views could not be saved. Check the application log and configuration-file permissions.');

            return $this->redirectToRoute('app_reporting_database');
        }
        $this->addFlash('success', 'The views to copy were saved. Tables of views you removed stay in the reporting database until you drop them there.');

        return $this->redirectToRoute('app_reporting_database');
    }

    #[Route('/dashboard/reporting-database/schedule', name: 'app_reporting_database_schedule', methods: ['POST'])]
    public function saveSchedule(Request $request): Response
    {
        if (($response = $this->guard($request, ['enabled', 'interval'])) !== null) {
            return $response;
        }
        try {
            $this->settings->save(array_filter([
                ReportingDatabaseSettings::KEY_ENABLED => $request->request->get('enabled', '0'),
                ReportingDatabaseSettings::KEY_INTERVAL => $request->request->get('interval'),
            ], static fn (mixed $value): bool => $value !== null));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_reporting_database');
        } catch (\Throwable $e) {
            $this->logger->error('Failed to save the reporting database schedule.', ['exception' => $e]);
            $this->addFlash('error', 'The schedule could not be saved. Check the application log and configuration-file permissions.');

            return $this->redirectToRoute('app_reporting_database');
        }
        $this->addFlash('success', 'The sync schedule was saved.');

        return $this->redirectToRoute('app_reporting_database');
    }

    #[Route('/dashboard/reporting-database/test', name: 'app_reporting_database_test', methods: ['POST'])]
    public function test(Request $request): Response
    {
        if (($response = $this->guard($request)) !== null) {
            return $response;
        }
        try {
            $check = $this->runner->check();
            $this->addFlash('success', sprintf(
                'Connected to %s (server version %s). This user can create and drop tables. %s',
                ReportingDatabaseConnection::label($check),
                $check['server_version'],
                $check['tables'] === [] ? 'No views have been copied yet.' : count($check['tables']).' copied view'.(count($check['tables']) === 1 ? '' : 's').' found.',
            ));
        } catch (ReportingDatabaseException $e) {
            $this->addFlash('error', 'The connection test failed: '.$e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('Reporting database connection test failed.', ['exception' => $e]);
            $this->addFlash('error', 'The connection test failed; see the application log.');
        }

        return $this->redirectToRoute('app_reporting_database');
    }

    #[Route('/dashboard/reporting-database/sync', name: 'app_reporting_database_sync', methods: ['POST'])]
    public function syncNow(Request $request): Response
    {
        if (($response = $this->guard($request)) !== null) {
            return $response;
        }
        try {
            if (!$this->settings->toArray()[ReportingDatabaseSettings::KEY_ENABLED]) {
                throw new \RuntimeException('Turn the reporting database sync on before syncing.');
            }
            if (!$this->database->isConfigured()) {
                throw new \RuntimeException('Set REPORTING_DATABASE_URL on the server before syncing.');
            }
            $this->background->start($this->getUser()?->getUserIdentifier() ?? 'administrator');
            $request->getSession()->set(self::SESSION_SYNC_REQUESTED, time());
            $this->addFlash('success', 'The sync has started. This page shows each view’s result when it finishes.');
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_reporting_database');
    }

    /** Admin, dashboard and CSRF checks for form posts; only the listed fields may be submitted. @param list<string> $fields */
    private function guard(Request $request, array $fields = []): ?Response
    {
        $this->denyUnlessAvailableToAdmin();
        $submitted = $request->request->all();
        $token = $submitted['_csrf_token'] ?? null;
        if (!is_string($token) || !$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $token)) {
            $this->addFlash('error', 'Invalid security token. Please try again.');

            return $this->redirectToRoute('app_reporting_database');
        }
        if (array_diff(array_keys($submitted), ['_csrf_token', ...$fields]) !== []) {
            $this->addFlash('error', 'The form contained an unexpected setting. Nothing was saved.');

            return $this->redirectToRoute('app_reporting_database');
        }

        return null;
    }
}
