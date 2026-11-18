<?php

declare(strict_types=1);

namespace App\Features\Alerts\DeliverAlert;

use App\Entities\Destination\AlertChannel;

/**
 * One alert offered to its destinations at one hourly check.
 *
 * @phpstan-import-type AlertPayload from AlertChannel
 */
final class Attempt
{
    /** @param AlertPayload $payload */
    public function __construct(
        public readonly array $payload,
        public readonly string $conditionDescription,
        public readonly string $since,
        public readonly \DateTimeImmutable $windowEnd,
        public readonly string $installation,
        public readonly \DateTimeImmutable $completedHour,
        public readonly bool $retryable,
    ) {
    }
}
