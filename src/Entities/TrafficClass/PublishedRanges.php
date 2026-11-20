<?php

declare(strict_types=1);

namespace App\Entities\TrafficClass;

/** The saved published range lists, asked which of them hold an address. */
interface PublishedRanges
{
    /** @return list<RangeSource> every source whose saved ranges contain the address */
    public function sourcesContaining(string $ip): array;
}
