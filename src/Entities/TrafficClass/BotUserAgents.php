<?php

declare(strict_types=1);

namespace App\Entities\TrafficClass;

/**
 * Whether a User-Agent belongs to a crawler, monitor or automation tool, by
 * the maintained list, common bot words and the operator's own phrases.
 */
interface BotUserAgents
{
    public function isBot(string $userAgent): bool;
}
