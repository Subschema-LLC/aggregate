<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Clock\ClockInterface;

final class AnalyticsMaintenanceRunner
{
    private const UTC = 'UTC';

    public function __construct(
        private readonly AnalyticsDataLifecyclePolicy $policy,
        private readonly AnalyticsArchiveService $archive,
        private readonly AnalyticsRetentionService $retention,
        private readonly AnalyticsMaintenanceLease $lease,
        private readonly ClockInterface $clock,
    ) {
    }

    public function run(bool $dryRun = false): AnalyticsMaintenanceResult
    {
        // Resolve every setting before acquiring a lease or changing data. A
        // malformed policy must always fail closed, especially for deletion.
        $settings = $this->policy->getPolicy();
        $archivingEnabled = $settings['archiving_enabled'];
        $retentionEnabled = $settings['retention_enabled'];

        if (!$archivingEnabled && !$retentionEnabled) {
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
            );
        }

        $this->lease->acquire($now);
        $failure = null;

        try {
            $this->archive->assertSchemaReady();
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

            return new AnalyticsMaintenanceResult(
                false,
                $archivingEnabled,
                $retentionEnabled,
                $archivedEvents,
                $deletedRawEvents,
                $deletedArchiveCells,
            );
        } catch (\Throwable $e) {
            $failure = $e;

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
