<?php

declare(strict_types=1);

namespace App\Service\Scheduler;

use App\Service\ReportingDatabase\ReportingDatabaseSyncRunner;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/** Reporting database sync: each selected view when its interval has passed (see ViewSyncJob). */
final class ReportingDatabaseSyncJob extends ViewSyncJob
{
    public function __construct(ReportingDatabaseSyncRunner $runner, ClockInterface $clock, LoggerInterface $logger)
    {
        parent::__construct($runner, $clock, $logger);
    }
}
