<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Controller\HealthController;
use App\Controller\ReceiveController;
use App\Service\EventIngestion;
use App\Controller\ScriptController;
use App\Entity\Event;
use App\Service\AggregateConfigLoader;
use App\Service\AnonymousEventRecorder;
use App\Service\CollectionProfile;
use App\Service\CustomDataSettings;
use App\Service\GeoIp\GeoIpResolverInterface;
use App\Service\GoalEventRegistry;
use App\Service\InternalTrafficSettings;
use App\Service\PrivacyPolicy;
use App\Service\PrivacySanitizer;
use App\Service\SiteScriptConfig;
use App\Service\WebsiteScopeCatalog;
use App\Service\WebsiteScopeEditor;
use App\Service\WebsiteScopePublisher;
use App\Service\WebsiteScopeResolver;
use App\Service\WebsiteConfigManager;
use App\Security\IpRateLimiter;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;

final class WebsiteScopeResolverTest extends TestCase
{
    public function testAMissingCatalogUsesTheDeploymentSettings(): void
    {
        [$directory, $loader] = $this->deployment(['anonymous_tracking_enabled' => true, CollectionProfile::KEY => CollectionProfile::STANDARD]);
        $resolver = new WebsiteScopeResolver($loader, new WebsiteScopeCatalog($directory, 'test'));

        $view = $resolver->forToken('store-token');

        self::assertFalse($view->isScopeInvalid());
        self::assertTrue((new PrivacyPolicy($view))->isAnonymousTrackingEnabled());
        self::assertSame(CollectionProfile::STANDARD, (new CollectionProfile($view))->name());
    }

    public function testTheMostSpecificSettingWinsAndEnvironmentStillWins(): void
    {
        [$directory, $loader] = $this->deployment([
            'anonymous_tracking_enabled' => true,
            CollectionProfile::KEY => CollectionProfile::STANDARD,
            'anonymous_excluded_paths' => ['/deployment'],
        ]);
        $catalog = new WebsiteScopeCatalog($directory, 'test');
        $group = $catalog->createGroup('Amazon');
        $catalog->assign('store-token', $group);
        $catalog->saveOverlay('groups', $group, [
            CollectionProfile::KEY => CollectionProfile::STRICT,
            'anonymous_excluded_paths' => ['/group'],
        ], []);
        $catalog->saveOverlay('websites', SiteScriptConfig::idForToken('store-token'), [
            'anonymous_excluded_paths' => ['/website'],
        ], []);
        $resolver = new WebsiteScopeResolver($loader, $catalog);

        $view = $resolver->forToken('store-token');

        self::assertSame(['/website'], $view->get('anonymous_excluded_paths'));
        self::assertSame(CollectionProfile::STRICT, (new CollectionProfile($view))->name());
        self::assertTrue((new PrivacyPolicy($view))->isAnonymousTrackingEnabled());

        $_ENV['COLLECTION_PROFILE'] = CollectionProfile::STANDARD;
        $_SERVER['COLLECTION_PROFILE'] = CollectionProfile::STANDARD;
        try {
            self::assertSame(CollectionProfile::STANDARD, (new CollectionProfile($view))->name());
        } finally {
            unset($_ENV['COLLECTION_PROFILE'], $_SERVER['COLLECTION_PROFILE']);
        }
    }

    public function testADirectRequestUsesTheWebsiteSettingBeforeLookingUpTheWebsite(): void
    {
        [$directory, $loader] = $this->deployment(['anonymous_tracking_enabled' => true]);
        $catalog = new WebsiteScopeCatalog($directory, 'test');
        $catalog->saveOverlay('websites', SiteScriptConfig::idForToken('store-token'), [
            'anonymous_tracking_enabled' => false,
        ], []);
        $resolver = new WebsiteScopeResolver($loader, $catalog);
        self::assertFalse($resolver->forToken('store-token')->isScopeInvalid());
        $websites = $this->createMock(WebsiteConfigManager::class);
        $websites->expects(self::never())->method('findOneByToken');

        $response = (new ReceiveController())(
            Request::create('/api/receive', 'POST', server: [
                'CONTENT_TYPE' => 'application/json',
                'REMOTE_ADDR' => '203.0.113.9',
            ], content: json_encode([
                'websiteToken' => 'store-token',
                'pagePath' => '/pricing',
                'eventName' => 'view',
                'consentState' => 'denied',
            ], JSON_THROW_ON_ERROR)),
            new EventIngestion(
                $websites,
                $this->createStub(MessageBusInterface::class),
                $this->createStub(IpRateLimiter::class),
                $sanitizer = new PrivacySanitizer(),
                new GoalEventRegistry($sanitizer, []),
                new PrivacyPolicy($loader),
                $this->createStub(GeoIpResolverInterface::class),
                new AnonymousEventRecorder($this->createStub(EntityManagerInterface::class)),
                new NullLogger(),
                new CustomDataSettings($loader),
                scopes: $resolver,
            ),
        );

        self::assertSame(202, $response->getStatusCode());
        self::assertSame(['status' => 'ignored'], json_decode((string) $response->getContent(), true));
    }

