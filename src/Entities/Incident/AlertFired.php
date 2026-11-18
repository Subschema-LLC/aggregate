<?php

declare(strict_types=1);

namespace App\Entities\Incident;

/** A rule's window broke for a website; the incident starts at the window end. */
final class AlertFired
{
    public function __construct(
        public readonly string $ruleId,
        public readonly string $websiteToken,
        public readonly string $since,
    ) {
    }

    /** @return array{type: 'firing', window_end: string, since: string} */
    public function toArray(): array
    {
        return ['type' => 'firing', 'window_end' => $this->since, 'since' => $this->since];
    }
}
