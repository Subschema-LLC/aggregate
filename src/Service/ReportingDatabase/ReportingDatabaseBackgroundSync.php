<?php

declare(strict_types=1);

namespace App\Service\ReportingDatabase;

use App\Service\Operations\BackgroundCommand;
use App\Service\Scheduler\SchedulerSetup;
use App\Service\ViewSync\ViewSyncBackground;

/** Starts app:reporting-database:sync --force from the admin page; output goes to var/reporting-database/last-run.log. */
class ReportingDatabaseBackgroundSync extends ViewSyncBackground
{
    public const COMMAND = 'app:reporting-database:sync';

    public function __construct(BackgroundCommand $background, SchedulerSetup $scheduler)
    {
        parent::__construct($background, $scheduler, ReportingDatabaseSettings::definition());
    }
}