    public function testAnInvalidCatalogOrOverlayFailsClosedWithoutChangingTheDeployment(): void
    {
        [$directory, $loader] = $this->deployment(['anonymous_tracking_enabled' => true]);
        file_put_contents($directory.'/config/website-scopes.yaml', "label: [not-a-mapping]\n");
        $resolver = new WebsiteScopeResolver($loader, new WebsiteScopeCatalog($directory, 'test'));

        $closed = $resolver->forToken('store-token');

        self::assertTrue($closed->isScopeInvalid());
        self::assertFalse((new PrivacyPolicy($closed))->isAnonymousTrackingEnabled());
        self::assertTrue((new PrivacyPolicy($loader))->isAnonymousTrackingEnabled());

        $directory = $this->freshDirectory();
        [$directory, $loader] = $this->deployment(['anonymous_tracking_enabled' => true], $directory);
        $catalog = new WebsiteScopeCatalog($directory, 'test');
        $overlayDirectory = $directory.'/config/website-scopes/websites';
        mkdir($overlayDirectory, 0700, true);
        file_put_contents(
            $directory.'/config/website-scopes/websites/'.SiteScriptConfig::idForToken('store-token').'.yaml',
            "dashboard_enabled: false\n",
        );
        $resolver = new WebsiteScopeResolver($loader, new WebsiteScopeCatalog($directory, 'test'));

        self::assertTrue($resolver->forToken('store-token')->isScopeInvalid());
        self::assertArrayNotHasKey('dashboard_enabled', $loader->all());
    }

    public function testAGroupSharesSettingsAndAWebsiteCanLeaveTheGroup(): void
    {
        [$directory, $loader] = $this->deployment([CollectionProfile::KEY => CollectionProfile::STANDARD]);
        $catalog = new WebsiteScopeCatalog($directory, 'test');
        $catalog->setLabel('Brand', 'Brands');
        $amazon = $catalog->createGroup('Amazon');
        $foods = $catalog->createGroup('Whole Foods');
        $catalog->assign('store-token', $amazon);
        $catalog->assign('grocery-token', $amazon);
        $catalog->saveOverlay('groups', $amazon, [CollectionProfile::KEY => CollectionProfile::STRICT], []);
        $resolver = new WebsiteScopeResolver($loader, $catalog);

        self::assertSame('Brands', $catalog->label()['plural']);
        self::assertSame(CollectionProfile::STRICT, (new CollectionProfile($resolver->forToken('grocery-token')))->name());

        $catalog->assign('grocery-token', $foods);
        self::assertSame(CollectionProfile::STANDARD, (new CollectionProfile($resolver->forToken('grocery-token')))->name());
        self::assertSame(CollectionProfile::STRICT, (new CollectionProfile($resolver->forToken('store-token')))->name());
    }

    public function testSavingASectionDoesNotCopyUnrelatedDeploymentSettings(): void
    {
        [$directory, $loader] = $this->deployment([
            'anonymous_tracking_enabled' => true,
            'anonymous_excluded_paths' => ['/keep'],
            CollectionProfile::KEY => CollectionProfile::STANDARD,
            'branding' => ['name' => 'Deployment'],
        ]);
        $websites = new WebsiteConfigManager($directory);
        $websites->addWebsite('Store', 'store.example', 'store-token');
        $editor = new WebsiteScopeEditor(new WebsiteScopeCatalog($directory, 'test'), new WebsiteScopeResolver($loader, new WebsiteScopeCatalog($directory, 'test')), $websites, $loader);
        $catalog = new WebsiteScopeCatalog($directory, 'test');
        $group = $catalog->createGroup('Amazon');

        $editor->saveCollection('group:'.$group, [
            'anonymous_tracking_enabled' => false,
            'anonymous_excluded_paths' => ['/checkout'],
        ], ['tracking']);

        $saved = $catalog->readOverlay('groups', $group)['values'];
        self::assertSame([
            'anonymous_tracking_enabled' => false,
            'anonymous_excluded_paths' => ['/checkout'],
        ], $saved);
        self::assertSame('Deployment', $loader->get('branding')['name']);
    }

