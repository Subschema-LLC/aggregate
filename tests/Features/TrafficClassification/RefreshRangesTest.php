<?php

declare(strict_types=1);

namespace App\Tests\Features\TrafficClassification;

use App\Entities\TrafficClass\IpRanges;
use App\Entities\TrafficClass\RangeDownloads;
use App\Entities\TrafficClass\RangeSource;
use App\Entities\TrafficClass\RangeStore;
use App\Entities\TrafficClass\TrafficClass;
use App\Features\TrafficClassification\RefreshRanges\RefreshRanges;
use App\Features\TrafficClassification\ShowRanges\ShowRanges;
use PHPUnit\Framework\TestCase;

final class RefreshRangesTest extends TestCase
{
    /** @var array<string, list<string>|\RuntimeException> */
    private array $published = [];

    private RangeStore $store;

    protected function setUp(): void
    {
        $this->store = new class implements RangeStore {
            /** @var array<string, array{count: int, fetched_at: \DateTimeImmutable}> */
            public array $lists = [];

            public function save(
                RangeSource $source,
                IpRanges $ranges,
                \DateTimeImmutable $fetchedAt,
            ): void {
                $this->lists[$source->id] = ['count' => $ranges->count(), 'fetched_at' => $fetchedAt];
            }

            public function saved(): array
            {
                return $this->lists;
            }
        };
    }

    public function testSavesEveryListThatDownloads(): void
    {
        $this->published = ['bot' => ['192.0.2.0/24', '2001:db8::/32'], 'cloud' => ['198.51.100.0/24']];
        $now = new \DateTimeImmutable('2026-11-20 12:00:00 UTC');

        $results = $this->refresh()->refresh($now);

        self::assertSame([true, true], array_column($results, 'ok'));
        self::assertSame([2, 1], array_column($results, 'count'));
        $shown = (new ShowRanges($this->downloads(), $this->store))->show();
        self::assertSame(2, $shown[0]['count']);
        self::assertSame($now, $shown[0]['fetched_at']);
        self::assertSame('crawler', $shown[0]['class']);
        self::assertTrue($shown[1]['cloud']);
    }

    public function testAFailedOrBrokenListKeepsThePreviousOne(): void
    {
        $this->published = ['bot' => ['192.0.2.0/24'], 'cloud' => ['198.51.100.0/24']];
        $this->refresh()->refresh(new \DateTimeImmutable('2026-11-19 12:00:00 UTC'));

        $this->published = ['bot' => new \RuntimeException('HTTP 503'), 'cloud' => ['not a range']];
        $results = $this->refresh()->refresh(new \DateTimeImmutable('2026-11-20 12:00:00 UTC'));

        self::assertSame([false, false], array_column($results, 'ok'));
        self::assertSame('HTTP 503', $results[0]['error']);
        self::assertSame('2026-11-19', $this->store->saved()['bot']['fetched_at']->format('Y-m-d'));
        self::assertSame(1, $this->store->saved()['cloud']['count']);
    }

    public function testAnEmptyListIsNotSaved(): void
    {
        $this->published = ['bot' => [], 'cloud' => ['198.51.100.0/24']];

        $results = $this->refresh()->refresh(new \DateTimeImmutable('2026-11-20 12:00:00 UTC'));

        self::assertFalse($results[0]['ok']);
        self::assertArrayNotHasKey('bot', $this->store->saved());
    }

    public function testRefreshesOneSourceOrRefusesAnUnknownOne(): void
    {
        $this->published = ['bot' => ['192.0.2.0/24'], 'cloud' => ['198.51.100.0/24']];
        $now = new \DateTimeImmutable('2026-11-20 12:00:00 UTC');

        self::assertSame(['cloud'], array_column($this->refresh()->refresh($now, 'cloud'), 'source'));
        $this->expectException(\InvalidArgumentException::class);
        $this->refresh()->refresh($now, 'missing');
    }

    private function refresh(): RefreshRanges
    {
        return new RefreshRanges($this->downloads(), $this->store);
    }

    private function downloads(): RangeDownloads
    {
        $published = &$this->published;

        return new class($published) implements RangeDownloads {
            /** @param array<string, list<string>|\RuntimeException> $published */
            public function __construct(private array &$published)
            {
            }

            public function sources(): array
            {
                return [
                    new RangeSource('bot', 'example', class: TrafficClass::Crawler),
                    new RangeSource('cloud', 'example-cloud', true),
                ];
            }

            public function download(RangeSource $source): array
            {
                $list = $this->published[$source->id];
                if ($list instanceof \RuntimeException) {
                    throw $list;
                }

                return $list;
            }
        };
    }
}
