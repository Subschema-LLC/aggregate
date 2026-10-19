<?php

declare(strict_types=1);

namespace App\Service\ViewSync;

use App\Service\Operations\BackgroundCommand;
use App\Service\Scheduler\SchedulerSetup;

/**
 * Starts a view sync command with --force from its admin page in a background
 * process, so a long copy does not hold a web request open. Its output goes
 * to a log file; results appear in the page's status table, and each view's
 * task records the administrator who asked for it.
 */
abstract class ViewSyncBackground
{
    private readonly string $command;
    private readonly string $logFile;

    public function __construct(
        private readonly BackgroundCommand $background,
        private readonly SchedulerSetup $scheduler,
        ViewSyncDefinition $sync,
    ) {
        $this->command = $sync->command;
        $this->logFile = $sync->logFile;
    }

    /** The crontab line that runs the scheduled jobs, this sync among them, every five minutes. */
    public function cronLine(): string
    {
        return $this->scheduler->cronLine();
    }

    /** Why a sync cannot start from the dashboard, or null when it can. */
    public function problem(): ?string
    {
        return $this->background->problem($this->command.' --force');
    }

    /** @throws \RuntimeException with a message for the administrator */
    public function start(string $requestedBy): void
    {
        $this->background->start($this->command, ['--force', '--requested-by='.$requestedBy], $this->logFile);
    }
}
