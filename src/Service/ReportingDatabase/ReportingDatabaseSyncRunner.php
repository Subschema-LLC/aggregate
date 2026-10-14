<?php

declare(strict_types=1);

namespace App\Service\ReportingDatabase;

use App\Service\BigQuery\BigQueryException;
use App\Service\BigQuery\BigQueryViewCatalog;
use App\Service\BigQuery\BigQueryViewExporter;
use App\Service\Operations\ProcessingTasks;
use App\Service\Operations\TaskTrigger;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Exception\DriverException;
use Psr\Log\LoggerInterface;

/**
 * Copies the selected reporting views into same-named tables in the reporting
 * database, so BI tools can read them without access to the application
 * database. Each sync replaces a table with a complete copy of its view (see
 * ReportingTableWriter). app:reporting-database:sync runs this every few
 * minutes; a view syncs when its interval has passed (or with --force). Each
 * view sync is a processing task (type reporting_database_sync, subject =
 * view) that holds the view's lock while it runs and ends in the audit trail.
 */
class ReportingDatabaseSyncRunner
{
    public const TASK_TYPE = 'reporting_database_sync';
    /** A sync still running after this long is treated as abandoned. */
    public const LEASE_SECONDS = 7200;
    /** A scheduled run every five minutes lands within this margin of the interval. */
    private const SCHEDULE_SLACK_MINUTES = 5;
    private const RETRY_AFTER_FAILURE_MINUTES = 15;
    private const CHECK_TABLE = 'aggregate_sync_check';

    private \Closure $clock;

