<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Entity\Event;
use App\Message\TrackEventMessage;
use App\MessageHandler\TrackEventHandler;
use Doctrine\ORM\EntityManagerInterface;
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
            goalEvent: 'checkout',
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
        self::assertSame('checkout', $persisted->getGoalEvent());
        self::assertSame($occurredAt, $persisted->getCreatedAt());

        // Doctrine invokes this on persist. Enhanced events keep exact time.
        $persisted->enforcePrivacyInvariants();
        self::assertSame($occurredAt, $persisted->getCreatedAt());
    }

    public function testEventEntityRejectsNonGrantedConsentStateAtAssignment(): void
    {
        $event = new Event();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Consent state must be granted or null.');
        $event->setConsentState('denied');
    }
}
