<?php

declare(strict_types=1);

namespace App\Entities\TrafficClass;

/** The published range lists and how to download each one. */
interface RangeDownloads
{
    /** @return list<RangeSource> */
    public function sources(): array;

    /**
     * The ranges a source publishes today, as written in its list.
     *
     * @return list<string>
     *
     * @throws \RuntimeException when the list cannot be downloaded or read
     */
    public function download(RangeSource $source): array;
}
