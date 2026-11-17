<?php

declare(strict_types=1);

namespace App\Integrations\Alerts;

use App\Entities\AlertRule\EventNames;
use App\Service\PrivacySanitizer;

/** Event names an alert rule may filter on are the ones collection accepts. */
final class PrivacySanitizerEventNames implements EventNames
{
    public function isSafe(string $eventName): bool
    {
        return PrivacySanitizer::isSafeEventName($eventName);
    }
}
