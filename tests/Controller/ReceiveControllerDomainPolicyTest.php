<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\ReceiveController;
use App\Entity\Event;
use App\Message\TrackEventMessage;
use App\Security\IpRateLimiter;
use App\Service\AggregateConfigLoader;
use App\Service\AnonymousEventRecorder;
use App\Service\CustomDataSettings;
use App\Service\GeoIp\GeoIpResolverInterface;
use App\Service\GoalEventRegistry;
use App\Service\InternalTrafficSettings;
use App\Service\PrivacyPolicy;
use App\Service\PrivacySanitizer;
use App\Service\WebsiteConfigManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Yaml\Yaml;

final class ReceiveControllerDomainPolicyTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/aggregate-domain-ingestion-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->projectDir.'/config', 0700, true));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->projectDir.'/config/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->projectDir.'/config');
        @rmdir($this->projectDir);
    }

    #[DataProvider('domainRequests')]
    public function testSavedPolicyControlsDirectRequestsInBothPrivacyModes(
        array $website,
        ?string $origin,
        ?string $referer,
        bool $allowed,
        string $consentState,
    ): void {
        [$response, $stored] = $this->ingest($website, $origin, $referer, $consentState, $allowed);

        self::assertSame($allowed ? 202 : 403, $response->getStatusCode());
        if (!$allowed) {
            self::assertSame(['error' => 'Forbidden origin'], $this->responseData($response));

            return;
        }

        self::assertSame([
            'status' => $consentState === 'granted' ? 'accepted' : 'recorded',
            'mode' => $consentState === 'granted' ? 'enhanced' : 'anonymous',
        ], $this->responseData($response));
        // Domain/header policy must not add request metadata to retained events.
        self::assertStringNotContainsString('203.0.113.42', serialize($stored));
        self::assertStringNotContainsString('https://', serialize($stored));
        if ($origin) {
            self::assertSame($origin, $response->headers->get('Access-Control-Allow-Origin'));
        }
    }

    public static function domainRequests(): iterable
    {
        $exact = ['domain_policy' => ['mode' => 'restricted', 'domains' => ['example.com']]];
        $selected = ['domain_policy' => ['mode' => 'restricted', 'domains' => ['shop.example.com', 'docs.other.test']]];
        $wildcard = ['domain_policy' => ['mode' => 'restricted', 'domains' => ['*.example.com']]];
        $all = ['domain_policy' => ['mode' => 'all']];
        $cases = [
            'legacy primary domain' => [[], 'https://example.com', null, true],
            'legacy descendant' => [[], 'https://a.b.example.com', null, true],
            'legacy unrelated domain' => [[], 'https://other.test', null, false],
            'exact primary domain' => [$exact, 'https://example.com', null, true],
            'exact rule excludes descendants' => [$exact, 'https://www.example.com', null, false],
            'selected subdomain' => [$selected, 'https://shop.example.com', null, true],
            'additional unrelated domain' => [$selected, 'https://docs.other.test', null, true],
            'primary domain is not implicitly allowed' => [$selected, 'https://example.com', null, false],
            'selected subdomain excludes descendants' => [$selected, 'https://preview.shop.example.com', null, false],
            'wildcard subdomain' => [$wildcard, 'https://shop.example.com', null, true],
            'wildcard nested subdomain' => [$wildcard, 'https://a.b.example.com', null, true],
            'wildcard excludes apex' => [$wildcard, 'https://example.com', null, false],
            'wildcard excludes suffix lookalike' => [$wildcard, 'https://notexample.com', null, false],
            'wildcard excludes appended domain' => [$wildcard, 'https://shop.example.com.attacker.test', null, false],
            'all permits unrelated domain' => [$all, 'https://other.test', null, true],
            'all permits absent headers' => [$all, null, null, true],
            'all permits opaque origin' => [$all, 'null', null, true],
            'missing origin uses referer host' => [$exact, null, 'https://example.com/private?secret=value', true],
            'restricted denies missing headers' => [$exact, null, null, false],
            'origin wins over unrelated referer' => [$exact, 'https://example.com', 'https://other.test/path', true],
            'denied origin cannot use allowed referer' => [$exact, 'https://other.test', 'https://example.com/path', false],
            'empty origin cannot use allowed referer' => [$exact, '', 'https://example.com/path', false],
            'opaque origin cannot use allowed referer' => [$exact, 'null', 'https://example.com/path', false],
            'malformed origin cannot use allowed referer' => [$exact, 'https://example.com/private', 'https://example.com/path', false],
            'null policy fails closed' => [['domain_policy' => null], 'https://example.com', null, false],
            'scalar policy fails closed' => [['domain_policy' => 'all'], 'https://example.com', null, false],
            'unknown policy mode fails closed' => [['domain_policy' => ['mode' => 'allow_all']], 'https://example.com', null, false],
            'empty restricted list fails closed' => [['domain_policy' => ['mode' => 'restricted', 'domains' => []]], 'https://example.com', null, false],
            'invalid rule invalidates otherwise matching list' => [['domain_policy' => ['mode' => 'restricted', 'domains' => ['example.com', '*example.com']]], 'https://example.com', null, false],
            'invalid domains in all policy fail closed' => [['domain_policy' => ['mode' => 'all', 'domains' => 'example.com']], 'https://example.com', null, false],
        ];

        foreach (['denied', 'granted'] as $consentState) {
            foreach ($cases as $name => $arguments) {
                yield $name.' with '.$consentState.' consent' => [...$arguments, $consentState];
            }
        }
    }

    #[DataProvider('repeatedHeaders')]
    public function testRepeatedHeadersCannotSelectAnAllowedFirstValue(
        array $website,
        string|array|null $origin,
        string|array|null $referer,
        bool $allowed,
        string $consentState,
    ): void {
        [$response] = $this->ingest($website, $origin, $referer, $consentState, $allowed);

        self::assertSame($allowed ? 202 : 403, $response->getStatusCode());
    }

    public static function repeatedHeaders(): iterable
    {
        $exact = ['domain_policy' => ['mode' => 'restricted', 'domains' => ['example.com']]];
        $all = ['domain_policy' => ['mode' => 'all']];
        $origins = ['https://example.com', 'https://attacker.test'];
        $referers = ['https://example.com/path', 'https://attacker.test/path'];
        $cases = [
            'repeated Origin' => [$exact, $origins, null, false],
            'repeated Referer fallback' => [$exact, null, $referers, false],
            'repeated Referer ignored when Origin is present' => [$exact, 'https://example.com', $referers, true],
            'allow all with repeated Origin' => [$all, $origins, null, true],
            'allow all with repeated Referer' => [$all, null, $referers, true],
        ];
        foreach (['denied', 'granted'] as $consentState) {
            foreach ($cases as $name => $arguments) {
                yield $name.' with '.$consentState.' consent' => [...$arguments, $consentState];
            }
        }
    }

    #[DataProvider('invalidTokens')]
    public function testAllowAllStillRequiresARegisteredWebsiteToken(mixed $token, string $consentState): void
    {
        [$response] = $this->ingest(
            ['domain_policy' => ['mode' => 'all']],
            null,
            null,
            $consentState,
            false,
            ['websiteToken' => $token],
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertSame([
            'error' => $token === 'unknown-token' ? 'Invalid websiteToken' : 'websiteToken is required',
        ], $this->responseData($response));
    }

    public static function invalidTokens(): iterable
    {
        foreach (['denied', 'granted'] as $consentState) {
            yield 'null token with '.$consentState.' consent' => [null, $consentState];
            yield 'empty token with '.$consentState.' consent' => ['', $consentState];
            yield 'unknown token with '.$consentState.' consent' => ['unknown-token', $consentState];
        }
    }

    #[DataProvider('privacyModes')]
    public function testClientPolicyAndPayloadUrlsCannotOverrideSavedRestrictions(string $consentState): void
    {
        [$response] = $this->ingest(
            ['domain_policy' => ['mode' => 'restricted', 'domains' => ['example.com']]],
            'https://attacker.test',
            'https://example.com/',
            $consentState,
            false,
            [
                'domain_policy' => ['mode' => 'all'],
                'domainPolicy' => ['mode' => 'all'],
                'allowedDomains' => ['attacker.test'],
                'domain' => 'example.com',
                'origin' => 'https://example.com',
                'url' => 'https://example.com/pricing',
                'referrer' => 'https://example.com/',
                'eventData' => ['domain_policy' => 'all'],
            ],
        );

        self::assertSame(403, $response->getStatusCode());
    }

    #[DataProvider('referrers')]
    public function testAdditionalAllowedHostsDoNotChangeThePrimaryReferrerClassification(
        string $consentState,
        string $referrer,
        string $expectedChannel,
    ): void {
        [$response, $stored] = $this->ingest(
            ['domain_policy' => ['mode' => 'restricted', 'domains' => ['other.test']]],
            'https://other.test',
            null,
            $consentState,
            true,
            ['referrer' => $referrer],
        );

        self::assertSame(202, $response->getStatusCode());
        self::assertSame($expectedChannel, $stored instanceof Event ? $stored->getReferrer() : $stored->referrerChannel);
    }

    public static function referrers(): iterable
    {
        foreach (['denied', 'granted'] as $consentState) {
            yield 'primary referrer with '.$consentState.' consent' => [$consentState, 'https://example.com/contact', 'internal'];
            yield 'additional host referrer with '.$consentState.' consent' => [$consentState, 'https://other.test/contact', 'referral'];
        }
    }

    #[DataProvider('privacyModes')]
    public function testAllowAllStillHonorsTheCollectionKillSwitchBeforeLookups(string $consentState): void
    {
        [$response] = $this->ingest(
            ['domain_policy' => ['mode' => 'all']],
            null,
            null,
            $consentState,
            false,
            collectionEnabled: false,
        );

        self::assertSame(202, $response->getStatusCode());
        self::assertSame(['status' => 'ignored'], $this->responseData($response));
    }

    #[DataProvider('privacyModes')]
    public function testAllowAllStillHonorsSensitivePathExclusionsBeforeLookups(string $consentState): void
    {
        [$response] = $this->ingest(
            ['domain_policy' => ['mode' => 'all']],
            null,
            null,
            $consentState,
            false,
            excludedPaths: ['/pricing'],
        );

        self::assertSame(202, $response->getStatusCode());
        self::assertSame(['status' => 'ignored'], $this->responseData($response));
    }

    public static function privacyModes(): iterable
    {
        yield 'anonymous' => ['denied'];
        yield 'enhanced' => ['granted'];
    }

    /** @return array{Response, Event|TrackEventMessage|null} */
    private function ingest(
        array $website,
        string|array|null $origin,
        string|array|null $referer,
        string $consentState,
        bool $allowed,
        array $payloadOverrides = [],
        bool $collectionEnabled = true,
        array $excludedPaths = [],
    ): array {
        $website = array_replace([
            'name' => 'Example',
            'domain' => 'example.com',
            'token' => 'public-site-token',
        ], $website);
        file_put_contents($this->projectDir.'/config/websites.yaml', Yaml::dump(['websites' => [$website]], 6));
        $manager = new WebsiteConfigManager($this->projectDir);
        $config = $this->createStub(AggregateConfigLoader::class);
        $config->method('all')->willReturn(['custom_data_properties' => [], 'query_parameter_mappings' => []]);
        $config->method('getBoolWithEnvFallback')->willReturn($collectionEnabled);
        $config->method('getWithEnvFallback')->willReturnCallback(
            static fn (string $key, mixed $default = null): mixed => $key === 'anonymous_excluded_paths' ? $excludedPaths : $default,
        );

        $stored = null;
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($allowed && $consentState === 'denied' ? self::once() : self::never())
            ->method('persist')
            ->willReturnCallback(static function (object $event) use (&$stored): void {
                self::assertInstanceOf(Event::class, $event);
                $stored = $event;
            });
        $entityManager->expects($allowed && $consentState === 'denied' ? self::once() : self::never())->method('flush');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($allowed && $consentState === 'granted' ? self::once() : self::never())
            ->method('dispatch')
            ->willReturnCallback(static function (object $message) use (&$stored): Envelope {
                self::assertInstanceOf(TrackEventMessage::class, $message);
                $stored = $message;

                return new Envelope($message);
            });
        $geo = $this->createMock(GeoIpResolverInterface::class);
        $geo->expects($allowed ? self::once() : self::never())->method('resolve')->willReturn(null);
        $limiter = $this->createMock(IpRateLimiter::class);
        $limiter->expects($collectionEnabled && $excludedPaths === [] ? self::once() : self::never())
            ->method('allow')->willReturn(true);

        $request = Request::create('/api/receive', 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => '203.0.113.42',
        ], content: json_encode(array_replace([
            'websiteToken' => 'public-site-token',
            'pagePath' => '/pricing',
            'eventName' => 'view',
            'consentState' => $consentState,
        ], $payloadOverrides), JSON_THROW_ON_ERROR));
        if ($origin !== null) {
            $request->headers->set('Origin', $origin);
        }
        if ($referer !== null) {
            $request->headers->set('Referer', $referer);
        }
        $sanitizer = new PrivacySanitizer();

        $response = (new ReceiveController())(
            $request,
            $manager,
            $bus,
            $limiter,
            $sanitizer,
            new GoalEventRegistry($sanitizer, []),
            new PrivacyPolicy($config),
            $geo,
            new AnonymousEventRecorder($entityManager),
            new NullLogger(),
            new InternalTrafficSettings($config),
            new CustomDataSettings($config),
        );

        return [$response, $stored];
    }

    private function responseData(Response $response): array
    {
        return json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
