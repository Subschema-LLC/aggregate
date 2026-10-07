<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Operations\AuditTrail;
use App\Service\Operations\ProcessingTasks;
use App\Service\Operations\TaskTrigger;
use Psr\Clock\ClockInterface;

/**
 * Runs the data lifecycle policy: archives and deletes analytics data when
 * those are turned on, and purges processing tasks and audit trail entries
 * older than their retention periods. Each run that changes data is itself a
 * processing task (analytics_maintenance), so it ends in the audit trail.
 */
final class AnalyticsMaintenanceRunner
{
    public const TASK_TYPE = 'analytics_maintenance';
    private const UTC = 'UTC';

    public function __construct(
        private readonly AnalyticsDataLifecyclePolicy $policy,
        private readonly AnalyticsArchiveService $archive,
        private readonly AnalyticsRetentionService $retention,
        private readonly AnalyticsMaintenanceLease $lease,
        private readonly ClockInterface $clock,
        private readonly ProcessingTasks $tasks,
        private readonly AuditTrail $audit,
    ) {
    }

    public function run(bool $dryRun = false): AnalyticsMaintenanceResult
    {
        // Resolve every setting before acquiring a lease or changing data. A
        // malformed policy must always fail closed, especially for deletion.
        $settings = $this->policy->getPolicy();
        $archivingEnabled = $settings['archiving_enabled'];
        $retentionEnabled = $settings['retention_enabled'];
        $taskDays = $settings['processing_tasks_retention_days'];
        $auditDays = $settings['audit_trail_retention_days'];
        $recordPurgeEnabled = $taskDays > 0 || $auditDays > 0;

        if (!$archivingEnabled && !$retentionEnabled && !$recordPurgeEnabled) {
            return new AnalyticsMaintenanceResult(
                $dryRun,
                false,
                false,
                0,
                0,
                0,
            );
        }

        $now = $this->now();
        $archiveCutoff = $this->calendarCutoff($now, $settings['archive_after_days']);
        $anonymousCutoff = $this->calendarCutoff($now, $settings['anonymous_retention_days']);
        $enhancedCutoff = $this->calendarCutoff($now, $settings['enhanced_retention_days']);
        $archiveRetentionCutoff = $this->calendarCutoff($now, $settings['archive_retention_days']);
        $taskCutoff = $taskDays > 0 ? $this->calendarCutoff($now, $taskDays) : null;
        $auditCutoff = $auditDays > 0 ? $this->calendarCutoff($now, $auditDays) : null;

        if ($dryRun) {
            return new AnalyticsMaintenanceResult(
                true,
                $archivingEnabled,
                $retentionEnabled,
                $archivingEnabled ? $this->archive->countBefore($archiveCutoff) : 0,
                $retentionEnabled
                    ? $this->retention->countRawEventsBefore($anonymousCutoff, $enhancedCutoff)
                    : 0,
                $retentionEnabled
                    ? $this->retention->countArchiveCellsBefore($archiveRetentionCutoff)
                    : 0,
                $recordPurgeEnabled,
                $taskCutoff !== null ? $this->tasks->countBefore($taskCutoff) : 0,
                $auditCutoff !== null ? $this->audit->countBefore($auditCutoff) : 0,
            );
        }

        $this->lease->acquire($now);
        $failure = null;
        $task = null;

        try {
            $task = $this->tasks->start(self::TASK_TYPE, null, TaskTrigger::schedule(), $now);
            if ($archivingEnabled || $retentionEnabled) {
                $this->archive->assertSchemaReady();
            }
            $heartbeat = function (): void {
                $this->lease->refresh($this->now());
            };
            $archivedEvents = $archivingEnabled
                ? $this->archive->archiveBefore(
                    $archiveCutoff,
                    $now,
                    $settings['batch_size'],
                    $heartbeat,
                )
                : 0;

            // Deletion cannot run if archiving threw. When archiving is enabled,
            // the archived_at predicate also protects old events that arrive
            // from an async queue after this run's archive high-water mark.
            $deletedRawEvents = $retentionEnabled
                ? $this->retention->deleteRawEventsBefore(
                    $anonymousCutoff,
                    $enhancedCutoff,
                    $settings['batch_size'],
                    $archivingEnabled,
                    $heartbeat,
                )
                : 0;
            $deletedArchiveCells = $retentionEnabled
                ? $this->retention->deleteArchiveCellsBefore(
                    $archiveRetentionCutoff,
                    $settings['batch_size'],
                    $heartbeat,
                )
                : 0;

            $heartbeat();
            $purgedTasks = $taskCutoff !== null ? $this->tasks->purgeBefore($taskCutoff, $settings['batch_size']) : 0;
            $heartbeat();
            $purgedAudit = $auditCutoff !== null ? $this->audit->purgeBefore($auditCutoff, $settings['batch_size']) : 0;

            $result = new AnalyticsMaintenanceResult(
                false,
                $archivingEnabled,
                $retentionEnabled,
                $archivedEvents,
                $deletedRawEvents,
                $deletedArchiveCells,
                $recordPurgeEnabled,
                $purgedTasks,
                $purgedAudit,
            );
            $this->tasks->succeed(
                (int) $task,
                $this->now(),
                $archivedEvents + $deletedRawEvents + $deletedArchiveCells + $purgedTasks + $purgedAudit,
                self::summary($result, $archiveCutoff, $taskCutoff, $auditCutoff),
            );

            return $result;
        } catch (\Throwable $e) {
            $failure = $e;
            if ($task !== null) {
                try {
                    $this->tasks->fail($task, $this->now(), self::failureDetails($e));
                } catch (\Throwable) {
                    // The original failure is the one to report.
                }
            }

            throw $e;
        } finally {
            try {
                $this->lease->release($this->now());
            } catch (\Throwable $releaseFailure) {
                // Preserve the actionable archive/retention failure. A failed
                // release remains bounded by the lease expiration.
                if ($failure === null) {
                    throw $releaseFailure;
                }
            }
        }
    }

