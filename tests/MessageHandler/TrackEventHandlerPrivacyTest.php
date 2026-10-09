<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Entity\Event;
use App\Message\TrackEventMessage;
use App\MessageHandler\TrackEventHandler;
use App\Service\InternalTrafficSettings;
use App\Service\TrackingFailureRetryRunner;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class TrackEventHandlerPrivacyTest extends TestCase
{
    public function testHandlerCreatesEnhancedEventWithMessageOccurrenceTimeUnchanged(): void
    {
        $persisted = null;
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$persisted): void {
                $persisted = $entity;
            });
        $entityManager->expects(self::once())->method('flush');

        $occurredAt = new \DateTimeImmutable('2026-07-24 12:34:56.987654-05:00');
        $handler = new TrackEventHandler($entityManager, $this->createStub(TrackingFailureRetryRunner::class), new NullLogger());
        $handler(new TrackEventMessage(
            websiteToken: 'site-token',
            eventName: 'purchase.completed',
            pagePath: '/pricing',
            referrerChannel: 'search',
            deviceClass: 'desktop',
            viewportBucket: 'large',
            screenWidth: 1440,
            goalEvent: 'purchase',
            eventData: ['plan' => 'pro'],
            generalizedUserAgent: 'Chrome / desktop',
            visitorId: 'visitor_abc',
            sessionId: 'session_abc',
            occurredAt: $occurredAt,
            geoArea: 'continent:NA',
            internalTraffic: false,
        ));

        self::assertInstanceOf(Event::class, $persisted);
        self::assertSame('enhanced', $persisted->getPrivacyMode());
        self::assertSame('granted', $persisted->getConsentState());
        self::assertSame('purchase.completed', $persisted->getEventName());
        self::assertSame('/pricing', $persisted->getUrl());
        self::assertSame('search', $persisted->getReferrer());
        self::assertSame('desktop', $persisted->getDeviceClass());
        self::assertSame('large', $persisted->getViewportBucket());
        self::assertSame('continent:NA', $persisted->getGeoArea());
        self::assertSame('Chrome / desktop', $persisted->getGeneralizedUserAgent());
        self::assertSame('visitor_abc', $persisted->getVisitorId());
        self::assertSame('session_abc', $persisted->getSessionId());
        self::assertSame(['plan' => 'pro', InternalTrafficSettings::JSON_KEY => false], $persisted->getCustomData());
        self::assertFalse($persisted->isInternalTraffic());
        self::assertSame('purchase', $persisted->getGoalEvent());
        self::assertSame($occurredAt, $persisted->getCreatedAt());

        // Doctrine invokes this on persist. Enhanced events keep exact time.
        $persisted->enforcePrivacyInvariants();
        self::assertSame($occurredAt, $persisted->getCreatedAt());
    }

    #[DataProvider('internalTrafficMessages')]
    public function testHandlerOverridesReservedCustomDataWithExplicitTrafficMetadata(?bool $internalTraffic): void
    {
        $persisted = null;
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('persist')
            ->willReturnCallback(static function (object $event) use (&$persisted): void {
                $persisted = $event;
            });
        $entityManager->expects(self::once())->method('flush');
        $message = new TrackEventMessage(
            websiteToken: 'site-token',
            eventName: 'view',
            pagePath: '/pricing',
            referrerChannel: 'direct',
            deviceClass: 'desktop',
            viewportBucket: 'large',
            screenWidth: null,
            goalEvent: null,
            eventData: ['plan' => 'pro', InternalTrafficSettings::JSON_KEY => !$internalTraffic],
            generalizedUserAgent: 'Chrome / desktop',
            visitorId: 'visitor-1',
            sessionId: 'session-1',
            occurredAt: new \DateTimeImmutable('2026-07-24T17:00:00+00:00'),
            internalTraffic: $internalTraffic,
        );
        $message = unserialize(serialize($message));

        (new TrackEventHandler($entityManager, $this->createStub(TrackingFailureRetryRunner::class), new NullLogger()))($message);

        self::assertInstanceOf(Event::class, $persisted);
        self::assertSame($internalTraffic === true, $persisted->isInternalTraffic());
        self::assertSame(
            $internalTraffic === null ? ['plan' => 'pro'] : ['plan' => 'pro', InternalTrafficSettings::JSON_KEY => $internalTraffic],
            $persisted->getCustomData(),
        );
    }

    public static function internalTrafficMessages(): iterable
    {
        yield 'marked browser overrides a false property' => [true];
        yield 'unmarked browser overrides a true property' => [false];
        yield 'an event without a flag keeps no forged property' => [null];
    }

    public function testEventEntityRejectsNonGrantedConsentStateAtAssignment(): void
    {
        $event = new Event();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Consent state must be granted or null.');
        $event->setConsentState('denied');
    }

    public function testPersistenceFailuresAreRecordedForRetry(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist');
        $entityManager->expects(self::once())->method('flush')->willThrowException(new \RuntimeException('db down'));
        $runner = $this->createMock(TrackingFailureRetryRunner::class);
        $runner->expects(self::once())->method('recordFailure');
        $handler = new TrackEventHandler($entityManager, $runner, new NullLogger());
        $message = new TrackEventMessage(
            websiteToken: 'site-token',
            eventName: 'view',
            pagePath: '/pricing',
            referrerChannel: 'direct',
            deviceClass: 'desktop',
            viewportBucket: 'large',
            screenWidth: null,
            goalEvent: null,
            eventData: null,
            generalizedUserAgent: 'ua',
            visitorId: 'visitor',
            sessionId: 'session',
            occurredAt: new \DateTimeImmutable('2026-10-08T00:00:00+00:00'),
        );

        $this->expectException(\RuntimeException::class);
        $handler($message);
    }
}
