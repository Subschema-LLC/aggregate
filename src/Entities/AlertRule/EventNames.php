<?php

declare(strict_types=1);

namespace App\Entities\AlertRule;

/** Whether an event name is one collection would accept, so a rule can filter on it. */
interface EventNames
{
    public function isSafe(string $eventName): bool;
}
