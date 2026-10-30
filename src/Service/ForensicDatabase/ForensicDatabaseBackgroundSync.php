<?php

declare(strict_types=1);

namespace App\Service\ForensicDatabase;

use App\Service\Operations\BackgroundCommand;
use App\Service\Scheduler\SchedulerSetup;
use App\Service\ViewSync\ViewSyncBackground;

/** Starts app:forensic-database:sync --force from the admin page; output goes to var/forensic-database/last-run.log. */
class ForensicDatabaseBackgroundSync extends ViewSyncBackground
{
    public const COMMAND = 'app:forensic-database:sync';

    public function __construct(BackgroundCommand $background, SchedulerSetup $scheduler)
    {
        parent::__construct($background, $scheduler, ForensicDatabaseSettings::definition());
    }
}