    public function testPublishingAddsANewColumnWithoutChangingAnExistingOne(): void
    {
        [$directory, $loader] = $this->deployment([
            CustomDataSettings::PROPERTIES_KEY => [
                'campaign' => ['description' => 'Campaign', 'consent_required' => true, 'column' => 'campaign'],
            ],
            CustomDataSettings::MAPPINGS_KEY => [],
            CustomDataSettings::PAGE_SEQUENCE_ENABLED_KEY => false,
            CustomDataSettings::PAGE_SEQUENCE_METHOD_KEY => 'session_storage',
        ]);
        $catalog = new WebsiteScopeCatalog($directory, 'test');
        $catalog->saveOverlay('websites', SiteScriptConfig::idForToken('store-token'), [
            CustomDataSettings::PROPERTIES_KEY => [
                'campaign' => ['description' => 'Different', 'consent_required' => false, 'column' => 'other'],
                'plan' => ['description' => 'Plan', 'consent_required' => true, 'column' => 'plan'],
            ],
        ], []);

        $added = (new WebsiteScopePublisher($catalog, new CustomDataSettings($loader)))->publishModel();

        self::assertSame(['plan'], $added);
        $saved = (new CustomDataSettings($loader))->toArray()[CustomDataSettings::PROPERTIES_KEY];
        self::assertSame('campaign', $saved['campaign']['column']);
        self::assertTrue($saved['campaign']['consent_required']);
        self::assertSame('plan', $saved['plan']['column']);
    }

    public function testADirectRequestUsesTheWebsiteStrictProfile(): void
    {
        [$directory, $loader] = $this->deployment([
            CollectionProfile::KEY => CollectionProfile::STANDARD,
            'anonymous_tracking_enabled' => true,
            CustomDataSettings::PROPERTIES_KEY => [
                'utm_medium' => ['description' => 'Medium', 'consent_required' => false, 'column' => ''],
            ],
            CustomDataSettings::MAPPINGS_KEY => [],
        ]);
        $catalog = new WebsiteScopeCatalog($directory, 'test');
        $catalog->saveOverlay('websites', SiteScriptConfig::idForToken('store-token'), [
            CollectionProfile::KEY => CollectionProfile::STRICT,
        ], []);
        $resolver = new WebsiteScopeResolver($loader, $catalog);
        $persisted = null;
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->willReturnCallback(static function (object $event) use (&$persisted): void {
            $persisted = $event;
        });
        $entityManager->expects(self::once())->method('flush');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        $geoResolver = $this->createMock(GeoIpResolverInterface::class);
        $geoResolver->expects(self::never())->method('resolve');
        $websites = $this->createMock(WebsiteConfigManager::class);
        $websites->expects(self::once())->method('findOneByToken')->with('store-token')->willReturn([
            'name' => 'Store',
            'domain' => 'store.example',
            'token' => 'store-token',
        ]);
        $rateLimiter = $this->createStub(IpRateLimiter::class);
        $rateLimiter->method('allow')->willReturn(true);

        $response = (new ReceiveController())(
            Request::create('/api/receive', 'POST', server: [
                'CONTENT_TYPE' => 'application/json',
                'REMOTE_ADDR' => '203.0.113.9',
                'HTTP_ORIGIN' => 'https://store.example',
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone) Mobile Safari',
            ], content: json_encode([
                'websiteToken' => 'store-token',
                'pagePath' => '/pricing?utm_medium=email',
                'eventName' => 'plan_selected',
                'goalEvent' => 'purchase',
                'consentState' => 'granted',
                'visitorId' => 'private-visitor',
                'sessionId' => 'private-session',
                'referrerChannel' => 'search',
                'deviceClass' => 'mobile',
                'viewportBucket' => 'small',
                'screenWidth' => 390,
                InternalTrafficSettings::JSON_KEY => true,
                'customData' => ['utm_medium' => 'private-medium'],
            ], JSON_THROW_ON_ERROR)),
            new EventIngestion(
                $websites,
                $bus,
                $rateLimiter,
                $sanitizer = new PrivacySanitizer(),
                new GoalEventRegistry($sanitizer, [
                        'purchase' => ['label' => 'Purchase', 'enabled' => true, 'anonymous' => true],
                    ]),
                new PrivacyPolicy($loader),
                $geoResolver,
                new AnonymousEventRecorder($entityManager),
                new NullLogger(),
                new CustomDataSettings($loader),
                scopes: $resolver,
            ),
        );