    /** What the run changed, for its processing task and audit entry. */
    private static function summary(AnalyticsMaintenanceResult $result, \DateTimeImmutable $archiveCutoff, ?\DateTimeImmutable $taskCutoff, ?\DateTimeImmutable $auditCutoff): string
    {
        $count = static fn (int $number, string $one, string $many): string => $number.' '.($number === 1 ? $one : $many);
        $parts = [];
        if ($result->archivingEnabled) {
            $parts[] = sprintf('archived %s from before %s', $count($result->archivedEvents, 'raw event', 'raw events'), $archiveCutoff->format('Y-m-d'));
        }
        if ($result->retentionEnabled) {
            $parts[] = sprintf('deleted %s and %s', $count($result->deletedRawEvents, 'raw event', 'raw events'), $count($result->deletedArchiveCells, 'archive cell', 'archive cells'));
        }
        if ($taskCutoff !== null) {
            $parts[] = sprintf('purged %s from before %s', $count($result->purgedProcessingTasks, 'processing task', 'processing tasks'), $taskCutoff->format('Y-m-d'));
        }
        if ($auditCutoff !== null) {
            $parts[] = sprintf('purged %s from before %s', $count($result->purgedAuditEntries, 'audit trail entry', 'audit trail entries'), $auditCutoff->format('Y-m-d'));
        }

        return ucfirst(implode('; ', $parts)).'.';
    }

    /**
     * A failure's details for the task and audit trail. Database errors can
     * quote stored values, so only their type is kept; the log has the rest.
     */
    private static function failureDetails(\Throwable $e): string
    {
        $type = (new \ReflectionClass($e))->getShortName();
        if ($e instanceof \Doctrine\DBAL\Exception || !($e instanceof \RuntimeException || $e instanceof \LogicException)) {
            return 'Maintenance stopped with a '.$type.'; see the application log. Steps that finished before it are kept.';
        }

        return 'Maintenance stopped: '.$e->getMessage().' Steps that finished before it are kept.';
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now())
            ->setTimezone(new \DateTimeZone(self::UTC));
    }

    private function calendarCutoff(\DateTimeImmutable $now, int $days): \DateTimeImmutable
    {
        return $now
            ->setTime(0, 0, 0)
            ->sub(new \DateInterval(sprintf('P%dD', $days)));
    }
}
