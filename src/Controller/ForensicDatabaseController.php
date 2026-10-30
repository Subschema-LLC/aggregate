<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AggregateConfigLoader;
use App\Service\ForensicDatabase\ForensicCatalog;
use App\Service\ForensicDatabase\ForensicDatabaseBackgroundSync;
use App\Service\ForensicDatabase\ForensicDatabaseConfigurator;
use App\Service\ForensicDatabase\ForensicDatabaseConnection;
use App\Service\ForensicDatabase\ForensicDatabaseSettings;
use App\Service\ForensicDatabase\ForensicDatabaseSyncRunner;
use App\Service\ReportingDatabase\ReportingDatabaseConnection;
use App\Service\ReportingDatabase\ReportingDatabaseException;
use App\Service\ViewSync\ViewSyncDestinations;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Admin page for the forensic database sync, administrators only. It holds
 * the settings app:forensic-database:sync reads (the same YAML keys), the
 * connection, the access confirmation and each copy's status: names, row
 * counts and times. It never reads or shows forensic rows; they are read in
 * the forensic database itself, with its own database accounts.
 */
final class ForensicDatabaseController extends AbstractController
{
    use DashboardAccess;
    use ViewSyncAdmin;

    public const CSRF_TOKEN_ID = 'forensic_database_settings';
    private const ROUTE = 'app_forensic_database';
    private const SESSION_SYNC_REQUESTED = 'forensic_database_sync_requested_at';
    /** Fields of a connection that failed its test, shown again without the password. */
    private const SESSION_CONNECTION_FORM = 'forensic_database_connection_form';
    private const CONNECTION_FIELDS = ['type', 'host', 'port', 'database', 'user', 'password', 'trust_certificate'];

    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly ForensicDatabaseSettings $settings,
        private readonly ForensicDatabaseConnection $database,
        private readonly ForensicDatabaseSyncRunner $runner,
        private readonly ForensicDatabaseBackgroundSync $background,
        private readonly ForensicDatabaseConfigurator $configurator,
        private readonly LoggerInterface $logger,
        private readonly ViewSyncDestinations $destinations,
    ) {
    }

    #[Route('/dashboard/forensic-database', name: 'app_forensic_database', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyUnlessPermitted();
        [$settings, $error] = $this->loadSyncSettings();

        $location = null;
        $connectionError = null;
        try {
            $location = ReportingDatabaseConnection::label($this->database->describe());
        } catch (ReportingDatabaseException $e) {
            $connectionError = $e->getMessage();
        }

        return $this->render('forensic_database/index.html.twig', [
            ...$this->syncPage($request, $settings, $error),
            'forensic_tables' => ForensicCatalog::tableDescriptions(),
            'location' => $location,
            'connection_error' => $connectionError,
            'connection' => $this->connectionForm($request),
            'connection_problem' => $this->configurator->problem(),
            'connection_saved_here' => $this->configurator->isSavedHere(),
            'types' => array_map(static fn (array $type): array => $type + ['available' => extension_loaded($type['extension'])], ReportingDatabaseConnection::TYPES),
            'mark' => ForensicDatabaseConnection::FORENSIC_MARK,
            'application_database' => $this->database->applicationDatabase(),
        ]);
    }

    /**
     * Saves whether access to a forensic database on an engine that cannot be
     * marked is confirmed restricted. Confirming needs the form's checkbox.
     */
    #[Route('/dashboard/forensic-database/access', name: 'app_forensic_database_access', methods: ['POST'])]
    public function saveAccess(Request $request): Response
    {
        if (($response = $this->guard($request, ['access_restricted', 'access_confirmed'])) !== null) {
            return $response;
        }
        $restricted = $request->request->get('access_restricted') === '1';
        try {
            if ($restricted && !$this->settings->toArray()[ForensicDatabaseSettings::KEY_ACCESS_RESTRICTED] && $request->request->get('access_confirmed') !== '1') {
                throw new \InvalidArgumentException('Confirm that only the people who investigate problems can read the forensic database.');
            }
            $this->settings->save([ForensicDatabaseSettings::KEY_ACCESS_RESTRICTED => $restricted]);
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute(self::ROUTE);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to save the forensic database access confirmation.', ['exception' => $e]);
            $this->addFlash('error', 'The confirmation could not be saved. Check the application log and configuration-file permissions.');

            return $this->redirectToRoute(self::ROUTE);
        }
        $this->addFlash('success', $restricted
            ? 'Saved: access to the forensic database is confirmed restricted. A database whose engine keeps comments must still be marked.'
            : 'Saved: the confirmation was withdrawn. A forensic database that cannot be marked receives nothing until it is confirmed again.');

        return $this->redirectToRoute(self::ROUTE);
    }

    /** Tests the connection from the form and saves it to .env.local. */
    #[Route('/dashboard/forensic-database/connection', name: 'app_forensic_database_connection', methods: ['POST'])]
    public function saveConnection(Request $request): Response
    {
        if (($response = $this->guard($request, self::CONNECTION_FIELDS)) !== null) {
            return $response;
        }
        $fields = array_intersect_key($request->request->all(), array_flip(self::CONNECTION_FIELDS));
        try {
            $check = $this->configurator->save($fields);
            $request->getSession()->remove(self::SESSION_CONNECTION_FORM);
            $this->addFlash('success', 'Saved to .env.local. '.$this->checkSentence($check));
        } catch (\InvalidArgumentException|ReportingDatabaseException $e) {
            unset($fields['password']);
            $request->getSession()->set(self::SESSION_CONNECTION_FORM, $fields);
            $this->addFlash('error', 'The connection was not saved: '.$e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('Failed to save the forensic database connection.', ['exception' => $e]);
            $this->addFlash('error', 'The connection was not saved; see the application log.');
        }

        return $this->redirectToRoute(self::ROUTE);
    }

    #[Route('/dashboard/forensic-database/connection/remove', name: 'app_forensic_database_connection_remove', methods: ['POST'])]
    public function removeConnection(Request $request): Response
    {
        if (($response = $this->guard($request)) !== null) {
            return $response;
        }
        try {
            $this->configurator->remove();
            $this->addFlash('success', 'The connection was removed from .env.local. Copies already made stay in the forensic database until you drop them there.');
        } catch (\Throwable $e) {
            $this->addFlash('error', $e instanceof ReportingDatabaseException || $e instanceof \RuntimeException ? $e->getMessage() : 'The connection could not be removed.');
        }

        return $this->redirectToRoute(self::ROUTE);
    }

    /**
     * Saves the table copies and private views to copy. Every one of them is
     * row-level or unsuppressed data, so adding any needs the confirmation.
     */
    #[Route('/dashboard/forensic-database/views', name: 'app_forensic_database_views', methods: ['POST'])]
    public function saveViews(Request $request): Response
    {
        if (($response = $this->guard($request, ['tables', 'views', 'confirmed'])) !== null) {
            return $response;
        }
        $tables = $request->request->all()['tables'] ?? [];
        $views = $request->request->all()['views'] ?? [];
        if (!is_array($tables) || !is_array($views)) {
            $this->addFlash('error', 'The form contained an unexpected value. Nothing was saved.');

            return $this->redirectToRoute(self::ROUTE);
        }
        $tables = array_values(array_map('strval', $tables));
        $views = array_values(array_map('strval', $views));
        try {
            $current = $this->settings->toArray();
            $added = [...array_diff($tables, $current[ForensicDatabaseSettings::KEY_TABLES]), ...array_diff($views, $current[ForensicDatabaseSettings::KEY_VIEWS])];
            if ($added !== [] && $request->request->get('confirmed') !== '1') {
                throw new \InvalidArgumentException('Confirm that what you added may be copied to the forensic database. It holds raw events and unsuppressed counts.');
            }
            $this->settings->save([ForensicDatabaseSettings::KEY_TABLES => $tables, ForensicDatabaseSettings::KEY_VIEWS => $views]);
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute(self::ROUTE);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to save forensic database tables.', ['exception' => $e]);
            $this->addFlash('error', 'The tables and views could not be saved. Check the application log and configuration-file permissions.');

            return $this->redirectToRoute(self::ROUTE);
        }
        $this->addFlash('success', ForensicDatabaseSettings::definition()->viewsSaved);

        return $this->redirectToRoute(self::ROUTE);
    }

    #[Route('/dashboard/forensic-database/schedule', name: 'app_forensic_database_schedule', methods: ['POST'])]
    public function saveSchedule(Request $request): Response
    {
        return $this->saveSyncSchedule($request);
    }

    #[Route('/dashboard/forensic-database/status', name: 'app_forensic_database_status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        return $this->syncStatusResponse();
    }

    #[Route('/dashboard/forensic-database/test', name: 'app_forensic_database_test', methods: ['POST'])]
    public function test(Request $request): Response
    {
        if (($response = $this->guard($request)) !== null) {
            return $response;
        }
        try {
            $this->addFlash('success', $this->checkSentence($this->runner->check()));
        } catch (ReportingDatabaseException $e) {
            $this->addFlash('error', 'The connection test failed: '.$e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('Forensic database connection test failed.', ['exception' => $e]);
            $this->addFlash('error', 'The connection test failed; see the application log.');
        }

        return $this->redirectToRoute(self::ROUTE);
    }

    #[Route('/dashboard/forensic-database/sync', name: 'app_forensic_database_sync', methods: ['POST'])]
    public function syncNow(Request $request): Response
    {
        return $this->startSync($request, function (): void {
            if (!$this->settings->toArray()[ForensicDatabaseSettings::KEY_ENABLED]) {
                throw new \RuntimeException('Turn the forensic database sync on before syncing.');
            }
            if (!$this->database->isConfigured()) {
                throw new \RuntimeException('Set '.ForensicDatabaseConnection::VARIABLE.' on the server before syncing.');
            }
        });
    }

    /**
     * A successful check, for the page's message: where, what it found, and
     * what allows the copy. Names tables, never their rows.
     *
     * @param array{engine: string, host: ?string, port: ?int, database: string, server_version: string, tables: list<string>, mark: array{source: ?string}|null} $check
     */
    private function checkSentence(array $check): string
    {
        return trim(sprintf(
            'Connected to %s (server version %s). This user can create and drop tables. %s %s',
            ReportingDatabaseConnection::label($check),
            $check['server_version'],
            $check['tables'] === [] ? 'Nothing has been copied yet.' : count($check['tables']).' cop'.(count($check['tables']) === 1 ? 'y' : 'ies').' found.',
            ForensicDatabaseConnection::markSentence($check['mark']),
        ));
    }

    private function syncRunner(): ForensicDatabaseSyncRunner
    {
        return $this->runner;
    }

    private function syncBackground(): ForensicDatabaseBackgroundSync
    {
        return $this->background;
    }

    private function syncLogger(): LoggerInterface
    {
        return $this->logger;
    }

    private function syncDestinations(): ViewSyncDestinations
    {
        return $this->destinations;
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
}
