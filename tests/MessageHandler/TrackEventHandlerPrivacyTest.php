<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Entity\Event;
use App\Message\TrackEventMessage;
use App\MessageHandler\TrackEventHandler;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

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
        $handler = new TrackEventHandler($entityManager);
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
        self::assertSame(['plan' => 'pro'], $persisted->getCustomData());
        self::assertFalse($persisted->isInternalTraffic());
        self::assertSame('purchase', $persisted->getGoalEvent());
        self::assertSame($occurredAt, $persisted->getCreatedAt());

        // Doctrine invokes this on persist. Enhanced events keep exact time.
        $persisted->enforcePrivacyInvariants();
        self::assertSame($occurredAt, $persisted->getCreatedAt());
    }

    #[DataProvider('internalTrafficMessages')]
    public function testHandlerOverridesReservedCustomDataWithExplicitTrafficMetadata(
        bool $internalTraffic,
        bool $legacyMessage,
        string $markerName = 'orgInternalTraffic',
    ): void {
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
            eventData: ['plan' => 'pro', $markerName => !$internalTraffic],
            generalizedUserAgent: 'Chrome / desktop',
            visitorId: 'visitor-1',
            sessionId: 'session-1',
            occurredAt: new \DateTimeImmutable('2026-07-24T17:00:00+00:00'),
            internalTraffic: $internalTraffic,
            internalTrafficName: $markerName,
        );

        if ($legacyMessage) {
            // Previously queued PHP-serialized messages do not have this property.
            unset($message->internalTraffic);
            unset($message->internalTrafficName);
        }
        $message = unserialize(serialize($message));

        (new TrackEventHandler($entityManager))($message);

        self::assertInstanceOf(Event::class, $persisted);
        self::assertSame($internalTraffic, $persisted->isInternalTraffic());
        self::assertSame(
            $internalTraffic ? ['plan' => 'pro', $markerName => true] : ['plan' => 'pro'],
            $persisted->getCustomData(),
        );
    }

    public static function internalTrafficMessages(): iterable
    {
        yield 'marked browser overrides false custom property' => [true, false];
        yield 'unmarked browser removes true custom property' => [false, false];
        yield 'old queued message removes true custom property' => [false, true];
        yield 'queued event preserves configured marker name' => [true, false, 'companyStaff'];
        yield 'queued unmarked event clears configured marker property' => [false, false, 'companyStaff'];
    }

    public function testEventEntityRejectsNonGrantedConsentStateAtAssignment(): void
    {
        $event = new Event();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Consent state must be granted or null.');
        $event->setConsentState('denied');
    }
}
