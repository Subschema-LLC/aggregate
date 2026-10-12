<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\BotDetection\BotDetector;
use App\Service\BotDetection\BotUserAgentPatterns;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BotDetectorTest extends TestCase
{
    public function testTheVendoredListIsPinnedAndCompiles(): void
    {
        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', BotUserAgentPatterns::SOURCE_COMMIT);
        self::assertGreaterThan(1000, count(BotUserAgentPatterns::PATTERNS));
        self::assertSame(count(BotUserAgentPatterns::PATTERNS), count(array_unique(BotUserAgentPatterns::PATTERNS)));
        foreach (BotUserAgentPatterns::PATTERNS as $pattern) {
            self::assertNotFalse(@preg_match('/'.$pattern.'/', ''), $pattern);
        }
        self::assertNotFalse(preg_match('/'.implode('|', BotUserAgentPatterns::PATTERNS).'/', 'Mozilla/5.0'));
        self::assertFileExists(dirname(__DIR__, 2).'/src/Service/BotDetection/LICENSE-crawler-user-agents');
    }

    /** @return iterable<string, array{string}> */
    public static function bots(): iterable
    {
        yield 'Googlebot' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'];
        yield 'Googlebot rendering' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Googlebot/2.1; +http://www.google.com/bot.html) Chrome/129.0.6668.70 Safari/537.36'];
        yield 'Bingbot' => ['Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)'];
        yield 'headless Chrome' => ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/129.0.0.0 Safari/537.36'];
        yield 'Lighthouse' => ['Mozilla/5.0 (Linux; Android 11; moto g power (2022)) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36 Chrome-Lighthouse'];
        yield 'Facebook preview' => ['facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)'];
        yield 'Ahrefs' => ['Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)'];
        yield 'UptimeRobot' => ['Mozilla/5.0+(compatible; UptimeRobot/2.0; http://www.uptimerobot.com/)'];
        yield 'GPTBot' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)'];
        yield 'Google inspection' => ['Mozilla/5.0 (compatible; Google-InspectionTool/1.0;)'];
        yield 'an unlisted bot' => ['Mozilla/5.0 (compatible; SomeNewBot/1.0)'];
    }

    #[DataProvider('bots')]
    public function testCrawlersMonitorsAndHeadlessBrowsersAreBots(string $userAgent): void
    {
        self::assertTrue(BotDetector::isBot($userAgent));
    }

    /** @return iterable<string, array{string}> */
    public static function visitorsAndHttpClients(): iterable
    {
        yield 'Chrome on Windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36'];
        yield 'Safari on macOS' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15'];
        yield 'Firefox' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:131.0) Gecko/20100101 Firefox/131.0'];
        yield 'Edge' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36 Edg/129.0.0.0'];
        yield 'iPhone Safari' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1'];
        yield 'Samsung Internet' => ['Mozilla/5.0 (Linux; Android 14; SAMSUNG SM-S921B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/26.0 Chrome/122.0.0.0 Mobile Safari/537.36'];
        yield 'CUBOT phone' => ['Mozilla/5.0 (Linux; Android 10; CUBOT X30 Build/QP1A.190711.020) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36'];
        yield 'Facebook in-app browser' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 [FBAN/FBIOS;FBAV/470.0.0.31.104;FBBV/622458190;FBDV/iPhone14,5;FBMD/iPhone;FBSN/iOS;FBSV/17.5;FBSS/3;FBCR/;FBID/phone;FBLC/en_US;FBOP/80]'];
        yield 'Instagram in-app browser' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Instagram 341.0.0.29.92 (iPhone14,5; iOS 17_5; en_US; en; scale=3.00; 1170x2532; 627400398)'];
        yield 'Pinterest in-app browser' => ['Mozilla/5.0 (Linux; Android 14; SM-S918B Build/UP1A.231005.007; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/129.0.6668.70 Mobile Safari/537.36 [Pinterest/Android]'];
        yield 'Google app' => ['Mozilla/5.0 (Linux; Android 14; Pixel 8 Build/AP2A.240905.003; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/129.0.6668.81 Mobile Safari/537.36 GSA/15.38.30.29.arm64'];
        yield 'curl' => ['curl/8.5.0'];
        yield 'python-requests' => ['python-requests/2.32.3'];
        yield 'Go' => ['Go-http-client/2.0'];
        yield 'okhttp' => ['okhttp/4.12.0'];
        yield 'Symfony HttpClient' => ['Symfony HttpClient (Curl)'];
        yield 'Symfony test client' => ['Symfony'];
        yield 'no header' => [''];
    }

    #[DataProvider('visitorsAndHttpClients')]
    public function testBrowsersAndHttpClientLibrariesAreNotBots(string $userAgent): void
    {
        self::assertFalse(BotDetector::isBot($userAgent));
    }

    public function testOperatorPhrasesMatchIgnoringCase(): void
    {
        $userAgent = 'Mozilla/5.0 (Windows NT 10.0) Chrome/129.0 ExampleUptimeMonitor/3.1';
        self::assertFalse(BotDetector::isBot($userAgent));
        self::assertTrue(BotDetector::isBot($userAgent, ['exampleuptimemonitor']));
        self::assertFalse(BotDetector::isBot($userAgent, ['load-test']));
    }

    public function testOnlyTheStartOfAVeryLongHeaderIsExamined(): void
    {
        self::assertFalse(BotDetector::isBot(str_repeat('a', BotDetector::MAX_LENGTH).' Googlebot/2.1'));
        self::assertTrue(BotDetector::isBot('Googlebot/2.1 '.str_repeat('a', 10_000)));
    }
}
