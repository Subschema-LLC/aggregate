<?php

declare(strict_types=1);

namespace App\Tests\Entities\TrafficClass;

use App\Entities\TrafficClass\KnownAgents;
use App\Entities\TrafficClass\TrafficClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class KnownAgentsTest extends TestCase
{
    #[DataProvider('documentedAgents')]
    public function testRecognisesDocumentedAgents(
        string $userAgent,
        string $token,
        string $operator,
        TrafficClass $class,
    ): void {
        self::assertSame(
            ['token' => $token, 'operator' => $operator, 'class' => $class],
            KnownAgents::match($userAgent),
        );
    }

    /** @return iterable<string, array{string, string, string, TrafficClass}> */
    public static function documentedAgents(): iterable
    {
        $chrome = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Safari/537.36';
        yield 'GPTBot' => [
            'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.1; '
                .'+https://openai.com/gptbot',
            'GPTBot', 'openai', TrafficClass::Crawler,
        ];
        yield 'ChatGPT-User' => [
            $chrome.'; compatible; ChatGPT-User/1.0; +https://openai.com/bot',
            'ChatGPT-User', 'openai', TrafficClass::AiFetcher,
        ];
        yield 'Claude-SearchBot is not read as a shorter token' => [
            'Mozilla/5.0 (compatible; Claude-SearchBot/1.0; +Claude-SearchBot@anthropic.com)',
            'Claude-SearchBot', 'anthropic', TrafficClass::Crawler,
        ];
        yield 'Claude-User' => [
            'Mozilla/5.0 (compatible; Claude-User/1.0)',
            'Claude-User', 'anthropic', TrafficClass::AiFetcher,
        ];
        yield 'Perplexity-User' => [
            $chrome.'; compatible; Perplexity-User/1.0',
            'Perplexity-User', 'perplexity', TrafficClass::AiFetcher,
        ];
        yield 'case does not matter' => [
            'Mozilla/5.0 (compatible; BingBot/2.0)', 'bingbot', 'microsoft', TrafficClass::Crawler,
        ];
        yield 'a product suffix still counts' => [
            'Googlebot-Image/1.0', 'Googlebot', 'google', TrafficClass::Crawler,
        ];
    }

    public function testOrdinaryBrowsersAndLookalikesAreNotAgents(): void
    {
        self::assertNull(KnownAgents::match(''));
        self::assertNull(KnownAgents::match(
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/131.0 Safari/537.36',
        ));
        self::assertNull(KnownAgents::match('NotGPTBotAtAll/1.0'));
        self::assertNull(KnownAgents::match('my-ClaudeBot-clone/1.0'));
    }

    public function testOnlyTheFirstKilobyteIsExamined(): void
    {
        self::assertNull(KnownAgents::match(str_repeat('a', KnownAgents::MAX_LENGTH).' GPTBot/1.1'));
    }
}
