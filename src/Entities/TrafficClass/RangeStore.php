<?php

declare(strict_types=1);

namespace App\Entities\TrafficClass;

/** Where downloaded range lists are kept between refreshes. */
interface RangeStore
{
    /** Replaces the saved list of a source in one write. */
    public function save(
        RangeSource $source,
        IpRanges $ranges,
        \DateTimeImmutable $fetchedAt,
    ): void;

    /**
     * What is saved for each source that has a list.
     *
     * @return array<string, array{count: int, fetched_at: \DateTimeImmutable}>
     */
    public function saved(): array;
}
