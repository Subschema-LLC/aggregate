<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Service\AggregateConfigLoader;
use App\Service\InternalTrafficSettings;
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
        self::assertSelectorTextContains('body', '{"companyStaff":true}');
        self::assertSelectorNotExists('script[src], img, style, [style]');
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

    public function testDashboardExplainsTheConfiguredJsonKeyAndTheDistinctReferrerCategory(): void
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
        ]);
        $text = html_entity_decode(strip_tags($html));

        self::assertStringContainsString('Organization traffic', $text);
        self::assertStringContainsString('{"companyStaff":true}', $text);
        self::assertStringContainsString('custom_data["companyStaff"] = true', $text);
        self::assertStringContainsString('referrer category describes navigation within the website', $text);
        self::assertStringNotContainsString('custom_data.internalTraffic', $text);
    }

    private function configure(?string $token = null): AggregateConfigLoader
    {
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump([
            'internal_traffic_share_token' => $token ?? str_repeat('a', 64),
            'internal_traffic_name' => 'companyStaff',
            'internal_traffic_value' => 'team',
        ]));
        $config = new AggregateConfigLoader($this->projectDir, 'test');
        self::getContainer()->set(AggregateConfigLoader::class, $config);

        return $config;
    }

    private function assertProtectedResponse(): void
    {
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow, noarchive');
        self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer');
        self::assertStringContainsString('no-store', (string) self::getClient()->getResponse()->headers->get('Cache-Control'));
    }
}
