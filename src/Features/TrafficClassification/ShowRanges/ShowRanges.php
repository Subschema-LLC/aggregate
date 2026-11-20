<?php

declare(strict_types=1);

namespace App\Features\TrafficClassification\ShowRanges;

use App\Entities\TrafficClass\RangeDownloads;
use App\Entities\TrafficClass\RangeStore;

/** Lists each published range source and what is saved for it. */
final class ShowRanges
{
    public function __construct(
        private readonly RangeDownloads $downloads,
        private readonly RangeStore $store,
    ) {
    }

    /**
     * @return list<array{source: string, operator: string, cloud: bool, class: ?string,
     *     count: int, fetched_at: ?\DateTimeImmutable}>
     */
    public function show(): array
    {
        $saved = $this->store->saved();
        $rows = [];
        foreach ($this->downloads->sources() as $source) {
            $rows[] = [
                'source' => $source->id,
                'operator' => $source->operator,
                'cloud' => $source->cloud,
                'class' => $source->class?->value,
                'count' => $saved[$source->id]['count'] ?? 0,
                'fetched_at' => $saved[$source->id]['fetched_at'] ?? null,
            ];
        }

        return $rows;
    }
}
