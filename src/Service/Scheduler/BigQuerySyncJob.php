<?php

declare(strict_types=1);

namespace App\Service\Scheduler;

use App\Service\BigQuery\BigQuerySettings;
use App\Service\BigQuery\BigQuerySyncRunner;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/** BigQuery sync: each selected view when its interval has passed. */
final class BigQuerySyncJob extends ViewSyncJob
{
    public function __construct(BigQuerySyncRunner $runner, BigQuerySettings $settings, ClockInterface $clock, LoggerInterface $logger)
    {
        parent::__construct($runner, $settings, $clock, $logger,
            sync: 'BigQuery sync',
            settingsName: 'The BigQuery settings',
            failureLog: 'Scheduled BigQuery sync failed.',
        );
    }

    public function name(): string
    {
        return BigQuerySyncRunner::TASK_TYPE;
    }

    public function label(): string
    {
        return 'BigQuery sync';
    }
}
