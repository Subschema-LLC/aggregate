<?php

declare(strict_types=1);

namespace App\Service\ReportingDatabase;

use App\Service\Operations\ProcessingTasks;
use App\Service\ViewSync\ViewCatalog;
use App\Service\ViewSync\ViewExporter;
use App\Service\ViewSync\ViewSnapshot;
use App\Service\ViewSync\ViewSyncException;
use App\Service\ViewSync\ViewSyncRunner;
use Psr\Log\LoggerInterface;

/**
 * Copies the selected reporting views into same-named tables in the reporting
 * database (see ViewSyncRunner), so BI tools can read them without access to
 * the application database. Each sync replaces a table with a complete copy of
 * its view (see ReportingTableWriter). Each view sync is a processing task of
 * type reporting_database_sync.
 *
 * A reporting database marked as a read-only reporting copy for BI users (see
 * ReportingDatabaseConnection::mark()) gets approved views only: its readers
 * see every table, so a private view selected for it fails instead of being
 * copied. One marked as another installation's copy is not written at all.
 *
 * @extends ViewSyncRunner<ReportingTableWriter>
 */
class ReportingDatabaseSyncRunner extends ViewSyncRunner
{
    public const TASK_TYPE = 'reporting_database_sync';

    /** @var array{source: ?string}|null the open reporting database's mark */
    private ?array $mark = null;

    /** @param \Closure(): \DateTimeImmutable|null $clock */
    public function __construct(
        ReportingDatabaseSettings $settings,
        private readonly ReportingDatabaseConnection $database,
        ViewExporter $exporter,
        ProcessingTasks $tasks,
        LoggerInterface $logger,
        ?\Closure $clock = null,
    ) {
        parent::__construct($settings, $exporter, $tasks, $logger, $clock);
    }

    /**
     * Connects, and creates and drops a small table to prove the sync may
     * replace tables, without touching the copied views.
     *
     * @return array{engine: string, host: ?string, port: ?int, database: string, server_version: string, tables: list<string>, mark: array{source: ?string}|null}
     *
     * @throws ReportingDatabaseException with a message for the administrator
     */
    public function check(): array
    {
        try {
            return $this->database->probe();
        } catch (ReportingDatabaseException $e) {
            if ($e->getPrevious() !== null) {
                $this->logger->error('The reporting database check failed.', ['exception' => $e]);
            }
            throw $e;
        }
    }

    protected function open(array $settings): ReportingTableWriter
    {
        $this->mark = null;
        $connection = $this->database->connection();
        $connection->getServerVersion();
        $this->mark = $this->database->assertMarkAllowsSync();

        return new ReportingTableWriter($connection);
    }

    protected function refusal(string $view, object $destination, array $settings): ?string
    {
        if ($this->mark === null || !ViewCatalog::isPrivate($view)) {
            return null;
        }

        return $view.' holds row-level or unsuppressed data, and the reporting database is marked as a read-only reporting copy for BI users, who can read every table in it. Only approved views are copied to it: remove '.$view.' from '.ReportingDatabaseSettings::KEY_PRIVATE_VIEWS.', or use another reporting database for it.';
    }

    /**
     * Writes the snapshot's rows to the reporting database, replacing the
     * view's table (see ReportingTableWriter). The view was read from the
     * application database before this starts.
     *
     * @param ReportingTableWriter $destination
     */
    protected function replace(ViewSnapshot $snapshot, object $destination, array $settings, ?string &$externalId): void
    {
        $destination->replace($snapshot->view, $snapshot->fields, $snapshot->records(), $snapshot->rows);
    }

    /** @param ReportingTableWriter $destination */
    protected function copiedRows(string $view, object $destination, array $settings): ?int
    {
        return $destination->rowCount($view);
    }

    protected function copyName(string $view, object $destination, array $settings): string
    {
        return $view;
    }

    protected function setupFailure(\Throwable $e): string
    {
        return $this->message($e, 'The reporting database could not be reached');
    }

    protected function copyFailure(string $view, \Throwable $e): string
    {
        return $this->message($e, 'The view could not be copied');
    }

    /**
     * A message that is safe to store and show. Reporting database and view
     * errors carry their own; a database error during setup or table changes
     * names identifiers only, so its first line is kept. Anything else is
     * logged and summarized.
     */
    private function message(\Throwable $e, string $context): string
    {
        if ($e instanceof ViewSyncException) {
            if ($e->getPrevious() !== null) {
                $this->logger->error($context.'.', ['exception' => $e]);
            }

            return $e->getMessage();
        }
        $this->logger->error($context.'.', ['exception' => $e]);

        return ReportingDatabaseConnection::firstLine($e, $context);
    }
}
