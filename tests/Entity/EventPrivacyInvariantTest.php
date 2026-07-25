<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EventPrivacyInvariantTest extends TestCase
{
    public function testAnonymousPrePersistAndPreUpdateScrubEnhancedFieldsAndBucketTime(): void
    {
        $event = $this->anonymousEvent()
            ->setGeneralizedUserAgent('Chrome / desktop')
            ->setScreenWidth(1440)
            ->setVisitorId('visitor-1')
            ->setSessionId('session-1')
            ->setConsentState('granted')
            ->setCustomData(['email' => 'person@example.com'])
            ->setGoalEvent('purchase')
            ->setGeoArea('country:US')
            ->setCreatedAt(new \DateTimeImmutable('2026-07-24 12:34:56.987654-05:00'));

        $event->enforcePrivacyInvariants();
        $this->assertAnonymousFieldsWereScrubbed($event, '2026-07-24T17:00:00.000000+00:00');

        // The same callback is registered for PreUpdate, so later accidental
        // enrichment cannot persist onto an existing anonymous row.
        $event
            ->setVisitorId('visitor-2')
            ->setSessionId('session-2')
            ->setCustomData(['plan' => 'private'])
            ->setCreatedAt(new \DateTimeImmutable('2026-07-25 03:59:59+02:00'));

        $event->enforcePrivacyInvariants();
        $this->assertAnonymousFieldsWereScrubbed($event, '2026-07-25T01:00:00.000000+00:00');
    }

    #[DataProvider('invalidAnonymousDimensions')]
    public function testAnonymousLifecycleRejectsUnsafeDimensions(string $field, string $value, string $message): void
    {
        $event = $this->anonymousEvent();
        match ($field) {
            'url' => $event->setUrl($value),
            'referrer' => $event->setReferrer($value),
            'eventName' => $event->setEventName($value),
        };

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage($message);
        $event->enforcePrivacyInvariants();
    }

    public static function invalidAnonymousDimensions(): iterable
    {
        yield 'query-bearing path' => ['url', '/account?token=secret', 'sanitized paths'];
        yield 'absolute URL' => ['url', 'https://example.com/account', 'sanitized paths'];
        yield 'raw referrer' => ['referrer', 'https://search.example/private', 'coarse channels'];
        yield 'identifier-like event name' => ['eventName', 'person@example.com', 'safe event-name format'];
        yield 'numeric ID event name' => ['eventName', 'order_123456', 'safe event-name format'];
        yield 'UUID event name' => ['eventName', 'event_550e8400-e29b-41d4-a716-446655440000', 'safe event-name format'];
        yield 'opaque event name' => ['eventName', 'event_abcdefghijklmnop1234567890', 'safe event-name format'];
    }

    public function testEnhancedLifecycleRequiresConsentAndPreservesExactOccurrenceTime(): void
    {
        $occurredAt = new \DateTimeImmutable('2026-07-24 12:34:56.987654-05:00');
        $event = (new Event())
            ->setPrivacyMode('enhanced')
            ->setWebsiteToken('site-token')
            ->setEventName('purchase')
            ->setUrl('/checkout')
            ->setReferrer('search')
            ->setDeviceClass('desktop')
            ->setViewportBucket('large')
            ->setGeneralizedUserAgent('Chrome / desktop')
            ->setVisitorId('visitor-1')
            ->setSessionId('session-1')
            ->setConsentState('granted')
            ->setGeoArea('continent:NA')
            ->setCreatedAt($occurredAt);

        $event->enforcePrivacyInvariants();

        self::assertSame($occurredAt, $event->getCreatedAt());
        self::assertSame('granted', $event->getConsentState());
        self::assertSame('visitor-1', $event->getVisitorId());
        self::assertSame('session-1', $event->getSessionId());
        self::assertSame('continent:NA', $event->getGeoArea());
    }

    #[DataProvider('invalidGeoAreas')]
    public function testOnlyCanonicalCoarseGeographicAreasAreAccepted(string $geoArea): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('canonical country:XX or continent:XX');

        $this->anonymousEvent()->setGeoArea($geoArea);
    }

    public static function invalidGeoAreas(): iterable
    {
        yield 'lowercase country' => ['country:us'];
        yield 'three-letter country' => ['country:USA'];
        yield 'unassigned country' => ['country:ZZ'];
        yield 'unknown continent code' => ['continent:XX'];
        yield 'subdivision' => ['subdivision:US-IL'];
        yield 'city' => ['city:Chicago'];
        yield 'view-only other bucket' => ['country:other'];
    }

    public function testEnhancedLifecycleRejectsMissingGrantedConsent(): void
    {
        $event = (new Event())->setPrivacyMode('enhanced');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Enhanced events require granted consent.');
        $event->enforcePrivacyInvariants();
    }

    private function anonymousEvent(): Event
    {
        return (new Event())
            ->setPrivacyMode('anonymous')
            ->setWebsiteToken('site-token')
            ->setEventName('nav:click')
            ->setUrl('/pricing')
            ->setReferrer('search')
            ->setDeviceClass('desktop')
            ->setViewportBucket('large');
    }

    private function assertAnonymousFieldsWereScrubbed(Event $event, string $expectedTime): void
    {
        self::assertSame($expectedTime, $event->getCreatedAt()->format('Y-m-d\TH:i:s.uP'));
        self::assertNull($event->getGeneralizedUserAgent());
        self::assertNull($event->getScreenWidth());
        self::assertNull($event->getVisitorId());
        self::assertNull($event->getSessionId());
        self::assertNull($event->getConsentState());
        self::assertNull($event->getCustomData());
        self::assertNull($event->getGoalEvent());
        self::assertSame('country:US', $event->getGeoArea());
    }
}
