<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AggregateConfigLoader;
use App\Service\InternalTrafficMarking;
use App\Service\InternalTrafficSettings;
use App\Service\WebsiteConfigManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class InternalTrafficMarkingTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private string $projectDir;
    private array $environment;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        foreach ([...array_keys(InternalTrafficSettings::DEFAULTS), InternalTrafficSettings::TOKEN_KEY, 'app_host', 'collection_profile'] as $key) {
            unset($_ENV[strtoupper($key)], $_SERVER[strtoupper($key)]);
        }
        $this->projectDir = sys_get_temp_dir().'/aggregate-marking-'.bin2hex(random_bytes(8));
        mkdir($this->projectDir.'/config', 0700, true);
    }

    protected function tearDown(): void
    {
        [$_ENV, $_SERVER] = $this->environment;
        foreach (glob($this->projectDir.'/config/*') as $path) {
            unlink($path);
        }
        rmdir($this->projectDir.'/config');
        rmdir($this->projectDir);
    }

    public function testCodesAreValidForTheirActionUntilTheyExpire(): void
    {
        $marking = $this->marking();
        $mark = $marking->code('mark', self::NOW);
        $remove = $marking->code('remove', self::NOW);

        self::assertMatchesRegularExpression('/^v1\.1800001800\.mark\.[A-Za-z0-9_-]{43}$/D', $mark);
        self::assertSame('mark', $marking->verify($mark, self::NOW));
        self::assertSame('mark', $marking->verify($mark, self::NOW + InternalTrafficMarking::LIFETIME_SECONDS));
        self::assertNull($marking->verify($mark, self::NOW + InternalTrafficMarking::LIFETIME_SECONDS + 1));
        self::assertSame('remove', $marking->verify($remove, self::NOW));
        // A code from a server with another secret is not accepted.
        self::assertNull($this->marking(secret: 'another-secret')->verify($mark, self::NOW));
    }

    #[DataProvider('forgedCodes')]
    public function testForgedOrMalformedCodesAreRejected(mixed $code): void
    {
        self::assertNull($this->marking()->verify($code, self::NOW));
    }

    public static function forgedCodes(): iterable
    {
        yield 'not a string' => [['v1']];
        yield 'empty' => [''];
        yield 'unknown version' => ['v2.1800001800.mark.'.str_repeat('A', 43)];
        yield 'unknown action' => ['v1.1800001800.erase.'.str_repeat('A', 43)];
        yield 'wrong signature' => ['v1.1800001800.mark.'.str_repeat('A', 43)];
        yield 'trailing newline' => ["v1.1800001800.mark.".str_repeat('A', 43)."\n"];
        yield 'expiry beyond the lifetime' => ['v1.1900000000.mark.'.str_repeat('A', 43)];
    }

    public function testChangedFieldsInvalidateTheSignature(): void
    {
        $marking = $this->marking();
        $code = $marking->code('remove', self::NOW);

        self::assertNull($marking->verify(str_replace('.remove.', '.mark.', $code), self::NOW));
        self::assertNull($marking->verify(str_replace('v1.1800001800.', 'v1.1800001799.', $code), self::NOW));
    }

    public function testRotatingOrRevokingTheShareLinkStopsOutstandingCodes(): void
    {
        $config = $this->config();
        $marking = $this->marking($config);
        $code = $marking->code('mark', self::NOW);
        $settings = new InternalTrafficSettings($config);

        $settings->rotateShareToken();
        self::assertNull($marking->verify($code, self::NOW));
        $fresh = $marking->code('mark', self::NOW);
        self::assertSame('mark', $marking->verify($fresh, self::NOW));
        $settings->revokeShareToken();
        self::assertNull($marking->verify($fresh, self::NOW));
    }

    public function testOnlyMarkAndRemoveCodesCanBeIssued(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->marking()->code('grant', self::NOW);
    }

    public function testPageListsWebsitesWithHomePageLinksAndSkipsUnusableDomains(): void
    {
        $page = $this->marking(websites: [
            ['name' => 'Shop', 'domain' => 'Shop.Example.com', 'token' => 'shop'],
            ['name' => 'Local', 'domain' => 'localhost:8001', 'token' => 'local'],
            ['name' => 'Broken', 'domain' => 'https://example.org/path', 'token' => 'broken'],
            ['name' => 'Empty', 'domain' => '', 'token' => 'empty'],
        ])->page(self::NOW);

        self::assertFalse($page['strict']);
        self::assertSame(self::NOW + InternalTrafficMarking::LIFETIME_SECONDS, $page['expires']);
        self::assertSame(['Shop', 'Local'], array_column($page['websites'], 'name'));
        self::assertStringStartsWith('https://shop.example.com/#aggregate-org-traffic=v1.1800001800.mark.', $page['websites'][0]['mark_url']);
        self::assertStringStartsWith('https://shop.example.com/#aggregate-org-traffic=v1.1800001800.remove.', $page['websites'][0]['remove_url']);
        self::assertStringStartsWith('http://localhost:8001/#aggregate-org-traffic=', $page['websites'][1]['mark_url']);
        self::assertStringNotContainsString(str_repeat('a', 64), json_encode($page, JSON_THROW_ON_ERROR));
        self::assertTrue($this->marking($this->config(['collection_profile' => 'strict']))->page(self::NOW)['strict']);
    }

    #[DataProvider('homeUrls')]
    public function testHomeUrls(string $domain, ?string $expected): void
    {
        self::assertSame($expected, InternalTrafficMarking::homeUrl($domain));
    }

    public static function homeUrls(): iterable
    {
        yield ['example.com', 'https://example.com/'];
        yield [' WWW.Example.com ', 'https://www.example.com/'];
        yield ['example.com:8443', 'https://example.com:8443/'];
        yield ['localhost', 'http://localhost/'];
        yield ['app.localhost:8000', 'http://app.localhost:8000/'];
        yield ['127.0.0.1:8080', 'http://127.0.0.1:8080/'];
        yield ['', null];
        yield ['https://example.com', null];
        yield ['example.com/path', null];
        yield ['example.com#fragment', null];
        yield ['exa mple.com', null];
    }

    #[DataProvider('cookieDomains')]
    public function testCookieDomainCoversThePageOrTheMarkerIsHostOnly(array $settings, string $origin, ?array $website, string $expected): void
    {
        self::assertSame($expected, $this->marking($this->config($settings))->cookieDomainFor($origin, $website));
    }

    public static function cookieDomains(): iterable
    {
        $shop = ['domain' => 'shop.example.com'];
        yield 'configured domain covers the page' => [['internal_traffic_cookie_domain' => '.example.com'], 'https://www.shop.example.com', $shop, 'example.com'];
        yield 'website domain when the configured one does not cover the page' => [['internal_traffic_cookie_domain' => 'example.org'], 'https://www.shop.example.com', $shop, 'shop.example.com'];
        yield 'exact website host' => [[], 'https://shop.example.com', $shop, 'shop.example.com'];
        yield 'unrelated page is host-only' => [[], 'https://evil.example.net', $shop, ''];
        yield 'single-label host is host-only' => [[], 'http://localhost:8001', ['domain' => 'localhost:8001'], ''];
        yield 'no origin is host-only' => [[], '', $shop, ''];
        yield 'unknown website is host-only' => [[], 'https://shop.example.com', null, ''];
        yield '__Host- cookies never get a domain' => [['internal_traffic_name' => '__Host-staff'], 'https://shop.example.com', $shop, ''];
        yield 'local storage has no domain' => [['internal_traffic_storage' => 'local_storage', 'internal_traffic_cookie_domain' => 'example.com'], 'https://shop.example.com', $shop, ''];
    }

    public function testContinueUrlUsesTheConfiguredApplicationHost(): void
    {
        self::assertSame('https://fallback.test/internal-traffic/continue', $this->marking()->continueUrl('https://fallback.test/internal-traffic/continue'));
        self::assertSame(
            'https://analytics.example.com/internal-traffic/continue',
            $this->marking($this->config(['app_host' => 'https://analytics.example.com/']))->continueUrl('https://fallback.test/internal-traffic/continue'),
        );
    }

    private function config(array $values = []): AggregateConfigLoader
    {
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump([
            InternalTrafficSettings::TOKEN_KEY => str_repeat('a', 64),
            ...$values,
        ]));

        return new AggregateConfigLoader($this->projectDir, 'test');
    }

    private function marking(?AggregateConfigLoader $config = null, string $secret = 'test-secret', array $websites = []): InternalTrafficMarking
    {
        $config ??= $this->config();
        file_put_contents($this->projectDir.'/config/websites.yaml', Yaml::dump(['websites' => $websites], 4));

        return new InternalTrafficMarking(new InternalTrafficSettings($config), new WebsiteConfigManager($this->projectDir), $config, $secret);
    }
}
