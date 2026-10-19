<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AggregateConfigLoader;
use App\Service\ReportingDatabase\ReportingDatabaseBackgroundSync;
use App\Service\ReportingDatabase\ReportingDatabaseConfigurator;
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
    use ViewSyncAdmin;

    public const CSRF_TOKEN_ID = 'reporting_database_settings';
    private const ROUTE = 'app_reporting_database';
    private const SESSION_SYNC_REQUESTED = 'reporting_database_sync_requested_at';
    /** Fields of a connection that failed its test, shown again without the password. */
    private const SESSION_CONNECTION_FORM = 'reporting_database_connection_form';
    private const CONNECTION_FIELDS = ['type', 'host', 'port', 'database', 'user', 'password', 'trust_certificate'];

    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly ReportingDatabaseSettings $settings,
        private readonly ReportingDatabaseConnection $database,
        private readonly ReportingDatabaseSyncRunner $runner,
        private readonly ReportingDatabaseBackgroundSync $background,
        private readonly ReportingDatabaseConfigurator $configurator,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/dashboard/reporting-database', name: 'app_reporting_database', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyUnlessAvailableToAdmin();
        [$settings, $error] = $this->loadSyncSettings();

        $location = null;
        $connectionError = null;
        try {
            $location = ReportingDatabaseConnection::label($this->database->describe());
        } catch (ReportingDatabaseException $e) {
            $connectionError = $e->getMessage();
        }
        return $this->render('reporting_database/index.html.twig', [
            ...$this->syncPage($request, $settings, $error),
            'location' => $location,
            'connection_error' => $connectionError,
            'connection' => $this->connectionForm($request),
            'connection_problem' => $this->configurator->problem(),
            'connection_saved_here' => $this->configurator->isSavedHere(),
            'types' => array_map(static fn (array $type): array => $type + ['available' => extension_loaded($type['extension'])], ReportingDatabaseConnection::TYPES),
        ]);
    }

    /** Tests the connection from the form and saves it to .env.local. */
    #[Route('/dashboard/reporting-database/connection', name: 'app_reporting_database_connection', methods: ['POST'])]
    public function saveConnection(Request $request): Response
    {
        if (($response = $this->guard($request, self::CONNECTION_FIELDS)) !== null) {
            return $response;
        }
        $fields = array_intersect_key($request->request->all(), array_flip(self::CONNECTION_FIELDS));
        try {
            $check = $this->configurator->save($fields);
            $request->getSession()->remove(self::SESSION_CONNECTION_FORM);
            $this->addFlash('success', sprintf(
                'Connected to %s (server version %s) and saved to .env.local. This user can create and drop tables. %s',
                ReportingDatabaseConnection::label($check),
                $check['server_version'],
                $check['tables'] === [] ? 'No views have been copied yet.' : count($check['tables']).' copied view'.(count($check['tables']) === 1 ? '' : 's').' found.',
            ));
        } catch (\InvalidArgumentException|ReportingDatabaseException $e) {
            unset($fields['password']);
            $request->getSession()->set(self::SESSION_CONNECTION_FORM, $fields);
            $this->addFlash('error', 'The connection was not saved: '.$e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('Failed to save the reporting database connection.', ['exception' => $e]);
            $this->addFlash('error', 'The connection was not saved; see the application log.');
        }

        return $this->redirectToRoute(self::ROUTE);
    }

    #[Route('/dashboard/reporting-database/connection/remove', name: 'app_reporting_database_connection_remove', methods: ['POST'])]
    public function removeConnection(Request $request): Response
    {
        if (($response = $this->guard($request)) !== null) {
            return $response;
        }
        try {
            $this->configurator->remove();
            $this->addFlash('success', 'The connection was removed from .env.local. Tables already copied stay in the reporting database.');
        } catch (\Throwable $e) {
            $this->addFlash('error', $e instanceof ReportingDatabaseException || $e instanceof \RuntimeException ? $e->getMessage() : 'The connection could not be removed.');
        }

        return $this->redirectToRoute(self::ROUTE);
    }

    private function syncRunner(): ReportingDatabaseSyncRunner
    {
        return $this->runner;
    }

    private function syncBackground(): ReportingDatabaseBackgroundSync
    {
        return $this->background;
    }

    private function syncLogger(): LoggerInterface
    {
        return $this->logger;
    }

    /**
     * The connection form's values: those of a failed attempt, or the saved
     * connection's, never its password.
     *
     * @return array{type: string, host: string, port: string, database: string, user: string, trust_certificate: bool, has_password: bool}
     */
    private function connectionForm(Request $request): array
    {
        $saved = $this->database->fields();
        $form = $saved ?? ['type' => 'pgsql', 'host' => 'localhost', 'port' => '', 'database' => '', 'user' => '', 'trust_certificate' => false, 'has_password' => false];
        $failed = $request->getSession()->get(self::SESSION_CONNECTION_FORM);
        $request->getSession()->remove(self::SESSION_CONNECTION_FORM);
        if (is_array($failed)) {
            foreach (['type', 'host', 'port', 'database', 'user'] as $key) {
                $form[$key] = is_scalar($failed[$key] ?? null) ? (string) $failed[$key] : '';
            }
            $form['trust_certificate'] = ($failed['trust_certificate'] ?? null) === '1';
        }
        if (!isset(ReportingDatabaseConnection::TYPES[$form['type']])) {
            $form['type'] = 'pgsql';
        }

        return $form;
    }

    #[Route('/dashboard/reporting-database/status', name: 'app_reporting_database_status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        return $this->syncStatusResponse();
    }

    #[Route('/dashboard/reporting-database/views', name: 'app_reporting_database_views', methods: ['POST'])]
    public function saveViews(Request $request): Response
    {
        return $this->saveSyncViews($request);
    }

    #[Route('/dashboard/reporting-database/schedule', name: 'app_reporting_database_schedule', methods: ['POST'])]
    public function saveSchedule(Request $request): Response
    {
        return $this->saveSyncSchedule($request);
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

        return $this->redirectToRoute(self::ROUTE);
    }

    #[Route('/dashboard/reporting-database/sync', name: 'app_reporting_database_sync', methods: ['POST'])]
    public function syncNow(Request $request): Response
    {
        return $this->startSync($request, function (): void {
            if (!$this->settings->toArray()[ReportingDatabaseSettings::KEY_ENABLED]) {
                throw new \RuntimeException('Turn the reporting database sync on before syncing.');
            }
            if (!$this->database->isConfigured()) {
                throw new \RuntimeException('Set REPORTING_DATABASE_URL on the server before syncing.');
            }
        });
    }
}
