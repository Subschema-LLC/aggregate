<?php

declare(strict_types=1);

namespace App\Tests\Features\TrafficClassification;

use App\Entities\TrafficClass\BotUserAgents;
use App\Entities\TrafficClass\IpRanges;
use App\Entities\TrafficClass\PublishedRanges;
use App\Entities\TrafficClass\RangeSource;
use App\Entities\TrafficClass\TrafficClass;
use App\Features\TrafficClassification\ClassifyRequest\ClassifyRequest;
use PHPUnit\Framework\TestCase;

final class ClassifyRequestTest extends TestCase
{
    private const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        .'(KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

    public function testAFetcherWithABrowserUserAgentIsVerifiedByItsAddress(): void
    {
        $result = $this->classifier()->classify(self::CHROME, '192.0.2.10', false);

        self::assertSame('ai_fetcher', $result['class']);
        self::assertSame('verified', $result['confidence']);
        self::assertSame('openai', $result['operator']);
    }

    public function testTheResultNeverRepeatsTheAddressOrUserAgent(): void
    {
        $result = $this->classifier()->classify(self::CHROME, '198.51.100.20', true);
        $encoded = json_encode($result, JSON_THROW_ON_ERROR);

        self::assertSame('ai_agent', $result['class']);
        self::assertStringNotContainsString('198.51.100.20', $encoded);
        self::assertStringNotContainsString('Chrome', $encoded);
    }

    public function testNoAddressSkipsTheRangeLists(): void
    {
        self::assertSame('human', $this->classifier()->classify(self::CHROME, '', false)['class']);
    }

    public function testTheBotListMarksOtherAutomation(): void
    {
        $result = $this->classifier()->classify('UptimeMonitor/2.0', '203.0.113.5', false);

        self::assertSame('automation', $result['class']);
    }

    private function classifier(): ClassifyRequest
    {
        $ranges = new class implements PublishedRanges {
            public function sourcesContaining(string $ip): array
            {
                $lists = [
                    [new RangeSource('openai-chatgpt-user', 'openai', class: TrafficClass::AiFetcher),
                        IpRanges::fromList(['192.0.2.0/24'])],
                    [new RangeSource('aws', 'aws', true), IpRanges::fromList(['198.51.100.0/24'])],
                ];
                $matches = [];
                foreach ($lists as [$source, $list]) {
                    if ($list->contains($ip)) {
                        $matches[] = $source;
                    }
                }

                return $matches;
            }
        };
        $bots = new class implements BotUserAgents {
            public function isBot(string $userAgent): bool
            {
                return str_contains($userAgent, 'Monitor');
            }
        };

        return new ClassifyRequest($ranges, $bots);
    }
}
