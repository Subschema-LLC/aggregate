<?php

declare(strict_types=1);

namespace App\Tests\Integrations\TrafficRanges;

use App\Entities\TrafficClass\IpRanges;
use App\Entities\TrafficClass\RangeSource;
use App\Integrations\TrafficRanges\FileRangeStore;
use App\Integrations\TrafficRanges\HttpRangeDownloads;
use App\Integrations\TrafficRanges\PublishedSources;
use App\Integrations\TrafficRanges\RangeFormats;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class RangeListsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/aggregate-ranges-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testReadsBothPublishedLayouts(): void
    {
        $prefixes = '{"creationTime":"2026-11-20T00:00:00","prefixes":'
            .'[{"ipv4Prefix":"192.0.2.0/24"},{"ipv6Prefix":"2001:db8::/32"}]}';
        $aws = '{"prefixes":[{"ip_prefix":"198.51.100.0/24","region":"x"}],'
            .'"ipv6_prefixes":[{"ipv6_prefix":"2001:db8:1::/48"}]}';

        self::assertSame(['192.0.2.0/24', '2001:db8::/32'], RangeFormats::parse('prefixes', $prefixes));
        self::assertSame(['198.51.100.0/24', '2001:db8:1::/48'], RangeFormats::parse('aws', $aws));
    }

    public function testRefusesListsInAnotherLayout(): void
    {
        foreach (['not json', '[]', '{"prefixes":{"a":1}}', '{"prefixes":[{"cidr":"192.0.2.0/24"}]}'] as $body) {
            try {
                RangeFormats::parse('prefixes', $body);
                self::fail('Accepted '.$body);
            } catch (\RuntimeException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testEveryPublishedSourceIsHttpsAndValid(): void
    {
        $sources = new PublishedSources();
        $ids = [];
        foreach ($sources->all() as $source) {
            $location = $sources->location($source);
            self::assertStringStartsWith('https://', $location['url']);
            self::assertContains($location['format'], ['prefixes', 'aws']);
            $ids[] = $source->id;
        }
        self::assertContains('anthropic-bots', $ids);
        self::assertContains('openai-chatgpt-user', $ids);
        self::assertSame(array_unique($ids), $ids);
    }

    public function testDownloadsAndParsesAList(): void
    {
        $requested = [];
        $http = new MockHttpClient(static function (string $method, string $url) use (&$requested) {
            $requested[] = $method.' '.$url;

            return new MockResponse('{"prefixes":[{"ipv4Prefix":"192.0.2.0/24"}]}');
        });
        $downloads = new HttpRangeDownloads($http);
        $source = $this->source('openai-gptbot', $downloads->sources());

        self::assertSame(['192.0.2.0/24'], $downloads->download($source));
        self::assertSame(['GET https://openai.com/gptbot.json'], $requested);
    }

    public function testAFailedDownloadIsARuntimeError(): void
    {
        $downloads = new HttpRangeDownloads(new MockHttpClient(new MockResponse('', ['http_code' => 503])));

        $this->expectException(\RuntimeException::class);
        $downloads->download($this->source('aws', $downloads->sources()));
    }

    public function testSavesListsAndFindsTheSourcesHoldingAnAddress(): void
    {
        $store = new FileRangeStore($this->directory);
        $sources = (new PublishedSources())->all();
        $fetched = new \DateTimeImmutable('2026-11-20 12:00:00 UTC');
        $store->save($this->source('openai-chatgpt-user', $sources), IpRanges::fromList(['192.0.2.0/24']), $fetched);
        $store->save($this->source('aws', $sources), IpRanges::fromList(['192.0.2.0/23']), $fetched);

        $fresh = new FileRangeStore($this->directory);
        $matches = array_map(static fn (RangeSource $s): string => $s->id, $fresh->sourcesContaining('192.0.2.9'));

        self::assertSame(['openai-chatgpt-user', 'aws'], $matches);
        self::assertSame(['aws'], array_map(
            static fn (RangeSource $s): string => $s->id,
            $fresh->sourcesContaining('192.0.3.9'),
        ));
        self::assertSame([], $fresh->sourcesContaining('203.0.113.1'));
        self::assertSame(1, $fresh->saved()['aws']['count']);
        self::assertEquals($fetched, $fresh->saved()['aws']['fetched_at']);
    }

    public function testAnUnreadableFileCountsAsNoList(): void
    {
        (new Filesystem())->dumpFile($this->directory.'/aws.json', '{broken');
        $store = new FileRangeStore($this->directory);

        self::assertSame([], $store->saved());
        self::assertSame([], $store->sourcesContaining('192.0.2.1'));
    }

    /** @param list<RangeSource> $sources */
    private function source(string $id, array $sources): RangeSource
    {
        foreach ($sources as $source) {
            if ($source->id === $id) {
                return $source;
            }
        }
        self::fail('No source '.$id);
    }
}
