<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Service\AggregateConfigLoader;
use App\Service\InternalTrafficMarking;
use App\Service\InternalTrafficSettings;
use App\Service\WebsiteConfigManager;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Yaml\Yaml;

final class InternalTrafficPublicControllerTest extends WebTestCase
{
    private string $projectDir;
    private array $environment;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        foreach ([...array_keys(InternalTrafficSettings::DEFAULTS), InternalTrafficSettings::TOKEN_KEY] as $key) {
            unset($_ENV[strtoupper($key)], $_SERVER[strtoupper($key)]);
        }
        $this->projectDir = sys_get_temp_dir().'/aggregate-public-marker-'.bin2hex(random_bytes(8));
        mkdir($this->projectDir.'/config', 0700, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        [$_ENV, $_SERVER] = $this->environment;
        @unlink($this->projectDir.'/config/aggregate.yaml');
        @unlink($this->projectDir.'/config/websites.yaml');
        @rmdir($this->projectDir.'/config');
        @rmdir($this->projectDir);
    }

    public function testPublicPageUsesLocalStylesWithoutSharingItsTokenOrStartingASession(): void
    {
        $client = self::createClient();
        $this->configure();
        $crawler = $client->request('GET', '/internal-traffic/'.str_repeat('a', 64));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Mark organization traffic');
        self::assertSelectorExists('[data-marker-set]');
        self::assertSelectorTextContains('body', '"org_internal_traffic": true');
        self::assertSelectorNotExists('script[src], img, style, [style]');
        // Each tracked website gets its own marking links: the tracker on that
        // website saves the marker in the website's own storage.
        $rows = $crawler->filter('[data-marking-website]');
        self::assertCount(2, $rows);
        $mark = $rows->eq(0)->filter('a[data-marking-one="mark"]')->attr('href');
        self::assertMatchesRegularExpression('{^https://shop\.example\.com/#aggregate-org-traffic=v1\.[0-9]+\.mark\.[A-Za-z0-9_-]{43}$}D', $mark);
        self::assertStringStartsWith('http://localhost:8001/#aggregate-org-traffic=v1.', $rows->eq(1)->filter('a[data-marking-one="remove"]')->attr('href'));
        self::assertStringNotContainsString(str_repeat('a', 64), $mark);
        $marking = self::getContainer()->get(InternalTrafficMarking::class);
        self::assertSame('mark', $marking->verify(explode('=', $mark, 2)[1]));
        self::assertSame('remove', $marking->verify(explode('=', (string) $rows->eq(0)->attr('data-remove-url'), 2)[1]));
        $stylesheet = $crawler->filter('link[rel="stylesheet"]');
        self::assertCount(1, $stylesheet);
        self::assertStringStartsWith('/assets/styles/internal-traffic-', $stylesheet->attr('href'));
        self::assertStringNotContainsString(str_repeat('a', 64), $stylesheet->attr('href'));
        self::assertSame('no-referrer', $stylesheet->attr('referrerpolicy'));
        self::assertSelectorExists('meta[name="robots"][content="noindex,nofollow,noarchive"]');
        $this->assertProtectedResponse();
        self::assertSame([], $client->getResponse()->headers->getCookies());
    }

