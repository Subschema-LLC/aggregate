<?php

declare(strict_types=1);

namespace App\Service\Scheduler;

use App\Service\BigQuery\BigQuerySyncRunner;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/** BigQuery sync: each selected view when its interval has passed (see ViewSyncJob). */
final class BigQuerySyncJob extends ViewSyncJob
{
    public function __construct(BigQuerySyncRunner $runner, ClockInterface $clock, LoggerInterface $logger)
    {
        parent::__construct($runner, $clock, $logger);
    }
}
