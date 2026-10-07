<?php

declare(strict_types=1);

namespace App\Service;

final readonly class AnalyticsMaintenanceResult
{
    public function __construct(
        public bool $dryRun,
        public bool $archivingEnabled,
        public bool $retentionEnabled,
        public int $archivedEvents,
        public int $deletedRawEvents,
        public int $deletedArchiveCells,
        public bool $recordPurgeEnabled = false,
        public int $purgedProcessingTasks = 0,
        public int $purgedAuditEntries = 0,
    ) {
    }

    public function hasWork(): bool
    {
        return $this->archivingEnabled || $this->retentionEnabled || $this->recordPurgeEnabled;
    }
}
