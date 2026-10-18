<?php

declare(strict_types=1);

namespace App\Service\ReportingDatabase;

use App\Service\Operations\ProcessingTasks;
use App\Service\ViewSync\ViewReader;
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
 * @extends ViewSyncRunner<ReportingTableWriter>
 */
class ReportingDatabaseSyncRunner extends ViewSyncRunner
{
    public const TASK_TYPE = 'reporting_database_sync';

    /** @param \Closure(): \DateTimeImmutable|null $clock */
    public function __construct(
        ReportingDatabaseSettings $settings,
        private readonly ReportingDatabaseConnection $database,
        private readonly ViewReader $reader,
        ProcessingTasks $tasks,
        LoggerInterface $logger,
        ?\Closure $clock = null,
    ) {
        parent::__construct($settings, $tasks, $logger, $clock, self::TASK_TYPE, 'the reporting database');
    }

    /**
     * Connects, and creates and drops a small table to prove the sync may
     * replace tables, without touching the copied views.
     *
     * @return array{engine: string, host: ?string, port: ?int, database: string, server_version: string, tables: list<string>}
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
        $connection = $this->database->connection();
        $connection->getServerVersion();

        return new ReportingTableWriter($connection);
    }

    /**
     * Reads the view from the application database and writes it to the
     * reporting database. The view's rows are read while they are written;
     * leaving this method, normally or by an exception, ends that read before
     * the task is recorded on the application database.
     *
     * @param ReportingTableWriter $destination
     */
    protected function copy(string $view, object $destination, array $settings, ?string &$externalId): array
    {
        $source = $this->reader->read($view);
        $rows = $destination->replace($view, $source['fields'], $source['rows']);

        return ['rows' => $rows, 'message' => sprintf('Replaced %s with %d row%s.', $view, $rows, $rows === 1 ? '' : 's')];
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
