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
use App\Service\GeoIp\GeoArea;
use App\Service\GeoIp\GeoIpResolverInterface;
use App\Service\GoalEventRegistry;
use App\Service\InternalTrafficSettings;
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
    public function testInvalidReportingGlossaryDoesNotBlockDirectAnonymousRequests(): void
    {
        $directory = sys_get_temp_dir().'/aggregate-glossary-ingestion-'.bin2hex(random_bytes(8));
        mkdir($directory.'/config', 0700, true);
        file_put_contents($directory.'/config/aggregate.yaml', "bi_glossary:\n  locales: [invalid_LOCALE]\n  values: private-glossary-text\n");
        try {
            $config = new AggregateConfigLoader($directory, 'test');
            $entityManager = $this->createMock(EntityManagerInterface::class);
            $entityManager->expects(self::once())->method('persist');
            $entityManager->expects(self::once())->method('flush');
            $recorder = new AnonymousEventRecorder($entityManager);
            $bus = $this->createMock(MessageBusInterface::class);
            $bus->expects(self::never())->method('dispatch');
            $response = $this->invoke(
                ['websiteToken' => 'public-site-token', 'eventName' => 'view', 'pagePath' => '/pricing', 'consentState' => 'denied'],
                $bus, $recorder, $config,
            );
            self::assertSame(202, $response->getStatusCode());
            self::assertStringNotContainsString('glossary', (string) $response->getContent());
        } finally {
            unlink($directory.'/config/aggregate.yaml');
            rmdir($directory.'/config');
            rmdir($directory);
        }
    }

    #[DataProvider('anonymousConsentStates')]
    public function testAnonymousEventsRetainOnlyExplicitlyApprovedSanitizedProperties(string $consentState): void
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
                'pagePath' => '/pricing?utm_campaign=private#fragment',
                'eventName' => 'plan_selected',
                'consentState' => $consentState,
                'visitorId' => 'private-visitor',
                'sessionId' => 'private-session',
                InternalTrafficSettings::JSON_KEY => true,
                'customData' => [
                    'plan' => "pro\0",
                    'zero' => 0,
                    'flag' => false,
                    'optional' => null,
                    'nested' => ['private' => 'nested-value'],
                    'email' => 'private@example.com',
                    'unconfigured' => 'private-unconfigured-value',
                    InternalTrafficSettings::JSON_KEY => 'private-marker-value',
                ],
            ],
            bus: $bus,
            recorder: new AnonymousEventRecorder($entityManager),
            config: $this->privacyConfig(
                customDataProperties: [
                    'plan' => ['consent_required' => false],
                    'zero' => ['consent_required' => false],
                    'flag' => ['consent_required' => false],
                    'optional' => ['consent_required' => false],
                    'nested' => ['consent_required' => false],
                    'email' => ['consent_required' => true],
                ],
            ),
        );

        self::assertSame(202, $response->getStatusCode());
        self::assertInstanceOf(Event::class, $persisted);
        $persisted->enforcePrivacyInvariants();
        self::assertSame([
            'plan' => 'pro',
            'zero' => 0,
            'flag' => false,
            'optional' => null,
            InternalTrafficSettings::JSON_KEY => true,
        ], $persisted->getCustomData());
        self::assertSame('/pricing', $persisted->getUrl());
        self::assertSame('anonymous', $persisted->getPrivacyMode());
        self::assertNull($persisted->getConsentState());
        self::assertNull($persisted->getVisitorId());
        self::assertNull($persisted->getSessionId());
        self::assertNull($persisted->getGeneralizedUserAgent());
        self::assertNull($persisted->getScreenWidth());
        self::assertSame('00:00.000000', $persisted->getCreatedAt()->format('i:s.u'));
        self::assertStringNotContainsString('private', serialize($persisted));
    }

    /**
     * customData is the payload's property object; eventData is its earlier
     * name. Direct requests under either name pass the same server filtering,
     * and when both are sent only customData is read.
     */
    #[DataProvider('propertyPayloads')]
    public function testCustomDataAndLegacyEventDataPassTheSameFilteringInBothModes(array $properties, string $consentState, ?array $expected): void
    {
        $persisted = null;
        $queued = null;
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(static function (object $event) use (&$persisted): void {
            $persisted = $event;
        });
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (object $message) use (&$queued): Envelope {
            $queued = $message;

            return new Envelope($message);
        });

        $response = $this->invoke(
            payload: ['websiteToken' => 'public-site-token', 'pagePath' => '/pricing', 'eventName' => 'plan_selected', 'consentState' => $consentState] + $properties,
            bus: $bus,
            recorder: new AnonymousEventRecorder($entityManager),
            config: $this->privacyConfig(customDataProperties: [
                'plan' => ['consent_required' => false],
                'email' => ['consent_required' => true],
            ]),
        );

        self::assertSame(202, $response->getStatusCode());
        if ($consentState === 'granted') {
            self::assertInstanceOf(TrackEventMessage::class, $queued);
            self::assertSame($expected, $queued->eventData);
        } else {
            self::assertInstanceOf(Event::class, $persisted);
            self::assertSame([...($expected ?? []), InternalTrafficSettings::JSON_KEY => false], $persisted->getCustomData());
            self::assertStringNotContainsString('person@example.com', serialize($persisted));
            self::assertStringNotContainsString('unconfigured', serialize($persisted));
        }
    }

    public static function propertyPayloads(): iterable
    {
        $submitted = ['plan' => 'pro', 'email' => 'person@example.com', 'unconfigured' => 'unconfigured-value'];
        foreach (['customData', 'eventData'] as $key) {
            yield $key.' without consent' => [[$key => $submitted], 'denied', ['plan' => 'pro']];
            // Consent permits declared and other scalar properties alike.
            yield $key.' with consent' => [[$key => $submitted], 'granted', $submitted];
        }
        $both = ['customData' => ['plan' => 'preferred'], 'eventData' => ['plan' => 'legacy', 'email' => 'person@example.com']];
        yield 'both names: customData wins without consent' => [$both, 'denied', ['plan' => 'preferred']];
        yield 'both names: customData wins with consent' => [$both, 'granted', ['plan' => 'preferred']];
        yield 'an empty customData is not replaced by eventData' => [['customData' => null, 'eventData' => ['plan' => 'legacy']], 'denied', null];
    }

    public static function anonymousConsentStates(): iterable
    {
        yield 'denied' => ['denied'];
        yield 'unknown' => ['unknown'];
        yield 'empty' => [''];
    }

    public function testInvalidCustomPropertyPolicyPreventsRecordingInsteadOfGrantingConsent(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $response = $this->invoke(
            payload: [
                'websiteToken' => 'public-site-token',
                'pagePath' => '/pricing',
                'consentState' => 'denied',
                'customData' => ['email' => 'private@example.com'],
            ],
            bus: $bus,
            recorder: new AnonymousEventRecorder($entityManager),
            config: $this->privacyConfig(customDataProperties: [
                'email' => ['consent_required' => 'false'],
            ]),
        );

        self::assertSame(500, $response->getStatusCode());
        self::assertSame(['error' => 'Ingestion failed'], json_decode((string) $response->getContent(), true));
    }

    #[DataProvider('internalTrafficInputs')]
    public function testOnlyStrictTrueMarksTrafficAndCustomDataCannotOverrideIt(
        string $consentState,
        mixed $submitted,
        bool $includeFlag,
        bool $expected,
    ): void {
        $persisted = null;
        $queued = null;
        $enhanced = $consentState === 'granted';
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($enhanced ? self::never() : self::once())
            ->method('persist')
            ->willReturnCallback(static function (object $event) use (&$persisted): void {
                $persisted = $event;
            });
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($enhanced ? self::once() : self::never())
            ->method('dispatch')
            ->willReturnCallback(static function (object $message) use (&$queued): Envelope {
                $queued = $message;

                return new Envelope($message);
            });
        $payload = [
            'websiteToken' => 'public-site-token',
            'pagePath' => '/pricing',
            'eventName' => 'button_click',
            'consentState' => $consentState,
            'internalTrafficName' => 'raw-marker-name-must-not-be-retained',
            'internalTrafficValue' => 'raw-marker-value-must-not-be-retained',
            'visitorId' => 'enhanced-visitor',
            'sessionId' => 'enhanced-session',
            // Neither the browser marker's name nor a property of the same
            // key can set the flag.
            'orgInternalTraffic' => true,
            'internalTraffic' => true,
            'customData' => [InternalTrafficSettings::JSON_KEY => !$expected, 'orgInternalTraffic' => true, 'plan' => 'pro'],
        ];
        unset($payload[InternalTrafficSettings::JSON_KEY]);
        if ($includeFlag) {
            $payload[InternalTrafficSettings::JSON_KEY] = $submitted;
        }

        $response = $this->invoke(
            payload: $payload,
            bus: $bus,
            recorder: new AnonymousEventRecorder($entityManager),
            config: $this->privacyConfig(customDataProperties: [
                'plan' => ['consent_required' => false],
                InternalTrafficSettings::JSON_KEY => ['type' => 'boolean', 'consent_required' => false, 'column' => 'staff'],
            ]),
        );

        self::assertSame(202, $response->getStatusCode());
        if ($enhanced) {
            self::assertInstanceOf(TrackEventMessage::class, $queued);
            self::assertSame($expected, $queued->internalTraffic);
            self::assertSame(['orgInternalTraffic' => true, 'plan' => 'pro'], $queued->eventData);
            self::assertStringNotContainsString('raw-marker-', serialize($queued));
        } else {
            self::assertInstanceOf(Event::class, $persisted);
            $persisted->enforcePrivacyInvariants();
            self::assertSame(['plan' => 'pro', InternalTrafficSettings::JSON_KEY => $expected], $persisted->getCustomData());
            self::assertSame($expected, $persisted->isInternalTraffic());
            self::assertNull($persisted->getVisitorId());
            self::assertNull($persisted->getSessionId());
            self::assertNull($persisted->getConsentState());
            self::assertSame('00:00.000000', $persisted->getCreatedAt()->format('i:s.u'));
            self::assertStringNotContainsString('raw-marker-', serialize($persisted));
        }
    }

    public static function internalTrafficInputs(): iterable
    {
        foreach (['unknown', 'denied', 'granted'] as $consentState) {
            yield $consentState.' missing' => [$consentState, null, false, false];
            foreach ([true, false, 'true', 'false', 1, 0, null, ['name' => 'orgInternalTraffic', 'value' => 'true']] as $index => $value) {
                yield $consentState.' value '.$index => [$consentState, $value, true, $value === true];
            }
        }
    }

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
                'customData' => ['email' => 'person@example.com'],
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
        self::assertSame([InternalTrafficSettings::JSON_KEY => false], $persisted->getCustomData());
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
                'customData' => ['plan' => "pro\0", 'nested' => ['ignored']],
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

    #[DataProvider('strictSubmissions')]
    public function testStrictProfileStoresOnlyPathEventNameAndGoalFromAnyDirectRequest(array $payload, string $userAgent): void
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
        $geoResolver->expects(self::never())->method('resolve');

        $response = $this->invoke(
            payload: $payload,
            bus: $bus,
            recorder: new AnonymousEventRecorder($entityManager),
            config: $this->privacyConfig(
                customDataProperties: [
                    'utm_medium' => ['consent_required' => false],
                    'page_sequence' => ['type' => 'integer', 'consent_required' => false],
                ],
                collectionProfile: 'strict',
            ),
            geoResolver: $geoResolver,
            userAgent: $userAgent,
        );

        self::assertSame(202, $response->getStatusCode());
        self::assertSame(['status' => 'recorded', 'mode' => 'anonymous'], json_decode((string) $response->getContent(), true));
        self::assertInstanceOf(Event::class, $persisted);
        $persisted->enforcePrivacyInvariants();
        self::assertSame('anonymous', $persisted->getPrivacyMode());
        self::assertSame('/pricing', $persisted->getUrl());
        self::assertSame('plan_selected', $persisted->getEventName());
        self::assertSame('purchase', $persisted->getGoalEvent());
        self::assertSame('unknown', $persisted->getReferrer());
        self::assertSame('unknown', $persisted->getDeviceClass());
        self::assertSame('unknown', $persisted->getViewportBucket());
        self::assertNull($persisted->getGeoArea());
        self::assertNull($persisted->getCustomData());
        self::assertNull($persisted->getConsentState());
        self::assertNull($persisted->getVisitorId());
        self::assertNull($persisted->getSessionId());
        self::assertNull($persisted->getScreenWidth());
        self::assertNull($persisted->getGeneralizedUserAgent());
        self::assertStringNotContainsString('private', serialize($persisted));
    }

    public static function strictSubmissions(): iterable
    {
        $full = [
            'websiteToken' => 'public-site-token',
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
            'customData' => ['utm_medium' => 'private-medium', 'page_sequence' => 3, InternalTrafficSettings::JSON_KEY => true],
        ];

        yield 'standard tracker payload with enhanced consent' => [$full, 'Mozilla/5.0 (iPhone) Mobile Safari'];
        yield 'legacy referrer URL and User-Agent fallback only' => [
            ['websiteToken' => 'public-site-token', 'pagePath' => '/pricing', 'eventName' => 'plan_selected', 'goalEvent' => 'purchase', 'referrer' => 'https://private.example/search?q=private', 'screenWidth' => '390'],
            'Googlebot/2.1 private crawler',
        ];
        yield 'strict tracker payload' => [
            ['websiteToken' => 'public-site-token', 'pagePath' => '/pricing', 'eventName' => 'plan_selected', 'goalEvent' => 'purchase'],
            '',
        ];
    }

    public function testStrictProfileStillAppliesTheKillSwitchAndPathExclusions(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        $payload = ['websiteToken' => 'public-site-token', 'pagePath' => '/account/settings', 'eventName' => 'view'];

        foreach ([
            $this->privacyConfig(enabled: false, collectionProfile: 'strict'),
            $this->privacyConfig(excludedPaths: ['/account/**'], collectionProfile: 'strict'),
        ] as $config) {
            $response = $this->invoke(payload: $payload, bus: $bus, recorder: new AnonymousEventRecorder($entityManager), config: $config);

            self::assertSame(['status' => 'ignored'], json_decode((string) $response->getContent(), true));
        }
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
                'customData' => ['email' => 'private@example.com'],
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
        ?string $userAgent = null,
    ): \Symfony\Component\HttpFoundation\Response {
        $config ??= $this->privacyConfig();
        $logger ??= new NullLogger();
        $rateLimiter ??= $this->rateLimiter();
        $geoResolver ??= $this->geoResolver();
        $goalEvents ??= $this->goalEvents();

        return (new ReceiveController())(
            $this->request($payload, $userAgent),
            $this->websiteManager(),
            $bus,
            $rateLimiter,
            new PrivacySanitizer(),
            $goalEvents,
            new PrivacyPolicy($config),
            $geoResolver,
            $recorder,
            $logger,
            new CustomDataSettings($config),
        );
    }

    private function privacyConfig(
        bool $enabled = true,
        array $excludedPaths = [],
        array $customDataProperties = [],
        string $collectionProfile = 'standard',
    ): AggregateConfigLoader
    {
        $config = $this->createStub(AggregateConfigLoader::class);
        $config->method('all')->willReturn([
            'custom_data_properties' => $customDataProperties,
            'query_parameter_mappings' => [],
        ]);
        $config->method('getBoolWithEnvFallback')->willReturn($enabled);
        $config->method('getWithEnvFallback')
            ->willReturnCallback(static function (string $key, mixed $default = null) use ($excludedPaths, $customDataProperties, $collectionProfile): mixed {
                if ($key === 'collection_profile') {
                    return $collectionProfile;
                }
                if ($key === 'custom_data_properties') {
                    return $customDataProperties;
                }
                return $key === 'anonymous_excluded_paths' ? $excludedPaths : $default;
            });

        return $config;
    }

    private function request(array $payload, ?string $userAgent = null): Request
    {
        return Request::create(
            '/api/receive',
            'POST',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ORIGIN' => 'https://www.example.com',
                'HTTP_USER_AGENT' => $userAgent ?? 'Mozilla/5.0 Chrome/126.0 Safari/537.36',
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
