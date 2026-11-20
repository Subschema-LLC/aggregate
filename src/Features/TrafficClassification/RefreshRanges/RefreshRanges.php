<?php

declare(strict_types=1);

namespace App\Features\TrafficClassification\RefreshRanges;

use App\Entities\TrafficClass\IpRanges;
use App\Entities\TrafficClass\RangeDownloads;
use App\Entities\TrafficClass\RangeStore;

/**
 * Downloads each published range list and saves it. A list that cannot be
 * downloaded, is empty or holds an entry that is not a range leaves the
 * previously saved list in place, so a vendor's format change or outage
 * never empties what classification uses.
 */
final class RefreshRanges
{
    public function __construct(
        private readonly RangeDownloads $downloads,
        private readonly RangeStore $store,
    ) {
    }

    /**
     * @param string|null $only a source id to refresh alone
     *
     * @return list<array{source: string, operator: string, cloud: bool, ok: bool,
     *     count: int, error: ?string}>
     */
    public function refresh(\DateTimeImmutable $now, ?string $only = null): array
    {
        $results = [];
        foreach ($this->downloads->sources() as $source) {
            if ($only !== null && $source->id !== $only) {
                continue;
            }
            $result = [
                'source' => $source->id, 'operator' => $source->operator,
                'cloud' => $source->cloud, 'ok' => false, 'count' => 0, 'error' => null,
            ];
            try {
                $ranges = IpRanges::fromList($this->downloads->download($source));
                if ($ranges->count() === 0) {
                    throw new \RuntimeException('The list holds no ranges.');
                }
                $this->store->save($source, $ranges, $now);
                $result['ok'] = true;
                $result['count'] = $ranges->count();
            } catch (\RuntimeException|\InvalidArgumentException $e) {
                $result['error'] = $e->getMessage();
            }
            $results[] = $result;
        }
        if ($only !== null && $results === []) {
            throw new \InvalidArgumentException(sprintf('There is no range source "%s".', $only));
        }

        return $results;
    }
}
