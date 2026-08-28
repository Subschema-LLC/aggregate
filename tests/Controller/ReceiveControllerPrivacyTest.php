<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\ReceiveController;
use App\Entity\Event;
use App\Message\TrackEventMessage;
use App\Security\IpRateLimiter;
use App\Service\AggregateConfigLoader;
use App\Service\AnonymousEventRecorder;
use App\Service\GeoIp\GeoArea;
use App\Service\GeoIp\GeoIpResolverInterface;
use App\Service\GoalEventRegistry;
use App\Service\PrivacyPolicy;
use App\Service\PrivacySanitizer;
use App\Service\WebsiteConfigManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class ReceiveControllerPrivacyTest extends TestCase
{
    public function testDeniedNamedClickIsRecordedAnonymouslyWithoutQueueingEnhancedFields(): void
    {
        $persisted = null;
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('persist')
            ->willReturnCallback(static function (object $event) use (&$persisted): void {
                $persisted = $event;
            });
        $entityManager->expects(self::once())->method('flush');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        $geoResolver = $this->createMock(GeoIpResolverInterface::class);
        $geoResolver->expects(self::once())
            ->method('resolve')
            ->with('203.0.113.42')
            ->willReturn(GeoArea::continent('NA'));

        $response = $this->invoke(
            payload: [
                'websiteToken' => 'public-site-token',
                'pagePath' => '/orders/123?access_token=secret#private',
                'referrer' => 'https://google.com/search?q=private',
                'eventName' => 'cta:click',
                'geoArea' => 'country:RU',
                'deviceClass' => 'desktop',
                'viewportBucket' => 'large',
                'screenWidth' => 1440,
                'consentState' => 'denied',
                'visitorId' => 'forged-visitor',
                'sessionId' => 'forged-session',
                'goalEvent' => 'purchase',
                'eventData' => ['email' => 'person@example.com'],
            ],
            bus: $bus,
            recorder: new AnonymousEventRecorder($entityManager),
            geoResolver: $geoResolver,
        );

        self::assertSame(202, $response->getStatusCode());
        self::assertSame(
            ['status' => 'recorded', 'mode' => 'anonymous'],
            json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR),
        );
        self::assertInstanceOf(Event::class, $persisted);
        self::assertSame('anonymous', $persisted->getPrivacyMode());
        self::assertSame('cta:click', $persisted->getEventName());
        self::assertSame('/orders/_redacted', $persisted->getUrl());
        self::assertSame('search', $persisted->getReferrer());
        self::assertSame('desktop', $persisted->getDeviceClass());
        self::assertSame('large', $persisted->getViewportBucket());
        self::assertSame('continent:NA', $persisted->getGeoArea());
        self::assertSame('UTC', $persisted->getCreatedAt()->getTimezone()->getName());
        self::assertSame('00:00.000000', $persisted->getCreatedAt()->format('i:s.u'));
        self::assertNull($persisted->getGeneralizedUserAgent());
        self::assertNull($persisted->getScreenWidth());
        self::assertNull($persisted->getVisitorId());
        self::assertNull($persisted->getSessionId());
        self::assertNull($persisted->getConsentState());
        self::assertSame('purchase', $persisted->getGoalEvent());
        self::assertNull($persisted->getCustomData());
    }

    public function testUnknownNamedEventIsAlsoAcceptedAnonymously(): void
    {
        $persisted = null;
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('persist')
            ->willReturnCallback(static function (object $event) use (&$persisted): void {
                $persisted = $event;
            });
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $response = $this->invoke(
            payload: [
                'websiteToken' => 'public-site-token',
                'pagePath' => '/signup',
                'eventName' => 'registration.started',
                'consentState' => 'unknown',
                'sessionId' => 'must-not-imply-consent',
            ],
            bus: $bus,
            recorder: new AnonymousEventRecorder($entityManager),
            geoResolver: $this->geoResolver(GeoArea::country('US')),
        );

        self::assertSame(202, $response->getStatusCode());
        self::assertInstanceOf(Event::class, $persisted);
        self::assertSame('registration.started', $persisted->getEventName());
        self::assertSame('country:US', $persisted->getGeoArea());
        self::assertNull($persisted->getSessionId());
    }

    public function testGrantedEventQueuesSanitizedFieldsWithOneExactOccurrenceTime(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');
        $queued = null;
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(function (object $message) use (&$queued): Envelope {
                $queued = $message;

                return new Envelope($message);
            });

        $before = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $response = $this->invoke(
            payload: [
                'websiteToken' => 'public-site-token',
                'url' => 'https://example.com/orders/123?access_token=secret#private',
                'referrer' => 'https://google.com/search?q=private',
                'eventName' => 'purchase.completed',
                'geoArea' => 'country:RU',
                'deviceClass' => 'desktop',
                'viewportBucket' => 'large',
                'goalEvent' => 'purchase',
                'screenWidth' => 1440,
                'eventData' => ['plan' => "pro\0", 'nested' => ['ignored']],
                'consentState' => 'granted',
                'visitorId' => 'visitor_abc',
                'sessionId' => 'session_abc',
            ],
            bus: $bus,
            recorder: new AnonymousEventRecorder($entityManager),
            geoResolver: $this->geoResolver(GeoArea::country('US')),
        );
        $after = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        self::assertSame(202, $response->getStatusCode());
        self::assertInstanceOf(TrackEventMessage::class, $queued);
        self::assertSame('/orders/_redacted', $queued->pagePath);
        self::assertSame('search', $queued->referrerChannel);
        self::assertSame('purchase.completed', $queued->eventName);
        self::assertSame('desktop', $queued->deviceClass);
        self::assertSame('large', $queued->viewportBucket);
        self::assertSame(['plan' => 'pro'], $queued->eventData);
        self::assertSame('visitor_abc', $queued->visitorId);
        self::assertSame('session_abc', $queued->sessionId);
        self::assertSame('country:US', $queued->geoArea);
        self::assertSame('Chrome / desktop', $queued->generalizedUserAgent);
        self::assertSame('purchase', $queued->goalEvent);
        self::assertGreaterThanOrEqual($before, $queued->occurredAt);
        self::assertLessThanOrEqual($after, $queued->occurredAt);
        self::assertNotSame('00:00.000000', $queued->occurredAt->format('i:s.u'));

        $queuedFields = array_keys(get_object_vars($queued));
        self::assertNotContains('ip', $queuedFields);
        self::assertNotContains('userAgent', $queuedFields);
        self::assertNotContains('consentState', $queuedFields);
    }

    public function testInvalidIdentifierLikeEventNameIsRejectedBeforeStorage(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $response = $this->invoke(
            payload: [
                'websiteToken' => 'public-site-token',
                'pagePath' => '/orders',
                'eventName' => 'order_123456',
                'consentState' => 'denied',
            ],
            bus: $bus,
            recorder: new AnonymousEventRecorder($entityManager),
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['error' => 'eventName is invalid'], json_decode((string) $response->getContent(), true));
    }

    #[DataProvider('rejectedAnonymousGoals')]
    public function testRejectedAnonymousGoalDoesNotRejectTheUnderlyingEventOrEchoTheCandidate(string $candidate): void
    {
        $persisted = null;
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('persist')
            ->willReturnCallback(static function (object $event) use (&$persisted): void {
                $persisted = $event;
            });
        $entityManager->expects(self::once())->method('flush');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        $response = $this->invoke(
            payload: [
                'websiteToken' => 'public-site-token',
                'pagePath' => '/contact',
                'eventName' => 'form_submit',
                'goalEvent' => $candidate,
                'consentState' => 'denied',
            ],
            bus: $bus,
            recorder: new AnonymousEventRecorder($entityManager),
        );

        self::assertSame(202, $response->getStatusCode());
        self::assertSame(
            ['status' => 'recorded', 'mode' => 'anonymous', 'warnings' => ['goal_not_allowed']],
            json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR),
        );
        self::assertStringNotContainsString($candidate, (string) $response->getContent());
        self::assertInstanceOf(Event::class, $persisted);
        self::assertNull($persisted->getGoalEvent());
    }

    public static function rejectedAnonymousGoals(): iterable
    {
        yield 'unknown' => ['unconfigured_goal'];
        yield 'disabled' => ['download'];
        yield 'anonymous disallowed' => ['subscription'];
        yield 'unsafe identifier-like value' => ['private@example.com'];
        yield 'case mismatch' => ['Purchase'];
        yield 'whitespace mismatch' => [' purchase '];
    }

    #[DataProvider('absentAnonymousGoals')]
    public function testMissingAndBlankAnonymousGoalsDoNotProduceWarnings(bool $includeGoal, mixed $candidate): void
    {
        $persisted = null;
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('persist')
            ->willReturnCallback(static function (object $event) use (&$persisted): void {
                $persisted = $event;
            });
        $entityManager->expects(self::once())->method('flush');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        $payload = [
            'websiteToken' => 'public-site-token',
            'pagePath' => '/contact',
            'eventName' => 'form_submit',
            'consentState' => 'denied',
        ];
        if ($includeGoal) {
            $payload['goalEvent'] = $candidate;
        }

        $response = $this->invoke(
            payload: $payload,
            bus: $bus,
            recorder: new AnonymousEventRecorder($entityManager),
        );

        self::assertSame(202, $response->getStatusCode());
        self::assertSame(
            ['status' => 'recorded', 'mode' => 'anonymous'],
            json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR),
        );
        self::assertInstanceOf(Event::class, $persisted);
        self::assertNull($persisted->getGoalEvent());
    }

    public static function absentAnonymousGoals(): iterable
    {
        yield 'missing' => [false, null];
        yield 'explicit null' => [true, null];
        yield 'empty string' => [true, ''];
        yield 'blank string' => [true, " \t\n"];
    }

    public function testRejectedEnhancedGoalIsOmittedWhileTheEventIsQueued(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $queued = null;
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(function (object $message) use (&$queued): Envelope {
                $queued = $message;

                return new Envelope($message);
            });

        $response = $this->invoke(
            payload: [
                'websiteToken' => 'public-site-token',
                'pagePath' => '/checkout',
                'eventName' => 'purchase.completed',
                'goalEvent' => 'unconfigured_goal',
                'consentState' => 'granted',
            ],
            bus: $bus,
            recorder: new AnonymousEventRecorder($entityManager),
        );

        self::assertSame(202, $response->getStatusCode());
        self::assertSame(
            ['status' => 'accepted', 'mode' => 'enhanced', 'warnings' => ['goal_not_allowed']],
            json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR),
        );
        self::assertInstanceOf(TrackEventMessage::class, $queued);
        self::assertNull($queued->goalEvent);
    }

    public function testDeploymentKillSwitchBlocksEvenGrantedEvents(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        $rateLimiter = $this->createMock(IpRateLimiter::class);
        $rateLimiter->expects(self::never())->method('allow');
        $geoResolver = $this->createMock(GeoIpResolverInterface::class);
        $geoResolver->expects(self::never())->method('resolve');

        $response = $this->invoke(
            payload: [
                'websiteToken' => 'public-site-token',
                'pagePath' => '/pricing',
                'eventName' => 'purchase',
                'consentState' => 'granted',
            ],
            bus: $bus,
            recorder: new AnonymousEventRecorder($entityManager),
            config: $this->privacyConfig(enabled: false),
            rateLimiter: $rateLimiter,
            geoResolver: $geoResolver,
        );

        self::assertSame(202, $response->getStatusCode());
        self::assertSame(['status' => 'ignored'], json_decode((string) $response->getContent(), true));
    }

    public function testCanonicalizedExcludedPathBlocksEvenGrantedEvents(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        $rateLimiter = $this->createMock(IpRateLimiter::class);
        $rateLimiter->expects(self::never())->method('allow');
        $geoResolver = $this->createMock(GeoIpResolverInterface::class);
        $geoResolver->expects(self::never())->method('resolve');

        $response = $this->invoke(
            payload: [
                'websiteToken' => 'public-site-token',
                'pagePath' => '/public/%252e%252e/account%252Fprofile',
                'eventName' => 'view',
                'consentState' => 'granted',
            ],
            bus: $bus,
            recorder: new AnonymousEventRecorder($entityManager),
            config: $this->privacyConfig(excludedPaths: ['/account/**']),
            rateLimiter: $rateLimiter,
            geoResolver: $geoResolver,
        );

        self::assertSame(202, $response->getStatusCode());
        self::assertSame(['status' => 'ignored'], json_decode((string) $response->getContent(), true));
    }

    public function testIngestionFailureLogsNoPayloadOrRequestMetadata(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('persist')->willThrowException(
            new \RuntimeException('database rejected private@example.com'),
        );
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(
                'Failed to ingest analytics event',
                self::callback(static function (array $context): bool {
                    $serialized = json_encode($context, JSON_THROW_ON_ERROR);

                    return $context === ['exception_class' => \RuntimeException::class]
                        && !str_contains($serialized, 'private@example.com')
                        && !str_contains($serialized, 'example.com');
                }),
            );

        $response = $this->invoke(
            payload: [
                'websiteToken' => 'public-site-token',
                'pagePath' => '/pricing',
                'eventName' => 'button_click',
                'eventData' => ['email' => 'private@example.com'],
                'consentState' => 'denied',
            ],
            bus: $this->createStub(MessageBusInterface::class),
            recorder: new AnonymousEventRecorder($entityManager),
            logger: $logger,
        );

        self::assertSame(500, $response->getStatusCode());
        self::assertSame(['error' => 'Ingestion failed'], json_decode((string) $response->getContent(), true));
    }

    private function invoke(
        array $payload,
        MessageBusInterface $bus,
        AnonymousEventRecorder $recorder,
        ?AggregateConfigLoader $config = null,
        ?LoggerInterface $logger = null,
        ?IpRateLimiter $rateLimiter = null,
        ?GeoIpResolverInterface $geoResolver = null,
        ?GoalEventRegistry $goalEvents = null,
    ): \Symfony\Component\HttpFoundation\Response {
        $config ??= $this->privacyConfig();
        $logger ??= new NullLogger();
        $rateLimiter ??= $this->rateLimiter();
        $geoResolver ??= $this->geoResolver();
        $goalEvents ??= $this->goalEvents();

        return (new ReceiveController())(
            $this->request($payload),
            $this->websiteManager(),
            $bus,
            $rateLimiter,
            new PrivacySanitizer(),
            $goalEvents,
            new PrivacyPolicy($config),
            $geoResolver,
            $recorder,
            $logger,
        );
    }

    private function privacyConfig(bool $enabled = true, array $excludedPaths = []): AggregateConfigLoader
    {
        $config = $this->createStub(AggregateConfigLoader::class);
        $config->method('getBoolWithEnvFallback')->willReturn($enabled);
        $config->method('getWithEnvFallback')
            ->willReturnCallback(static function (string $key, mixed $default = null) use ($excludedPaths): mixed {
                return $key === 'anonymous_excluded_paths' ? $excludedPaths : $default;
            });

        return $config;
    }

    private function request(array $payload): Request
    {
        return Request::create(
            '/api/receive',
            'POST',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ORIGIN' => 'https://www.example.com',
                'HTTP_USER_AGENT' => 'Mozilla/5.0 Chrome/126.0 Safari/537.36',
                'REMOTE_ADDR' => '203.0.113.42',
            ],
            content: json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }

    private function websiteManager(): WebsiteConfigManager
    {
        $manager = $this->createStub(WebsiteConfigManager::class);
        $manager->method('findOneByToken')
            ->willReturn(['name' => 'Example', 'domain' => 'example.com', 'token' => 'public-site-token']);

        return $manager;
    }

    private function rateLimiter(): IpRateLimiter
    {
        $limiter = $this->createStub(IpRateLimiter::class);
        $limiter->method('allow')->willReturn(true);

        return $limiter;
    }

    private function geoResolver(?GeoArea $area = null): GeoIpResolverInterface
    {
        $resolver = $this->createStub(GeoIpResolverInterface::class);
        $resolver->method('resolve')->willReturn($area);

        return $resolver;
    }

    private function goalEvents(): GoalEventRegistry
    {
        return new GoalEventRegistry(new PrivacySanitizer(), [
            'purchase' => [
                'label' => 'Purchase',
                'enabled' => true,
                'anonymous' => true,
            ],
            'subscription' => [
                'label' => 'Subscription',
                'enabled' => true,
                'anonymous' => false,
            ],
            'download' => [
                'label' => 'Download',
                'enabled' => false,
                'anonymous' => true,
            ],
        ]);
    }
}
