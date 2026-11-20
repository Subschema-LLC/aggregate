<?php

declare(strict_types=1);

namespace App\Entities\TrafficClass;

/**
 * What one request shows about its sender, gathered while the request is
 * handled and discarded with it. Nothing here holds the address or the full
 * User-Agent.
 */
final class Signals
{
    /**
     * @param array{token: string, operator: string, class: TrafficClass}|null $knownAgent
     * @param list<RangeSource> $ranges published lists that hold the address
     */
    public function __construct(
        public readonly ?array $knownAgent = null,
        public readonly bool $botUserAgent = false,
        public readonly bool $automationReported = false,
        public readonly array $ranges = [],
    ) {
    }

    public function botRange(): ?RangeSource
    {
        foreach ($this->ranges as $source) {
            if (!$source->cloud) {
                return $source;
            }
        }

        return null;
    }

    public function cloudRange(): ?RangeSource
    {
        foreach ($this->ranges as $source) {
            if ($source->cloud) {
                return $source;
            }
        }

        return null;
    }
}
