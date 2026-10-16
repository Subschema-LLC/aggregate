<?php

declare(strict_types=1);

namespace App\Service\ReportingDatabase;

use App\Service\Operations\BackgroundCommand;
use App\Service\Scheduler\SchedulerSetup;

/**
 * Starts app:reporting-database:sync --force from the admin page in a
 * background process, so copying large views does not hold a web request
 * open. Its output goes to var/reporting-database/last-run.log; results
 * appear in the status table, and each view's task records the administrator
 * who asked for it.
 */
class ReportingDatabaseBackgroundSync
{
    public const COMMAND = 'app:reporting-database:sync';

    public function __construct(
        private readonly BackgroundCommand $background,
        private readonly SchedulerSetup $scheduler,
    ) {
    }

    /** The crontab line that runs the scheduled jobs, this sync among them, every five minutes. */
    public function cronLine(): string
    {
        return $this->scheduler->cronLine();
    }

    /** Why a sync cannot start from the dashboard, or null when it can. */
    public function problem(): ?string
    {
        return $this->background->problem(self::COMMAND.' --force');
    }

    /** @throws \RuntimeException with a message for the administrator */
    public function start(string $requestedBy): void
    {
        $this->background->start(self::COMMAND, ['--force', '--requested-by='.$requestedBy], 'var/reporting-database/last-run.log');
    }
}
