<?php

declare(strict_types=1);

namespace App\Service\Scheduler;

use App\Service\ReportingDatabase\ReportingDatabaseSettings;
use App\Service\ReportingDatabase\ReportingDatabaseSyncRunner;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/** Reporting database sync: each selected view when its interval has passed. */
final class ReportingDatabaseSyncJob extends ViewSyncJob
{
    public function __construct(ReportingDatabaseSyncRunner $runner, ReportingDatabaseSettings $settings, ClockInterface $clock, LoggerInterface $logger)
    {
        parent::__construct($runner, $settings, $clock, $logger,
            sync: 'The reporting database sync',
            settingsName: 'The reporting database settings',
            failureLog: 'Scheduled reporting database sync failed.',
        );
    }

    public function name(): string
    {
        return ReportingDatabaseSyncRunner::TASK_TYPE;
    }

    public function label(): string
    {
        return 'Reporting database sync';
    }
}