    public function testDownloadContainsMarkerButNoTokenOrDownloadLink(): void
    {
        $client = self::createClient();
        $this->configure();
        $client->request('GET', '/internal-traffic/'.str_repeat('a', 64).'?download=1');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Disposition', 'attachment; filename="internal-traffic.html"');
        self::assertStringContainsString('companyStaff', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString(str_repeat('a', 64), (string) $client->getResponse()->getContent());
        // A downloaded page is hosted on one website and outlives the codes.
        self::assertStringNotContainsString('aggregate-org-traffic=', (string) $client->getResponse()->getContent());
        self::assertSelectorNotExists('[data-internal-traffic-websites]');
        self::assertSelectorNotExists('a[download]');
        self::assertSelectorNotExists('link[rel="stylesheet"], script[src]');
        self::assertSelectorTextContains('style', 'system-ui');
        $this->assertProtectedResponse();
    }

    #[DataProvider('unavailableLinks')]
    public function testMissingWrongDisabledAndMalformedTokensAreNotFound(string $stored, string $path): void
    {
        $client = self::createClient();
        $this->configure($stored);
        $client->request('GET', $path);

        self::assertResponseStatusCodeSame(404);
        self::assertSelectorNotExists('[data-marker-set]');
        $this->assertProtectedResponse();
    }

    public static function unavailableLinks(): iterable
    {
        yield 'missing' => [str_repeat('a', 64), '/internal-traffic'];
        yield 'incorrect' => [str_repeat('a', 64), '/internal-traffic/'.str_repeat('b', 64)];
        yield 'disabled' => ['', '/internal-traffic/'.str_repeat('a', 64)];
        yield 'malformed configuration' => ['short', '/internal-traffic/short'];
    }

    public function testRotationAndRevocationTakeEffectOnPublicRequests(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $settings = new InternalTrafficSettings($this->configure());
        $token = $settings->rotateShareToken();

        $client->request('GET', '/internal-traffic/'.str_repeat('a', 64));
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/internal-traffic/'.$token);
        self::assertResponseIsSuccessful();
        $settings->revokeShareToken();
        $client->request('GET', '/internal-traffic/'.$token);
        self::assertResponseStatusCodeSame(404);
        $this->assertProtectedResponse();
    }

    public function testDashboardStillRequiresLogin(): void
    {
        $client = self::createClient();
        $this->configure();
        $client->request('GET', '/dashboard/internal-traffic');
        self::assertResponseRedirects('http://localhost/login');
        $this->assertProtectedResponse();
    }

    public function testDashboardExplainsTheFixedJsonKeyAndTheDistinctReferrerCategory(): void
    {
        self::createClient();
        $settings = new InternalTrafficSettings($this->configure());
        $request = Request::create('/dashboard/internal-traffic');
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::getContainer()->get('request_stack')->push($request);
        $html = self::getContainer()->get('twig')->render('internal_traffic/index.html.twig', [
            'settings' => $settings->markerSettings(),
            'browser_config' => $settings->toBrowserConfig(),
            'overrides' => $settings->getEnvironmentOverrides(),
            'configuration_error' => null,
            'share_url' => null,
            'marking' => self::getContainer()->get(InternalTrafficMarking::class)->page(),
        ]);
        $text = html_entity_decode(strip_tags($html));

        self::assertStringContainsString('Organization traffic', $text);
        self::assertStringContainsString('"org_internal_traffic": true', $text);
        self::assertStringContainsString('custom_data.org_internal_traffic', $text);
        self::assertStringContainsString('whatever the marker’s name', $text);
        self::assertStringContainsString('referrer category describes navigation within the website', $text);
        self::assertStringContainsString('Mark this browser on all websites', $text);
        self::assertStringContainsString('shop.example.com', $text);
        self::assertStringNotContainsString('custom_data.internalTraffic', $text);
        self::assertStringNotContainsString('custom_data["companyStaff"]', $text);
    }

    public function testStrictProfileExplainsThatNoMarkerCanBeRead(): void
    {
        self::createClient();
        $this->configure(extra: ['collection_profile' => 'strict']);
        $crawler = self::getClient()->request('GET', '/internal-traffic/'.str_repeat('a', 64));

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-marking-website]');
        self::assertStringContainsString('strict collection profile is on', $crawler->filter('[data-internal-traffic-websites]')->text());
    }

    public function testVerifyAnswersTheTrackerWithTheMarkerForAFreshCode(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $this->configure(extra: ['internal_traffic_cookie_domain' => 'example.com', 'app_host' => 'https://analytics.example.net']);
        $marking = self::getContainer()->get(InternalTrafficMarking::class);

        $client->request('GET', '/internal-traffic/verify', ['code' => $marking->code('mark'), 'token' => 'shop-token'], server: ['HTTP_ORIGIN' => 'https://www.shop.example.com']);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Access-Control-Allow-Origin', '*');
        self::assertSame([], $client->getResponse()->headers->getCookies());
        $this->assertProtectedResponse();
        self::assertSame([
            'valid' => true,
            'action' => 'mark',
            'cookieDomain' => 'example.com',
            'continueUrl' => 'https://analytics.example.net/internal-traffic/continue',
        ], json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR));

