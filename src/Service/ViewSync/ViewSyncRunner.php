<?php

declare(strict_types=1);

namespace App\Service\ViewSync;

use App\Service\Operations\ProcessingTasks;
use App\Service\Operations\TaskTrigger;
use Psr\Log\LoggerInterface;

/**
 * Copies the selected reporting views to a destination, BigQuery or the
 * reporting database, each copy replacing the previous one with the whole
 * view. The scheduler runs this every few minutes; a view syncs when its
 * interval has passed (or with --force). Each view sync is a processing task
 * (subject = view) that holds the view's lock while it runs and ends in the
 * audit trail.
 *
 * Each view is first written to a snapshot (ViewExporter), and its content
 * fingerprint is recorded with the task. A scheduled sync keeps the
 * destination's copy when the fingerprint is the one its last successful sync
 * recorded and the copy still has as many rows; --force and the dashboard's
 * "Sync now" always replace it.
 *
 * A destination opens its connection once per run (open), counts the rows of
 * a copy (copiedRows) and replaces a copy with a snapshot (replace). If it
 * cannot be opened, every due view fails with the reason.
 *
 * @template TDestination of object
 */
abstract class ViewSyncRunner
{
    /** A sync still running after this long is treated as abandoned. */
    public const LEASE_SECONDS = 7200;
    /** A scheduled run every five minutes lands within this margin of the interval. */
    private const SCHEDULE_SLACK_MINUTES = 5;
    private const RETRY_AFTER_FAILURE_MINUTES = 15;

    /** @var \Closure(): \DateTimeImmutable */
    protected readonly \Closure $clock;
    private readonly string $taskType;

    /**
     * @param ViewSyncSettings                    $settings the sync's settings, which also define it (ViewSyncSettings::definition())
     * @param \Closure(): \DateTimeImmutable|null $clock
     */
    public function __construct(
        protected readonly ViewSyncSettings $settings,
        protected readonly ViewExporter $exporter,
        protected readonly ProcessingTasks $tasks,
        protected readonly LoggerInterface $logger,
        ?\Closure $clock,
    ) {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->taskType = $settings::definition()->taskType;
    }

    /** The settings of this sync; their definition() names it. */
    public function settings(): ViewSyncSettings
    {
        return $this->settings;
    }

    /**
     * Connects to the destination for this run.
     *
     * @param array<string, mixed> $settings
     *
     * @return TDestination
     */
    abstract protected function open(array $settings): object;

    /**
     * Replaces the destination's copy of the view with the snapshot's rows.
     * Sets $externalId as soon as the destination assigns one, so a failure is
     * recorded with it.
     *
     * @param TDestination         $destination
     * @param array<string, mixed> $settings
     */
    abstract protected function replace(ViewSnapshot $snapshot, object $destination, array $settings, ?string &$externalId): void;

    /**
     * How many rows the destination's copy of the view has, or null when it
     * has none.
     *
     * @param TDestination         $destination
     * @param array<string, mixed> $settings
     */
    abstract protected function copiedRows(string $view, object $destination, array $settings): ?int;

    /**
     * The copy's name in messages, as in "Replaced … with 3 rows."
     *
     * @param TDestination         $destination
     * @param array<string, mixed> $settings
     */
    abstract protected function copyName(string $view, object $destination, array $settings): string;

    /** Why the destination could not be opened, safe to store and show. */
    abstract protected function setupFailure(\Throwable $e): string;

    /** Why a view could not be copied, safe to store and show. */
    abstract protected function copyFailure(string $view, \Throwable $e): string;

    /**
     * @param bool                          $force    Sync every view now, replacing even copies of unchanged views
     * @param list<string>|null             $only     Views to sync now (they must be selected)
     * @param (callable(string): void)|null $progress
     * @param TaskTrigger|null              $trigger  Recorded with each view's task; by default
     *                                                "schedule", or "command" with --force or views
     *
     * @return array{enabled: bool, results: list<array{view: string, status: string, rows: ?int, message: string}>}
     *
     * @throws \InvalidArgumentException when the settings are invalid
     */
    public function run(bool $force = false, ?array $only = null, ?callable $progress = null, ?TaskTrigger $trigger = null): array
    {
        $trigger ??= $force || $only !== null ? TaskTrigger::command() : TaskTrigger::schedule();
        $settings = $this->settings->toArray();
        if (!$this->settings::isEnabled($settings)) {
            return ['enabled' => false, 'results' => []];
        }
        $selected = $this->settings::selectedViews($settings);
        foreach ($only ?? [] as $view) {
            if (!in_array($view, $selected, true)) {
                throw new \InvalidArgumentException($view.' is not selected for '.$this->settings::definition()->selectedFor.'.');
            }
        }
        $views = $only ?? $selected;
        $interval = $this->settings::intervalMinutes($settings);
        $now = ($this->clock)();
        $latest = $this->tasks->latest($this->taskType);
        $due = $force ? $views : array_values(array_filter($views, fn (string $view): bool => $this->isDue($latest[$view]['attempt'] ?? null, $interval, $now)));
        $results = [];
        foreach (array_diff($views, $due) as $view) {
            $results[] = ['view' => $view, 'status' => 'not_due', 'rows' => null, 'message' => 'Not due yet.'];
        }
        if ($due === []) {
            return ['enabled' => true, 'results' => $results];
        }

        $destination = null;
        $setupError = '';
        try {
            $destination = $this->open($settings);
        } catch (\Throwable $e) {
            $setupError = $this->setupFailure($e);
        }

        foreach ($due as $view) {
            // A forced sync replaces every copy; a scheduled one may keep an unchanged copy.
            $keepIfUnchanged = $force ? null : ($latest[$view]['success']['fingerprint'] ?? null);
            $results[] = $this->syncView($view, $settings, $destination, $setupError, $progress, $trigger, $keepIfUnchanged);
        }
        usort($results, static fn (array $a, array $b): int => array_search($a['view'], $views, true) <=> array_search($b['view'], $views, true));

        return ['enabled' => true, 'results' => $results];
    }

