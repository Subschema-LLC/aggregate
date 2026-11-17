<?php

declare(strict_types=1);

namespace App\Entities\Incident;

/** The incident that started at $since closed at the window ending $windowEnd. */
final class AlertRecovered
{
    public function __construct(
        public readonly string $ruleId,
        public readonly string $websiteToken,
        public readonly string $windowEnd,
        public readonly string $since,
    ) {
    }

    /** @return array{type: 'recovered', window_end: string, since: string} */
    public function toArray(): array
    {
        return ['type' => 'recovered', 'window_end' => $this->windowEnd, 'since' => $this->since];
    }
}
