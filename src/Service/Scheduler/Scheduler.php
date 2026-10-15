<?php

declare(strict_types=1);

namespace App\Service\Scheduler;

use App\Service\Operations\TaskTrigger;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Runs every background job that is due: the one command a server's
 * scheduler (cron, a Plesk scheduled task, a systemd timer) starts every five
 * minutes. Jobs run one after another; a failing job does not stop the next.
 * A lock keeps a second run from starting while one is still busy, for
 * example with a long sync.
 *
 * Each run is noted in var/scheduler/last-run.json, apart from the jobs' own
 * processing tasks: runs started by the server's scheduler and runs started
 * by hand separately, so the admin page can tell whether the scheduled task
 * itself works. The file holds times and results only.
 *
 * @phpstan-type RunRecord array{started_at: \DateTimeImmutable, finished_at: \DateTimeImmutable, trigger: string, results: list<array{job: string, label: string, status: string, message: string}>}
 */
class Scheduler
{
    /** The server's scheduler counts as late after this long without a run. */
    public const LATE_AFTER_MINUTES = 30;
    public const DIRECTORY = 'var/scheduler';
    private const RECORD_FILE = 'last-run.json';
    private const LOCK_FILE = 'run.lock';

    /** @var list<ScheduledJob> */
    private readonly array $jobs;

    /** The jobs run in this order; see config/services.yaml. */
    public function __construct(
        ScheduledJob $maintenance,
        ScheduledJob $bigQuery,
        ScheduledJob $reportingDatabase,
        private readonly string $projectDir,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
        $this->jobs = [$maintenance, $bigQuery, $reportingDatabase];
    }

    /** @return list<ScheduledJob> */
    public function jobs(): array
    {
        return $this->jobs;
    }

    /**
     * Runs every job that is due.
     *
     * @param (callable(string): void)|null $progress Called with each job's label before it runs
     *
     * @return array{ran: bool, started_at: \DateTimeImmutable, finished_at: \DateTimeImmutable, trigger: string, results: list<array{job: string, label: string, status: string, message: string}>}
     */
    public function run(TaskTrigger $trigger, ?callable $progress = null): array
    {
        $started = $this->now();
        $lock = $this->lock();
        if ($lock === null) {
            return ['ran' => false, 'started_at' => $started, 'finished_at' => $this->now(), 'trigger' => $trigger->source, 'results' => []];
        }

        try {
            $results = [];
            foreach ($this->jobs as $job) {
                if ($progress !== null) {
                    $progress($job->label());
                }
                try {
                    $result = $job->runDue($trigger);
                } catch (\Throwable $e) {
                    $this->logger->error('A scheduled job failed.', ['job' => $job->name(), 'exception' => $e]);
                    $result = ['status' => 'failed', 'message' => 'The job failed ('.(new \ReflectionClass($e))->getShortName().'); see the application log.'];
                }
                $results[] = ['job' => $job->name(), 'label' => $job->label()] + $result;
            }
            $record = ['started_at' => $started, 'finished_at' => $this->now(), 'trigger' => $trigger->source, 'results' => $results];
            $this->record($record);

            return ['ran' => true] + $record;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Whether a run is in progress (its lock is held). */
    public function isRunning(): bool
    {
        try {
            $lock = $this->lock();
        } catch (\RuntimeException) {
            return false;
        }
        if ($lock === null) {
            return is_file($this->path(self::LOCK_FILE));
        }
        flock($lock, LOCK_UN);
        fclose($lock);

        return false;
    }

    /**
     * Whether the server's scheduler runs this command: "working" when it ran
     * within LATE_AFTER_MINUTES, "late" when it ran before that, "never" when
     * it has not run. Runs started by hand are reported separately.
     *
     * @return array{state: string, scheduled: ?RunRecord, manual: ?RunRecord}
     */
    public function health(): array
    {
        $records = $this->records();
        $scheduled = $records['scheduled'] ?? null;
        $state = match (true) {
            $scheduled === null => 'never',
            $scheduled['started_at'] >= $this->now()->modify('-'.self::LATE_AFTER_MINUTES.' minutes') => 'working',
            default => 'late',
        };

        return ['state' => $state, 'scheduled' => $scheduled, 'manual' => $records['manual'] ?? null];
    }

    /** @return resource|null the held lock, or null when another run holds it */
    private function lock()
    {
        $directory = $this->path('');
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException(self::DIRECTORY.' could not be created. Check that var is writable by the user that runs Aggregate.');
        }
        $lock = @fopen($this->path(self::LOCK_FILE), 'c');
        if ($lock === false) {
            throw new \RuntimeException(self::DIRECTORY.'/'.self::LOCK_FILE.' could not be opened. Check that var is writable by the user that runs Aggregate.');
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            return null;
        }

        return $lock;
    }

    /** @param RunRecord $record */
    private function record(array $record): void
    {
        $records = $this->records();
        $records[$record['trigger'] === TaskTrigger::SCHEDULE ? 'scheduled' : 'manual'] = $record;
        $json = [];
        foreach ($records as $kind => $item) {
            $json[$kind] = ['started_at' => $item['started_at']->format(\DATE_ATOM), 'finished_at' => $item['finished_at']->format(\DATE_ATOM)] + $item;
        }
        $file = $this->path(self::RECORD_FILE);
        $temporary = @tempnam(dirname($file), 'last-run');
        if ($temporary === false || @file_put_contents($temporary, json_encode($json, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n") === false || !@rename($temporary, $file)) {
            if (is_string($temporary)) {
                @unlink($temporary);
            }
            $this->logger->warning('The scheduler could not note its run in '.self::DIRECTORY.'.');
        }
    }

    /** @return array<string, RunRecord> */
    private function records(): array
    {
        $data = @file_get_contents($this->path(self::RECORD_FILE));
        $data = is_string($data) ? json_decode($data, true) : null;
        $records = [];
        foreach (is_array($data) ? $data : [] as $kind => $item) {
            if (!in_array($kind, ['scheduled', 'manual'], true) || !is_array($item) || !is_array($item['results'] ?? null)) {
                continue;
            }
            try {
                $records[$kind] = [
                    'started_at' => new \DateTimeImmutable((string) ($item['started_at'] ?? '')),
                    'finished_at' => new \DateTimeImmutable((string) ($item['finished_at'] ?? '')),
                    'trigger' => (string) ($item['trigger'] ?? ''),
                    'results' => array_values(array_filter($item['results'], static fn (mixed $result): bool => is_array($result) && isset($result['job'], $result['label'], $result['status'], $result['message']))),
                ];
            } catch (\Exception) {
                continue;
            }
        }

        return $records;
    }

    private function path(string $file): string
    {
        return $this->projectDir.'/'.self::DIRECTORY.($file === '' ? '' : '/'.$file);
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now())->setTimezone(new \DateTimeZone('UTC'));
    }
}
