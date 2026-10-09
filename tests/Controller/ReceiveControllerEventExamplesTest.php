<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\ReceiveController;
use App\Entity\Event;
use App\Message\TrackEventMessage;
use App\MessageHandler\TrackEventHandler;
use App\Security\IpRateLimiter;
use App\Service\AggregateConfigLoader;
use App\Service\AnonymousEventRecorder;
use App\Service\CustomDataSettings;
use App\Service\EventExampleGenerator;
use App\Service\GeoIp\GeoIpResolverInterface;
use App\Service\GoalEventRegistry;
use App\Service\InternalTrafficSettings;
use App\Service\PrivacyPolicy;
use App\Service\PrivacySanitizer;
use App\Service\TrackingFailureRetryRunner;
use App\Service\WebsiteConfigManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class ReceiveControllerEventExamplesTest extends TestCase
{
    #[DataProvider('examples')]
    public function testExportedJsonSurvivesDirectIngestionAndPersistenceBoundaries(string $mode, array $model): void
    {
        $config = $this->config($model);
        $generator = new EventExampleGenerator(new CustomDataSettings($config));
        $bundle = json_decode($generator->exportJson($mode), true, flags: JSON_THROW_ON_ERROR);
        $payload = $bundle['examples'][$mode]['payload'];
        $event = $this->ingestAndPersist($payload, $config, $mode);

        // Every standard-profile event records the organization-traffic flag.
        self::assertFalse($payload[InternalTrafficSettings::JSON_KEY]);
        self::assertSame([...$payload['customData'], InternalTrafficSettings::JSON_KEY => false], $event->getCustomData());
        self::assertSame($payload['eventName'], $event->getEventName());
        self::assertSame($payload['pagePath'], $event->getUrl());
        self::assertSame($payload['referrerChannel'], $event->getReferrer());
        self::assertSame($payload['deviceClass'], $event->getDeviceClass());
        self::assertSame($payload['viewportBucket'], $event->getViewportBucket());
        self::assertNull($event->getGoalEvent());
        self::assertFalse($event->isInternalTraffic());
        self::assertStringNotContainsString('203.0.113.42', serialize($event));
        self::assertStringNotContainsString('Chrome/126.0', serialize($event));
        if ($mode === 'anonymous') {
            $this->assertAnonymous($event);
        } else {
            self::assertSame('granted', $event->getConsentState());
            self::assertSame('synthetic-visitor', $event->getVisitorId());
            self::assertSame('synthetic-session', $event->getSessionId());
            self::assertSame(1440, $event->getScreenWidth());
        }
    }

    public static function examples(): iterable
    {
        foreach (['anonymous', 'enhanced'] as $mode) {
            yield $mode.' defaults' => [$mode, []];
            yield $mode.' empty model' => [$mode, ['custom_data_properties' => [], 'query_parameter_mappings' => []]];
            yield $mode.' explicit scalar model and reserved keys' => [$mode, [
                'custom_data_properties' => [
                    'utm_medium' => ['consent_required' => false],
                    'utm_campaign' => ['consent_required' => false],
                    'campaign.kind' => ['consent_required' => false],
                    'plan' => ['consent_required' => true],
                    'quantity' => ['type' => 'integer', 'consent_required' => false],
                    'amount' => ['type' => 'double'],
                    'flag' => ['type' => 'boolean', 'consent_required' => false],
                    InternalTrafficSettings::JSON_KEY => ['type' => 'boolean', 'consent_required' => false, 'column' => 'organization_traffic'],
                ],
                'query_parameter_mappings' => ['channel' => 'utm_medium', 'utm_source' => 'campaign.kind'],
            ]];
        }
    }

    public function testRecommendedEcommercePayloadPassesTheProposedModelsIngestionPolicy(): void
    {
        $bundle = (new EventExampleGenerator(new CustomDataSettings($this->config([]))))->generate('ecommerce');
        $adoptedConfig = $this->config($bundle['recommended_model']);

        foreach (['anonymous', 'enhanced'] as $mode) {
            $payload = $bundle['examples'][$mode]['payload'];
            $event = $this->ingestAndPersist($payload, $adoptedConfig, $mode);
            self::assertSame([...(array) $payload['customData'], InternalTrafficSettings::JSON_KEY => false], $event->getCustomData());
            self::assertSame('purchase', $event->getEventName());
            self::assertNull($event->getGoalEvent());
        }
    }

    #[DataProvider('nonConsentStates')]
    public function testChangingTheEnhancedExampleToNonConsentCannotRetainEnhancedFields(mixed $consent): void
    {
        $config = $this->config([
            'custom_data_properties' => ['utm_medium' => ['consent_required' => false], 'plan' => []],
            'query_parameter_mappings' => [],
        ]);
        $bundle = (new EventExampleGenerator(new CustomDataSettings($config)))->generate();
        $payload = $bundle['examples']['enhanced']['payload'];
        $payload['consentState'] = $consent;
        $payload['customData']->{InternalTrafficSettings::JSON_KEY} = 'forged-marker-value';
        $payload['customData']->unmodeled = 'private-property';
        $event = $this->ingestAndPersist($payload, $config, 'anonymous');

        $this->assertAnonymous($event);
        self::assertSame(['utm_medium' => 'email', InternalTrafficSettings::JSON_KEY => false], $event->getCustomData());
        self::assertStringNotContainsString('private-property', serialize($event));
        self::assertStringNotContainsString('forged-marker-value', serialize($event));
    }

    public static function nonConsentStates(): iterable
    {
        yield ['denied'];
        yield ['false'];
        yield [true];
        yield [null];
        yield [['granted']];
    }

    #[DataProvider('collectionControls')]
    public function testGeneratedExamplesCannotBypassDisabledOrExcludedCollection(string $mode, array $controls): void
    {
        $config = $this->config($controls);
        $payload = (new EventExampleGenerator(new CustomDataSettings($config)))->generate()['examples'][$mode]['payload'];
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        $websites = $this->createMock(WebsiteConfigManager::class);
        $websites->expects(self::never())->method('findOneByToken');
        $limiter = $this->createMock(IpRateLimiter::class);
        $limiter->expects(self::never())->method('allow');
        $geo = $this->createMock(GeoIpResolverInterface::class);
        $geo->expects(self::never())->method('resolve');

        $response = $this->invoke($payload, $config, $entityManager, $bus, $websites, $limiter, $geo);

        self::assertSame(202, $response->getStatusCode());
        self::assertSame(['status' => 'ignored'], json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR));
    }

    public static function collectionControls(): iterable
    {
        foreach (['anonymous', 'enhanced'] as $mode) {
            yield $mode.' disabled' => [$mode, ['anonymous_tracking_enabled' => false]];
            yield $mode.' excluded' => [$mode, ['anonymous_excluded_paths' => ['/example/**']]];
        }
    }

    private function ingestAndPersist(array $payload, AggregateConfigLoader $config, string $mode): Event
    {
        $persisted = null;
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->willReturnCallback(static function (object $event) use (&$persisted): void {
            self::assertInstanceOf(Event::class, $event);
            $event->enforcePrivacyInvariants();
            $persisted = $event;
        });
        $entityManager->expects(self::once())->method('flush');
        $bus = $this->createMock(MessageBusInterface::class);
        $trackingFailures = $this->createStub(TrackingFailureRetryRunner::class);
        if ($mode === 'enhanced') {
            $bus->expects(self::once())->method('dispatch')->willReturnCallback(static function (object $message) use ($entityManager, $trackingFailures): Envelope {
                self::assertInstanceOf(TrackEventMessage::class, $message);
                self::assertStringNotContainsString('203.0.113.42', serialize($message));
                self::assertStringNotContainsString('Chrome/126.0', serialize($message));
                (new TrackEventHandler($entityManager, $trackingFailures, new NullLogger()))($message);

                return new Envelope($message);
            });
        } else {
            $bus->expects(self::never())->method('dispatch');
        }
        $websites = $this->createMock(WebsiteConfigManager::class);
        $websites->expects(self::once())->method('findOneByToken')->with('REPLACE_WITH_PUBLIC_WEBSITE_TOKEN')
            ->willReturn(['domain' => 'example.com', 'token' => 'REPLACE_WITH_PUBLIC_WEBSITE_TOKEN']);
        $limiter = $this->createStub(IpRateLimiter::class);
        $limiter->method('allow')->willReturn(true);
        $geo = $this->createStub(GeoIpResolverInterface::class);
        $geo->method('resolve')->willReturn(null);

        $response = $this->invoke($payload, $config, $entityManager, $bus, $websites, $limiter, $geo);

        self::assertSame(202, $response->getStatusCode());
        self::assertSame($mode, json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR)['mode']);
        self::assertInstanceOf(Event::class, $persisted);
        self::assertSame($mode, $persisted->getPrivacyMode());

        return $persisted;
    }

    private function invoke(
        array $payload,
        AggregateConfigLoader $config,
        EntityManagerInterface $entityManager,
        MessageBusInterface $bus,
        WebsiteConfigManager $websites,
        IpRateLimiter $limiter,
        GeoIpResolverInterface $geo,
    ): Response {
        $request = Request::create('/api/receive', 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ORIGIN' => 'https://www.example.com',
            'HTTP_USER_AGENT' => 'Mozilla/5.0 Chrome/126.0 Safari/537.36',
            'REMOTE_ADDR' => '203.0.113.42',
        ], content: json_encode($payload, JSON_THROW_ON_ERROR));
        $sanitizer = new PrivacySanitizer();

        return (new ReceiveController())(
            $request, $websites, $bus, $limiter, $sanitizer,
            new GoalEventRegistry($sanitizer, []), new PrivacyPolicy($config), $geo,
            new AnonymousEventRecorder($entityManager), new NullLogger(),
            new CustomDataSettings($config),
        );
    }

    private function assertAnonymous(Event $event): void
    {
        self::assertNull($event->getConsentState());
        self::assertNull($event->getVisitorId());
        self::assertNull($event->getSessionId());
        self::assertNull($event->getScreenWidth());
        self::assertNull($event->getGeneralizedUserAgent());
        self::assertSame('00:00.000000', $event->getCreatedAt()->format('i:s.u'));
    }

    private function config(array $values): AggregateConfigLoader
    {
        $config = $this->createStub(AggregateConfigLoader::class);
        $config->method('all')->willReturn($values);
        $config->method('getBoolWithEnvFallback')->willReturn($values['anonymous_tracking_enabled'] ?? true);
        $config->method('getWithEnvFallback')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => $values[$key] ?? $default);

        return $config;
    }
}
