<?php

declare(strict_types=1);

namespace App\Features\DeliverAlert;

use App\Entities\AlertRule\AlertRule;
use App\Entities\AlertRule\RuleDescription;
use App\Entities\Destination\AlertChannel;
use App\Entities\Destination\Destination;
use App\Entities\Incident\AlertFired;
use App\Entities\Incident\AlertRecovered;
use App\Entities\Incident\DeliveryLog;
use App\Entities\Incident\UtcHour;

/**
 * Reacts to AlertFired and AlertRecovered: sends the alert to each of the
 * rule's destinations that does not have it yet. Running it twice for the
 * same alert sends nothing the second time, which is what lets the hourly
 * check offer every current alert again until it is delivered.
 *
 * @phpstan-import-type RuleArray from AlertRule
 * @phpstan-import-type DestinationArray from Destination
 *
 * @phpstan-type Counts array{sent: int, suppressed: int, failed: int}
 */
final class DeliverAlert
{
    /** Hours after its window ends that an undelivered alert is still retried, once an hour. */
    public const RETRY_HOURS = 24;

    private readonly DeliverToDestination $destination;

    public function __construct(AlertChannel $channel, DeliveryLog $log)
    {
        $this->destination = new DeliverToDestination($channel, $log);
    }

    /**
     * @param RuleArray                                           $rule
     * @param array{token: string, name: string, domain: string} $site
     * @param array<string, DestinationArray>                     $destinations
     * @param bool $retryable false for an alert offered once only (a closing recovery)
     * @param Counts $counts                                  added to as each destination is done
     */
    public function deliver(
        AlertFired|AlertRecovered $alert,
        array $rule,
        array $site,
        array $destinations,
        string $installation,
        \DateTimeImmutable $completedHour,
        bool $retryable,
        array &$counts,
    ): void {
        $message = $alert->toArray();
        $windowEnd = UtcHour::parse($message['window_end']);
        if ($windowEnd === null) {
            return;
        }
        $payload = PayloadV1::build(
            $message['type'],
            $rule,
            $site,
            $installation,
            $windowEnd,
            $message['since'],
        );
        $description = RuleDescription::condition($rule);
        $attempt = new Attempt(
            $payload,
            $description,
            $message['since'],
            $windowEnd,
            $installation,
            $completedHour,
            $retryable,
        );

        foreach ($rule['destinations'] as $destinationId) {
            $destination = $destinations[$destinationId] ?? null;
            if ($destination !== null) {
                $outcome = $this->destination->deliver($destination, $site['token'], $attempt);
                if ($outcome !== null) {
                    ++$counts[$outcome];
                }
            }
        }
    }
}
