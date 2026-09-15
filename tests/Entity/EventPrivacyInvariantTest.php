<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EventPrivacyInvariantTest extends TestCase
{
    public function testAnonymousLifecycleRetainsApprovedValuesAndRejectsLaterEnrichment(): void
    {
        $event = $this->anonymousEvent()
            ->setApprovedAnonymousCustomData(['plan' => "pro\0", 'active' => false, 'optional' => null, 'nested' => ['ignored']])
            ->setInternalTraffic(true, 'companyStaff')
            ->setVisitorId('private-visitor')
            ->setSessionId('private-session');

        $expected = ['plan' => 'pro', 'active' => false, 'optional' => null, 'companyStaff' => true];
        $event->enforcePrivacyInvariants();
        self::assertSame($expected, $event->getCustomData());
        self::assertNull($event->getVisitorId());
        self::assertNull($event->getSessionId());

        $event->setCustomData([...$expected, 'plan' => 'private@example.com', 'email' => 'private@example.com']);
        $event->enforcePrivacyInvariants();
        self::assertSame($expected, $event->getCustomData());
        self::assertStringNotContainsString('private', serialize($event));
    }

    public function testHydrationPreservesHistoricalPropertiesAndRenamedTrafficMarkerOnUpdate(): void
    {
        $persistedData = ['plan' => 'pro', 'optional' => null, 'companyStaff' => true];
        $loaded = $this->anonymousEvent()->setCustomData($persistedData);
        $loaded->restoreAnonymousTrafficMarkerName();
        $loaded->setCustomData([...$persistedData, 'email' => 'private@example.com']);
        $loaded->setArchivedAt(new \DateTimeImmutable('2026-08-01T00:00:00Z'));
        $loaded->enforcePrivacyInvariants();

        self::assertSame($persistedData, $loaded->getCustomData());
        self::assertTrue($loaded->isInternalTraffic('companyStaff'));
        self::assertStringNotContainsString('private', serialize($loaded));

        $loaded->setInternalTraffic(false, 'companyStaff');
        $loaded->enforcePrivacyInvariants();
        self::assertSame(['plan' => 'pro', 'optional' => null], $loaded->getCustomData());
    }

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

    public function testAnonymousLifecyclePreservesOnlyReservedInternalTrafficMarker(): void
    {
        $event = $this->anonymousEvent()
            ->setCustomData(['email' => 'person@example.com', 'orgInternalTraffic' => true])
            ->setVisitorId('visitor-1')
            ->setSessionId('session-1')
            ->setCreatedAt(new \DateTimeImmutable('2026-07-24 12:34:56-05:00'));

        $event->enforcePrivacyInvariants();

        self::assertTrue($event->isInternalTraffic());
        self::assertSame(['orgInternalTraffic' => true], $event->getCustomData());
        self::assertNull($event->getVisitorId());
        self::assertNull($event->getSessionId());
        self::assertSame('2026-07-24T17:00:00+00:00', $event->getCreatedAt()->format(\DateTimeInterface::ATOM));

        $event->setCustomData([...$event->getCustomData(), 'plan' => 'private']);
        $event->enforcePrivacyInvariants();
        self::assertSame(['orgInternalTraffic' => true], $event->getCustomData());
    }

    #[DataProvider('nonBooleanInternalTrafficMarkers')]
    public function testAnonymousLifecycleNeverRetainsArbitraryInternalTrafficValues(mixed $marker): void
    {
        $event = $this->anonymousEvent()->setCustomData(['orgInternalTraffic' => $marker]);

        self::assertFalse($event->isInternalTraffic());
        $event->enforcePrivacyInvariants();
        self::assertNull($event->getCustomData());
    }

    public static function nonBooleanInternalTrafficMarkers(): iterable
    {
        yield 'false' => [false];
        yield 'null' => [null];
        yield 'string true' => ['true'];
        yield 'numeric true' => [1];
        yield 'arbitrary string' => ['person@example.com'];
        yield 'object-like data' => [['email' => 'person@example.com']];
    }

    public function testConfiguredOrganizationMarkerSurvivesAnonymousLifecycleAndHydration(): void
    {
        $event = $this->anonymousEvent()
            ->setCustomData(['email' => 'person@example.com'])
            ->setInternalTraffic(true, 'companyStaff');
        $event->enforcePrivacyInvariants();
        self::assertSame(['companyStaff' => true], $event->getCustomData());

        // Doctrine hydrates JSON and invokes PostLoad on a new entity instance.
        $loaded = $this->anonymousEvent()->setCustomData($event->getCustomData());
        $callback = new \ReflectionMethod(Event::class, 'restoreAnonymousTrafficMarkerName');
        self::assertCount(1, $callback->getAttributes(\Doctrine\ORM\Mapping\PostLoad::class));
        $loaded->restoreAnonymousTrafficMarkerName();
        self::assertTrue($loaded->isInternalTraffic());
        $loaded->setCustomData([...$loaded->getCustomData(), 'email' => 'must-be-stripped']);
        $loaded->enforcePrivacyInvariants();
        self::assertSame(['companyStaff' => true], $loaded->getCustomData());
        $loaded->setInternalTraffic(false, 'companyStaff');
        self::assertNull($loaded->getCustomData());
    }

    public function testNewAnonymousEventsDoNotTreatArbitraryBooleanPropertiesAsConfiguredMarkers(): void
    {
        $event = $this->anonymousEvent()->setCustomData(['arbitraryProperty' => true]);
        $event->enforcePrivacyInvariants();
        self::assertNull($event->getCustomData());
    }

    public function testNumericNamesCannotTurnMarkerJsonIntoAnArray(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Event())->setInternalTraffic(true, '0');
    }

    public function testInternalTrafficDefaultsToFalseAndSetterPreservesOtherCustomProperties(): void
    {
        $event = new Event();

        self::assertFalse($event->isInternalTraffic());
        $event->setInternalTraffic(false);
        self::assertNull($event->getCustomData());

        $event->setCustomData(['plan' => 'pro', 'orgInternalTraffic' => 'untrusted']);
        $event->setInternalTraffic(true);
        self::assertSame(['plan' => 'pro', 'orgInternalTraffic' => true], $event->getCustomData());

        $event->setInternalTraffic(false);
        self::assertSame(['plan' => 'pro'], $event->getCustomData());
        self::assertFalse($event->isInternalTraffic());

        $event->setCustomData(['orgInternalTraffic' => true])->setInternalTraffic(false);
        self::assertNull($event->getCustomData());
    }

    #[DataProvider('invalidAnonymousDimensions')]
    public function testAnonymousLifecycleRejectsUnsafeDimensions(string $field, string $value, string $message): void
    {
        $event = $this->anonymousEvent();
        match ($field) {
            'url' => $event->setUrl($value),
            'referrer' => $event->setReferrer($value),
            'eventName' => $event->setEventName($value),
            'goalEvent' => $event->setGoalEvent($value),
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
        yield 'identifier-like goal name' => ['goalEvent', 'person@example.com', 'safe event-name format'];
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
        self::assertSame('purchase', $event->getGoalEvent());
        self::assertSame('country:US', $event->getGeoArea());
    }
}