    /**
     * Status for the admin page and the check command, from each view's
     * latest processing task. The scheduler counts as late when sync is on and
     * no view sync has started for twice the interval (at least 30 minutes):
     * every view is attempted at least once per interval.
     *
     * @param array<string, mixed> $settings
     *
     * @return array{last_run: ?\DateTimeImmutable, views: list<array<string, mixed>>, scheduler_late: bool}
     */
    public function status(array $settings): array
    {
        $latest = $this->tasks->latest($this->taskType);
        $now = ($this->clock)();
        $interval = $this->settings::intervalMinutes($settings);
        $views = [];
        foreach ($this->settings::selectedViews($settings) as $view) {
            $attempt = $latest[$view]['attempt'] ?? null;
            $success = $latest[$view]['success'] ?? null;
            $views[] = [
                'view' => $view,
                'private' => ViewCatalog::isPrivate($view),
                'description' => ViewCatalog::describe($view),
                'status' => $attempt['status'] ?? 'never',
                'started_at' => $attempt['started_at'] ?? null,
                'finished_at' => $attempt['finished_at'] ?? null,
                'succeeded_at' => $success['finished_at'] ?? null,
                'row_count' => $success['row_count'] ?? null,
                'message' => $attempt['details'] ?? null,
                ...$this->attemptDetails($attempt),
                'next_due' => $this->nextDue($attempt, $interval),
            ];
        }
        $lastRun = null;
        foreach ($latest as $tasks) {
            if ($lastRun === null || $tasks['attempt']['started_at'] > $lastRun) {
                $lastRun = $tasks['attempt']['started_at'];
            }
        }
        $late = $this->settings::isEnabled($settings)
            && ($lastRun === null || $lastRun < $now->modify('-'.max(30, 2 * $interval).' minutes'));

        return ['last_run' => $lastRun, 'views' => $views, 'scheduler_late' => $late];
    }

    /**
     * Destination-specific fields of a view's status, from its latest attempt.
     *
     * @param array<string, mixed>|null $attempt
     *
     * @return array<string, mixed>
     */
    protected function attemptDetails(?array $attempt): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $settings
     * @param TDestination|null    $destination     null when it could not be opened
     * @param string|null          $keepIfUnchanged the fingerprint of the last successful copy, when it may be kept
     *
     * @return array{view: string, status: string, rows: ?int, message: string}
     */
    private function syncView(string $view, array $settings, ?object $destination, string $setupError, ?callable $progress, TaskTrigger $trigger, ?string $keepIfUnchanged): array
    {
        $task = $this->tasks->start($this->taskType, $view, $trigger, ($this->clock)(), self::LEASE_SECONDS);
        if ($task === null) {
            return ['view' => $view, 'status' => 'busy', 'rows' => null, 'message' => 'Another sync of this view is running.'];
        }
        if ($progress !== null) {
            $progress($view);
        }
        if ($destination === null) {
            $this->tasks->fail($task, ($this->clock)(), $setupError);

            return ['view' => $view, 'status' => 'failed', 'rows' => null, 'message' => $setupError];
        }
        $externalId = null;
        $snapshot = null;
        try {
            // The view is read completely before the destination is written.
            $snapshot = $this->exporter->export($view, $this->settings::definition()->exportDirectory);
            $rows = sprintf('%d row%s', $snapshot->rows, $snapshot->rows === 1 ? '' : 's');
            if ($keepIfUnchanged !== null && hash_equals($keepIfUnchanged, $snapshot->fingerprint)
                && $this->copiedRows($view, $destination, $settings) === $snapshot->rows) {
                $status = 'unchanged';
                $message = 'Unchanged since the last sync: kept '.$this->copyName($view, $destination, $settings).' with '.$rows.'.';
            } else {
                $this->replace($snapshot, $destination, $settings, $externalId);
                $status = 'synced';
                $message = 'Replaced '.$this->copyName($view, $destination, $settings).' with '.$rows.'.';
            }
            $this->tasks->succeed($task, ($this->clock)(), $snapshot->rows, $message, $externalId, $snapshot->fingerprint);

            return ['view' => $view, 'status' => $status, 'rows' => $snapshot->rows, 'message' => $message];
        } catch (\Throwable $e) {
            $message = $this->copyFailure($view, $e);
            $this->tasks->fail($task, ($this->clock)(), $message, $externalId);

            return ['view' => $view, 'status' => 'failed', 'rows' => null, 'message' => $message];
        } finally {
            $snapshot?->delete();
        }
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
