<?php

declare(strict_types=1);

namespace App\Service\Scheduler;

use App\Service\ForensicDatabase\ForensicDatabaseSyncRunner;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/** Forensic database sync: each selected table and view when its interval has passed (see ViewSyncJob). */
final class ForensicDatabaseSyncJob extends ViewSyncJob
{
    public function __construct(ForensicDatabaseSyncRunner $runner, ClockInterface $clock, LoggerInterface $logger)
    {
        parent::__construct($runner, $clock, $logger);
    }
}
