<?php

declare(strict_types=1);

namespace App\Service\BigQuery;

use App\Service\Operations\BackgroundCommand;
use App\Service\Scheduler\SchedulerSetup;
use App\Service\ViewSync\ViewSyncBackground;

/** Starts app:bigquery:sync --force from the admin page; output goes to var/bigquery/last-run.log. */
class BigQueryBackgroundSync extends ViewSyncBackground
{
    public const COMMAND = 'app:bigquery:sync';

    public function __construct(BackgroundCommand $background, SchedulerSetup $scheduler)
    {
        parent::__construct($background, $scheduler, self::COMMAND, 'var/bigquery/last-run.log');
    }
}