        self::assertSame(202, $response->getStatusCode());
        self::assertSame(['status' => 'recorded', 'mode' => 'anonymous'], json_decode((string) $response->getContent(), true));
        self::assertInstanceOf(Event::class, $persisted);
        self::assertSame('anonymous', $persisted->getPrivacyMode());
        self::assertSame('/pricing', $persisted->getUrl());
        self::assertSame('plan_selected', $persisted->getEventName());
        self::assertSame('purchase', $persisted->getGoalEvent());
        self::assertSame('unknown', $persisted->getReferrer());
        self::assertSame('unknown', $persisted->getDeviceClass());
        self::assertSame('unknown', $persisted->getViewportBucket());
        self::assertNull($persisted->getGeoArea());
        self::assertNull($persisted->getCustomData());
        self::assertNull($persisted->getVisitorId());
        self::assertNull($persisted->getSessionId());
        self::assertSame(CollectionProfile::STANDARD, (new CollectionProfile($loader))->name());
    }

    public function testEnablingPageDepthAgainstAnIncompatibleModelFailsClosed(): void
    {
        [$directory, $loader] = $this->deployment([
            'anonymous_tracking_enabled' => true,
            CustomDataSettings::PROPERTIES_KEY => [
                'page_sequence' => ['description' => 'Historical', 'consent_required' => false, 'type' => 'string', 'column' => ''],
            ],
            CustomDataSettings::MAPPINGS_KEY => [],
            CustomDataSettings::PAGE_SEQUENCE_ENABLED_KEY => false,
        ]);
        $catalog = new WebsiteScopeCatalog($directory, 'test');
        $catalog->saveOverlay('websites', SiteScriptConfig::idForToken('store-token'), [
            CustomDataSettings::PAGE_SEQUENCE_ENABLED_KEY => true,
        ], []);

        $closed = (new WebsiteScopeResolver($loader, $catalog))->forToken('store-token');

        self::assertTrue($closed->isScopeInvalid());
        self::assertFalse((new PrivacyPolicy($closed))->isAnonymousTrackingEnabled());
        self::assertFalse($loader->isScopeInvalid());
        self::assertFalse((new CustomDataSettings($loader))->toArray()[CustomDataSettings::PAGE_SEQUENCE_ENABLED_KEY]);
    }

    public function testATokenizedTrackerEmbedsThatWebsitesProfile(): void
    {
        [$directory, $loader] = $this->deployment([
            CollectionProfile::KEY => CollectionProfile::STANDARD,
            'anonymous_tracking_enabled' => true,
        ]);
        $catalog = new WebsiteScopeCatalog($directory, 'test');
        $catalog->saveOverlay('websites', SiteScriptConfig::idForToken('store-token'), [
            CollectionProfile::KEY => CollectionProfile::STRICT,
        ], []);
        $controller = new ScriptController(
            $loader,
            new InternalTrafficSettings($loader),
            new CustomDataSettings($loader),
            dirname(__DIR__, 2),
            scopes: new WebsiteScopeResolver($loader, $catalog),
        );

        $scoped = $controller(Request::create('/aggregate.js', 'GET', ['token' => 'store-token']));
        $deployment = $controller(Request::create('/aggregate.js'));

        self::assertSame(200, $scoped->getStatusCode());
        self::assertSame(['profile' => CollectionProfile::STRICT], $this->collectionBrowserConfig((string) $scoped->getContent()));
        self::assertSame(['profile' => CollectionProfile::STANDARD], $this->collectionBrowserConfig((string) $deployment->getContent()));
    }

    public function testOneInvalidWebsiteFileLeavesTheOtherWebsiteCollecting(): void
    {
        [$directory, $loader] = $this->deployment([
            CollectionProfile::KEY => CollectionProfile::STANDARD,
            'anonymous_tracking_enabled' => true,
        ]);
        $catalog = new WebsiteScopeCatalog($directory, 'test');
        $brokenPath = $directory.'/config/website-scopes/websites/'.SiteScriptConfig::idForToken('broken-token').'.yaml';
        mkdir(dirname($brokenPath), 0700, true);
        $invalid = "dashboard_enabled: false\n##";
        $valid = "collection_profile: strict\n";
        self::assertSame(strlen($invalid), strlen($valid));
        file_put_contents($brokenPath, $invalid);
        $catalog->saveOverlay('websites', SiteScriptConfig::idForToken('healthy-token'), [
            CollectionProfile::KEY => CollectionProfile::STRICT,
        ], []);
        $resolver = new WebsiteScopeResolver($loader, $catalog);
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(2))->method('executeQuery')->with('SELECT 1');
        $health = new HealthController($connection, $loader, $catalog);

        $first = $health();
        $brokenWebsites = $this->createMock(WebsiteConfigManager::class);
        $brokenWebsites->expects(self::never())->method('findOneByToken');
        $broken = $this->receive($resolver, $loader, $brokenWebsites, 'broken-token', $this->createStub(EntityManagerInterface::class));
        $persisted = null;
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->willReturnCallback(static function (object $event) use (&$persisted): void {
            $persisted = $event;
        });
        $entityManager->expects(self::once())->method('flush');
        $healthyWebsites = $this->createMock(WebsiteConfigManager::class);
        $healthyWebsites->expects(self::once())->method('findOneByToken')->with('healthy-token')->willReturn([
            'name' => 'Healthy',
            'domain' => 'healthy.example',
            'token' => 'healthy-token',
        ]);
        $healthy = $this->receive($resolver, $loader, $healthyWebsites, 'healthy-token', $entityManager, 'https://healthy.example');

        self::assertSame(503, $first->getStatusCode());
        self::assertSame('error', json_decode((string) $first->getContent(), true)['checks']['configuration']['status']);
        self::assertSame(202, $broken->getStatusCode());
        self::assertSame(['status' => 'ignored'], json_decode((string) $broken->getContent(), true));
        self::assertSame(202, $healthy->getStatusCode());
        self::assertSame(['status' => 'recorded', 'mode' => 'anonymous'], json_decode((string) $healthy->getContent(), true));
        self::assertInstanceOf(Event::class, $persisted);
        self::assertSame('healthy-token', $persisted->getWebsiteToken());
        self::assertSame(CollectionProfile::STANDARD, (new CollectionProfile($loader))->name());

        $modified = filemtime($brokenPath);
        self::assertIsInt($modified);
        file_put_contents($brokenPath, $valid);
        touch($brokenPath, $modified);
        clearstatcache(true, $brokenPath);
        self::assertTrue($catalog->hasInvalidOverlay());
        touch($brokenPath, $modified + 5);
        clearstatcache(true, $brokenPath);

        $second = $health();

        self::assertSame(200, $second->getStatusCode());
        self::assertSame(['status' => 'ok'], json_decode((string) $second->getContent(), true)['checks']['configuration']);
        self::assertFalse($resolver->forToken('broken-token')->isScopeInvalid());
        self::assertSame(CollectionProfile::STRICT, (new CollectionProfile($resolver->forToken('healthy-token')))->name());
    }

    /**
     * @return array{profile: string}
     */
    private function collectionBrowserConfig(string $script): array
    {
        self::assertSame(1, preg_match('/var collectionDefaults = (.*);/', $script, $matches));

        return json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
    }

    private function receive(
        WebsiteScopeResolver $resolver,
        AggregateConfigLoader $loader,
        WebsiteConfigManager $websites,
        string $token,
        EntityManagerInterface $entityManager,
        string $origin = 'https://store.example',
    ): \Symfony\Component\HttpFoundation\Response {
        $rateLimiter = $this->createStub(IpRateLimiter::class);
        $rateLimiter->method('allow')->willReturn(true);

        return (new ReceiveController())(
            Request::create('/api/receive', 'POST', server: [
                'CONTENT_TYPE' => 'application/json',
                'REMOTE_ADDR' => '203.0.113.9',
                'HTTP_ORIGIN' => $origin,
            ], content: json_encode([
                'websiteToken' => $token,
                'pagePath' => '/pricing',
                'eventName' => 'view',
                'consentState' => 'granted',
            ], JSON_THROW_ON_ERROR)),
            new EventIngestion(
                $websites,
                $this->createStub(MessageBusInterface::class),
                $rateLimiter,
                new PrivacySanitizer(),
                new GoalEventRegistry(new PrivacySanitizer(), []),
                new PrivacyPolicy($loader),
                $this->createStub(GeoIpResolverInterface::class),
                new AnonymousEventRecorder($entityManager),
                new NullLogger(),
                new CustomDataSettings($loader),
                scopes: $resolver,
            ),
        );
    }

    /**
     * @param array<string, mixed> $settings
     *
     * @return array{0: string, 1: AggregateConfigLoader}
     */
    private function deployment(array $settings, ?string $directory = null): array
    {
        $directory ??= $this->freshDirectory();
        file_put_contents($directory.'/config/aggregate.yaml', \Symfony\Component\Yaml\Yaml::dump($settings));

        return [$directory, new AggregateConfigLoader($directory, 'test')];
    }

    private function freshDirectory(): string
    {
        $directory = sys_get_temp_dir().'/aggregate-scopes-'.bin2hex(random_bytes(6));
        mkdir($directory.'/config', 0700, true);

        return $directory;
    }
}