    /** @param \Closure(): \DateTimeImmutable|null $clock */
    public function __construct(
        private readonly ReportingDatabaseSettings $settings,
        private readonly ReportingDatabaseConnection $database,
        private readonly BigQueryViewExporter $exporter,
        private readonly ProcessingTasks $tasks,
        private readonly LoggerInterface $logger,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /**
     * @param list<string>|null              $only     Views to sync now (they must be selected)
     * @param (callable(string): void)|null  $progress
     * @param TaskTrigger|null               $trigger  Recorded with each view's task; by default
     *                                                 "schedule", or "command" with --force or views
     *
     * @return array{enabled: bool, results: list<array{view: string, status: string, rows: ?int, message: string}>}
     *
     * @throws \InvalidArgumentException when the settings are invalid
     */
    public function run(bool $force = false, ?array $only = null, ?callable $progress = null, ?TaskTrigger $trigger = null): array
    {
        $trigger ??= $force || $only !== null ? TaskTrigger::command() : TaskTrigger::schedule();
        $settings = $this->settings->toArray();
        if (!$settings[ReportingDatabaseSettings::KEY_ENABLED]) {
            return ['enabled' => false, 'results' => []];
        }
        $selected = ReportingDatabaseSettings::selectedViews($settings);
        foreach ($only ?? [] as $view) {
            if (!in_array($view, $selected, true)) {
                throw new \InvalidArgumentException($view.' is not selected for the reporting database.');
            }
        }
        $views = $only ?? $selected;
        $now = ($this->clock)();
        $latest = $this->tasks->latest(self::TASK_TYPE);
        $due = $force ? $views : array_values(array_filter($views, fn (string $view): bool => $this->isDue($latest[$view]['attempt'] ?? null, $settings[ReportingDatabaseSettings::KEY_INTERVAL], $now)));
        $results = [];
        foreach (array_diff($views, $due) as $view) {
            $results[] = ['view' => $view, 'status' => 'not_due', 'rows' => null, 'message' => 'Not due yet.'];
        }
        if ($due === []) {
            return ['enabled' => true, 'results' => $results];
        }

        $writer = null;
        $setupError = null;
        try {
            $connection = $this->database->connection();
            $connection->getServerVersion();
            $writer = new ReportingTableWriter($connection);
        } catch (\Throwable $e) {
            $setupError = $this->message($e, 'The reporting database could not be reached');
        }

        foreach ($due as $view) {
            $results[] = $this->syncView($view, $writer, $setupError, $progress, $trigger);
        }
        usort($results, static fn (array $a, array $b): int => array_search($a['view'], $views, true) <=> array_search($b['view'], $views, true));

        return ['enabled' => true, 'results' => $results];
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
        $location = $this->database->describe();
        try {
            $connection = $this->database->connection();
            $version = $connection->getServerVersion();
        } catch (\Throwable $e) {
            throw new ReportingDatabaseException($this->message($e, 'The reporting database could not be reached'), previous: $e);
        }
        try {
            $platform = $connection->getDatabasePlatform();
            $table = $platform->quoteSingleIdentifier(self::CHECK_TABLE);
            $connection->executeStatement('DROP TABLE IF EXISTS '.$table);
            $connection->executeStatement('CREATE TABLE '.$table.' (checked_at '.$platform->getDateTimeTypeDeclarationSQL([]).' NULL)');
            $connection->executeStatement('DROP TABLE '.$table);
        } catch (\Throwable $e) {
            throw new ReportingDatabaseException($this->message($e, 'Connected, but this database user cannot create and drop tables, which every sync does'), previous: $e);
        }
        $writer = new ReportingTableWriter($connection);
        $copied = array_values(array_filter(array_keys(BigQueryViewCatalog::APPROVED + BigQueryViewCatalog::PRIVATE), $writer->tableExists(...)));

        return [...$location, 'server_version' => $version, 'tables' => $copied];
    }

    /**
     * Status for the admin page and app:reporting-database:check, from each
     * view's latest processing task. The scheduler counts as late when sync is
     * on and no view sync has started for twice the interval (at least 30
     * minutes): every view is attempted at least once per interval.
     *
     * @param array<string, mixed> $settings
     *
     * @return array{last_run: ?\DateTimeImmutable, views: list<array{view: string, private: bool, description: string, status: string, started_at: ?\DateTimeImmutable, finished_at: ?\DateTimeImmutable, succeeded_at: ?\DateTimeImmutable, row_count: ?int, message: ?string, next_due: ?\DateTimeImmutable}>, scheduler_late: bool}
     */
    public function status(array $settings): array
    {
        $latest = $this->tasks->latest(self::TASK_TYPE);
        $now = ($this->clock)();
        $views = [];
        foreach (ReportingDatabaseSettings::selectedViews($settings) as $view) {
            $attempt = $latest[$view]['attempt'] ?? null;
            $success = $latest[$view]['success'] ?? null;
            $views[] = [
                'view' => $view,
                'private' => BigQueryViewCatalog::isPrivate($view),
                'description' => BigQueryViewCatalog::describe($view),
                'status' => $attempt['status'] ?? 'never',
                'started_at' => $attempt['started_at'] ?? null,
                'finished_at' => $attempt['finished_at'] ?? null,
                'succeeded_at' => $success['finished_at'] ?? null,
                'row_count' => $success['row_count'] ?? null,
                'message' => $attempt['details'] ?? null,
                'next_due' => $this->nextDue($attempt, $settings[ReportingDatabaseSettings::KEY_INTERVAL]),
            ];
        }
        $lastRun = null;
        foreach ($latest as $tasks) {
            if ($lastRun === null || $tasks['attempt']['started_at'] > $lastRun) {
                $lastRun = $tasks['attempt']['started_at'];
            }
        }
        $late = $settings[ReportingDatabaseSettings::KEY_ENABLED]
            && ($lastRun === null || $lastRun < $now->modify('-'.max(30, 2 * $settings[ReportingDatabaseSettings::KEY_INTERVAL]).' minutes'));

        return ['last_run' => $lastRun, 'views' => $views, 'scheduler_late' => $late];
    }

    /** @return array{view: string, status: string, rows: ?int, message: string} */
    private function syncView(string $view, ?ReportingTableWriter $writer, ?string $setupError, ?callable $progress, TaskTrigger $trigger): array
    {
        $task = $this->tasks->start(self::TASK_TYPE, $view, $trigger, ($this->clock)(), self::LEASE_SECONDS);
        if ($task === null) {
            return ['view' => $view, 'status' => 'busy', 'rows' => null, 'message' => 'Another sync of this view is running.'];
        }
        if ($progress !== null) {
            $progress($view);
        }
        try {
            if ($writer === null) {
                throw new ReportingDatabaseException((string) $setupError);
            }
            $rows = $this->copy($view, $writer);
            $message = sprintf('Replaced %s with %d row%s.', $view, $rows, $rows === 1 ? '' : 's');
            $this->tasks->succeed($task, ($this->clock)(), $rows, $message);

            return ['view' => $view, 'status' => 'synced', 'rows' => $rows, 'message' => $message];
        } catch (\Throwable $e) {
            $message = $this->message($e, 'The view could not be copied');
            $this->tasks->fail($task, ($this->clock)(), $message);

            return ['view' => $view, 'status' => 'failed', 'rows' => null, 'message' => $message];
        }
    }

    /**
     * Reads the view from the application database and writes it to the
     * reporting database. The view's rows are read while they are written;
     * leaving this method, normally or by an exception, ends that read before
     * the task is recorded on the application database.
     */
    private function copy(string $view, ReportingTableWriter $writer): int
    {
        $source = $this->exporter->read($view);

        return $writer->replace($view, $source['fields'], $source['rows']);
    }

    /**
     * A message that is safe to store and show. Reporting database and view
     * errors carry their own; a database error during setup or table changes
     * names identifiers only, so its first line is kept. Anything else is
     * logged and summarized.
     */
    private function message(\Throwable $e, string $context): string
    {
        if ($e instanceof ReportingDatabaseException || $e instanceof BigQueryException) {
            if ($e->getPrevious() !== null) {
                $this->logger->error($context.'.', ['exception' => $e]);
            }

            return $e->getMessage();
        }
        $this->logger->error($context.'.', ['exception' => $e]);
        if ($e instanceof DbalException) {
            $detail = trim((string) strtok($e->getMessage(), "\n"));
            if (strlen($detail) > 300) {
                $detail = substr($detail, 0, 297).'...';
            }
            $state = $e instanceof DriverException && $e->getSQLState() !== null ? ' (SQLSTATE '.$e->getSQLState().')' : '';

            return $context.$state.': '.$detail;
        }

        return $context.' ('.(new \ReflectionClass($e))->getShortName().'); see the application log.';
    }

    /** @param array{status: string, started_at: \DateTimeImmutable}|null $attempt */
    private function isDue(?array $attempt, int $interval, \DateTimeImmutable $now): bool
    {
        $next = $this->nextDue($attempt, $interval);

        return $next === null || $now >= $next->modify('-'.min(self::SCHEDULE_SLACK_MINUTES, intdiv($interval, 3)).' minutes');
    }

    /** @param array{status: string, started_at: \DateTimeImmutable}|null $attempt */
    private function nextDue(?array $attempt, int $interval): ?\DateTimeImmutable
    {
        if ($attempt === null) {
            return null;
        }
        $wait = $attempt['status'] === ProcessingTasks::FAILED ? min(self::RETRY_AFTER_FAILURE_MINUTES, $interval) : $interval;

        return $attempt['started_at']->modify('+'.$wait.' minutes');
    }
}