        // Without a covering cookie domain, the website's own domain is used.
        $this->configure(extra: ['app_host' => 'https://analytics.example.net']);
        $client->request('GET', '/internal-traffic/verify', ['code' => $marking->code('remove'), 'token' => 'shop-token'], server: ['HTTP_ORIGIN' => 'https://www.shop.example.com']);
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('remove', $body['action']);
        self::assertSame('shop.example.com', $body['cookieDomain']);
    }

    #[DataProvider('rejectedCodes')]
    public function testVerifyRejectsExpiredForgedAndRevokedCodes(callable $code, array $extra = []): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $config = $this->configure(extra: $extra);
        $marking = self::getContainer()->get(InternalTrafficMarking::class);

        $client->request('GET', '/internal-traffic/verify', ['code' => $code($marking, new InternalTrafficSettings($config)), 'token' => 'shop-token']);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Access-Control-Allow-Origin', '*');
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($body['valid']);
        self::assertSame(['valid', 'continueUrl'], array_keys($body));
        self::assertStringNotContainsString('companyStaff', (string) $client->getResponse()->getContent());
    }

    public static function rejectedCodes(): iterable
    {
        yield 'missing' => [static fn (): string => ''];
        yield 'malformed' => [static fn (): string => 'v1.123.mark.not-a-signature'];
        yield 'expired' => [static fn (InternalTrafficMarking $marking): string => $marking->code('mark', time() - InternalTrafficMarking::LIFETIME_SECONDS - 1)];
        yield 'forged action' => [static fn (InternalTrafficMarking $marking): string => str_replace('.remove.', '.mark.', $marking->code('remove'))];
        yield 'forged expiry' => [static function (InternalTrafficMarking $marking): string {
            $parts = explode('.', $marking->code('mark'));
            $parts[1] = (string) ((int) $parts[1] - 1);

            return implode('.', $parts);
        }];
        yield 'rotated share link' => [static function (InternalTrafficMarking $marking, InternalTrafficSettings $settings): string {
            $code = $marking->code('mark');
            $settings->rotateShareToken();

            return $code;
        }];
        yield 'revoked share link' => [static function (InternalTrafficMarking $marking, InternalTrafficSettings $settings): string {
            $code = $marking->code('mark');
            $settings->revokeShareToken();

            return $code;
        }];
        yield 'strict profile' => [static fn (InternalTrafficMarking $marking): string => $marking->code('mark'), ['collection_profile' => 'strict']];
    }

    public function testContinuePageIsPublicAndProtected(): void
    {
        $client = self::createClient();
        $this->configure();
        $client->request('GET', '/internal-traffic/continue');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-marking-continue]');
        self::assertSelectorNotExists('script[src]');
        self::assertSame([], $client->getResponse()->headers->getCookies());
        $this->assertProtectedResponse();
    }

    private function configure(?string $token = null, array $extra = []): AggregateConfigLoader
    {
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump([
            'internal_traffic_share_token' => $token ?? str_repeat('a', 64),
            'internal_traffic_name' => 'companyStaff',
            'internal_traffic_value' => 'team',
            ...$extra,
        ]));
        file_put_contents($this->projectDir.'/config/websites.yaml', Yaml::dump(['websites' => [
            ['name' => 'Shop', 'domain' => 'shop.example.com', 'token' => 'shop-token'],
            ['name' => 'Local', 'domain' => 'localhost:8001', 'token' => 'local-token'],
        ]], 4));
        $config = new AggregateConfigLoader($this->projectDir, 'test');
        $container = self::getContainer();
        if (!$container->initialized(AggregateConfigLoader::class)) {
            $container->set(AggregateConfigLoader::class, $config);
            $container->set(WebsiteConfigManager::class, new WebsiteConfigManager($this->projectDir));
        }

        return $config;
    }

    private function assertProtectedResponse(): void
    {
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow, noarchive');
        self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer');
        self::assertStringContainsString('no-store', (string) self::getClient()->getResponse()->headers->get('Cache-Control'));
    }
}
