<?php

declare(strict_types=1);

namespace App\Service\ForensicDatabase;

use App\Service\Operations\ProcessingTasks;
use App\Service\ReportingDatabase\ReportingDatabaseConnection;
use App\Service\ReportingDatabase\ReportingDatabaseException;
use App\Service\ReportingDatabase\ReportingTableWriter;
use App\Service\ViewSync\ViewExporter;
use App\Service\ViewSync\ViewSnapshot;
use App\Service\ViewSync\ViewSyncException;
use App\Service\ViewSync\ViewSyncRunner;
use Psr\Log\LoggerInterface;

/**
 * Copies the selected table copies and private views into same-named tables
 * in the forensic database (see ViewSyncRunner), so problems can be
 * investigated in complete, read-only data without loading the application
 * database with long queries. Each sync replaces a table with a complete copy
 * (ReportingTableWriter). Each copy is a processing task of type
 * forensic_database_sync, in the audit trail like every sync.
 *
 * The exporter reads through ForensicReader, which hashes identifiers with a
 * key made for that copy alone, so every copy of forensic_events_v1 has new
 * hashes and is always replaced. Nothing is written unless the database
 * allows it (ForensicDatabaseConnection::assertMarkAllowsSync()): every due
 * table fails with the reason otherwise.
 *
 * @extends ViewSyncRunner<ReportingTableWriter>
 */
class ForensicDatabaseSyncRunner extends ViewSyncRunner
{
    public const TASK_TYPE = 'forensic_database_sync';

    /** @param \Closure(): \DateTimeImmutable|null $clock */
    public function __construct(
        ForensicDatabaseSettings $settings,
        private readonly ForensicDatabaseConnection $database,
        ViewExporter $exporter,
        ProcessingTasks $tasks,
        LoggerInterface $logger,
        ?\Closure $clock = null,
    ) {
        parent::__construct($settings, $exporter, $tasks, $logger, $clock);
    }

    /**
     * Connects, proves the sync may replace tables, and checks that the
     * database may receive forensic copies, without touching the copies.
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
                $this->logger->error('The forensic database check failed.', ['exception' => $e]);
            }
            throw $e;
        }
    }

    protected function open(array $settings): ReportingTableWriter
    {
        $connection = $this->database->connection();
        $connection->getServerVersion();
        $this->database->assertMarkAllowsSync();

        return new ReportingTableWriter($connection);
    }

    /** @param ReportingTableWriter $destination */
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
        return $this->message($e, 'The forensic database could not be reached');
    }

    protected function copyFailure(string $view, \Throwable $e): string
    {
        return $this->message($e, 'The table could not be copied');
    }

    /**
     * A message that is safe to store and show: reporting database and view
     * errors carry their own; a database error names identifiers only, so its
     * first line is kept. Anything else is logged and summarized.
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
