<?php

declare(strict_types=1);

namespace App\Integrations\Alerts;

use App\Entities\AlertRule\AlertRule;
use App\Entities\AlertRule\WindowBreaches;
use App\Repository\EventRepository;
use App\Service\Operations\ProcessingTasks;

/**
 * Counts a rule's window [hourEnd - window, hourEnd) in the events table or in
 * failed tracking tasks. Each count stops once it can decide, so a check never
 * reads more than the rule's threshold plus one rows for a website.
 */
final class EventCountWindowBreaches implements WindowBreaches
{
    public function __construct(
        private readonly EventRepository $events,
        private readonly ProcessingTasks $tasks,
    ) {
    }

    public function isBreached(
        array $rule,
        string $websiteToken,
        \DateTimeImmutable $hourEnd,
    ): bool
    {
        $windowHours = AlertRule::windowHours($rule['window']);
        $from = $hourEnd->modify('-'.$windowHours.' hours');

        if ($rule['source'] === AlertRule::SOURCE_TRACKING_FAILURES) {
            $cap = $rule['threshold'] + 1;
            $count = $this->tasks->countTrackingFailuresUpTo($websiteToken, $from, $hourEnd, $cap);

            return $count > $rule['threshold'];
        }

        $cap = $rule['condition'] === AlertRule::CONDITION_NO_EVENTS ? 1 : $rule['threshold'];
        $count = $this->events->countEventsUpTo(
            $websiteToken,
            $from,
            $hourEnd,
            $cap,
            $rule['privacy_mode'],
            $rule['event_name'] !== '' ? $rule['event_name'] : null,
        );

        return $count < $cap;
    }
}
