<?php

declare(strict_types=1);

namespace App\Service\Scheduler;

use App\Service\AnalyticsDataLifecyclePolicy;
use App\Service\AnalyticsMaintenanceAlreadyRunning;
use App\Service\AnalyticsMaintenanceRunner;
use App\Service\Operations\ProcessingTasks;
use App\Service\Operations\TaskTrigger;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Analytics maintenance once a day: archiving and retention when they are on,
 * and purging old task and audit records. A run counts as due 24 hours after
 * the last one started, whoever started it, so a separate daily cron line for
 * app:analytics:maintain keeps working alongside the scheduler.
 */
final class AnalyticsMaintenanceJob implements ScheduledJob
{
    public const INTERVAL_HOURS = 24;
    /** A run every five minutes lands within this margin of the due time. */
    private const SLACK_MINUTES = 10;

    public function __construct(
        private readonly AnalyticsMaintenanceRunner $runner,
        private readonly AnalyticsDataLifecyclePolicy $policy,
        private readonly ProcessingTasks $tasks,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function name(): string
    {
        return AnalyticsMaintenanceRunner::TASK_TYPE;
    }

    public function label(): string
    {
        return 'Analytics maintenance';
    }

    public function status(): array
    {
        $attempt = $this->tasks->latest(AnalyticsMaintenanceRunner::TASK_TYPE)['']['attempt'] ?? null;
        $status = [
            'enabled' => false,
            'schedule' => 'Daily',
            'last_run' => $attempt['started_at'] ?? null,
            'last_status' => $attempt['status'] ?? null,
            'next_due' => null,
            'problem' => null,
        ];
        try {
            $policy = $this->policy->getPolicy();
        } catch (\Throwable $e) {
            $status['problem'] = 'The data lifecycle settings are invalid'.($e instanceof \InvalidArgumentException ? ': '.$e->getMessage() : '; see the application log.');

            return $status;
        }
        $status['enabled'] = $policy['archiving_enabled'] || $policy['retention_enabled']
            || $policy['processing_tasks_retention_days'] > 0 || $policy['audit_trail_retention_days'] > 0
            || $policy['audit_trail_admin_retention_days'] > 0;
        if ($status['enabled']) {
            $status['next_due'] = $attempt === null ? $this->now() : $attempt['started_at']->modify('+'.self::INTERVAL_HOURS.' hours');
        }

        return $status;
    }

    public function runDue(TaskTrigger $trigger): array
    {
        $status = $this->status();
        if ($status['problem'] !== null) {
            return ['status' => 'failed', 'message' => $status['problem']];
        }
        if (!$status['enabled']) {
            return ['status' => 'off', 'message' => 'Archiving, retention and record purging are all turned off.'];
        }
        if ($status['next_due'] !== null && $this->now() < $status['next_due']->modify('-'.self::SLACK_MINUTES.' minutes')) {
            return ['status' => 'not_due', 'message' => 'Not due yet.'];
        }

        try {
            $result = $this->runner->run(false, $trigger);
        } catch (AnalyticsMaintenanceAlreadyRunning) {
            return ['status' => 'busy', 'message' => 'Another maintenance run is in progress.'];
        } catch (\Throwable $e) {
            $this->logger->error('Scheduled analytics maintenance failed.', ['exception' => $e]);

            return ['status' => 'failed', 'message' => 'Analytics maintenance failed ('.(new \ReflectionClass($e))->getShortName().'); see the application log.'];
        }

        return ['status' => 'ran', 'message' => sprintf(
            'Archived %d, deleted %d raw events and %d archive cells, purged %d task and %d audit records.',
            $result->archivedEvents,
            $result->deletedRawEvents,
            $result->deletedArchiveCells,
            $result->purgedProcessingTasks,
            $result->purgedAuditEntries,
        )];
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now())->setTimezone(new \DateTimeZone('UTC'));
    }
}
