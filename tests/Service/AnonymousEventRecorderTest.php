<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Event;
use App\Service\AnonymousEventRecorder;
use App\Service\InternalTrafficSettings;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AnonymousEventRecorderTest extends TestCase
{
    #[DataProvider('organizationTrafficFlags')]
    public function testRecorderCombinesApprovedPropertiesWithAuthoritativeTrafficFlag(bool $internalTraffic): void
    {
        $persisted = null;
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('persist')
            ->willReturnCallback(static function (object $event) use (&$persisted): void {
                $persisted = $event;
            });
        $entityManager->expects(self::once())->method('flush');

        (new AnonymousEventRecorder($entityManager))->record(
            websiteToken: 'site-token',
            eventName: 'view',
            pagePath: '/pricing',
            referrerChannel: 'direct',
            deviceClass: 'desktop',
            viewportBucket: 'large',
            internalTraffic: $internalTraffic,
            approvedCustomData: ['plan' => 'pro', InternalTrafficSettings::JSON_KEY => !$internalTraffic],
        );

        self::assertInstanceOf(Event::class, $persisted);
        // A later assignment can neither forge nor drop the flag.
        $persisted->setCustomData(['plan' => 'pro', InternalTrafficSettings::JSON_KEY => !$internalTraffic]);
        $persisted->enforcePrivacyInvariants();
        self::assertSame(['plan' => 'pro', InternalTrafficSettings::JSON_KEY => $internalTraffic], $persisted->getCustomData());
        self::assertSame($internalTraffic, $persisted->isInternalTraffic());
    }

    public static function organizationTrafficFlags(): iterable
    {
        yield 'marked' => [true];
        yield 'unmarked' => [false];
    }

    public function testRecorderPersistsOneIdentifierFreeUtcHourEventSynchronously(): void
    {
        $persisted = null;
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('persist')
            ->willReturnCallback(static function (object $event) use (&$persisted): void {
                $persisted = $event;
            });
        $entityManager->expects(self::once())->method('flush');

        $occurredAt = new \DateTimeImmutable('2026-07-24 12:34:56.789012-05:00');
        (new AnonymousEventRecorder($entityManager))->record(
            websiteToken: 'site-token',
            eventName: 'nav:click',
            pagePath: '/pricing',
            referrerChannel: 'search',
            deviceClass: 'desktop',
            viewportBucket: 'large',
            occurredAt: $occurredAt,
            geoArea: 'country:US',
            goalEvent: 'purchase',
        );

        self::assertInstanceOf(Event::class, $persisted);
        self::assertSame('anonymous', $persisted->getPrivacyMode());
        self::assertSame('site-token', $persisted->getWebsiteToken());
        self::assertSame('nav:click', $persisted->getEventName());
        self::assertSame('/pricing', $persisted->getUrl());
        self::assertSame('search', $persisted->getReferrer());
        self::assertSame('desktop', $persisted->getDeviceClass());
        self::assertSame('large', $persisted->getViewportBucket());
        self::assertSame('country:US', $persisted->getGeoArea());
        self::assertSame('2026-07-24T17:00:00.000000+00:00', $persisted->getCreatedAt()->format('Y-m-d\TH:i:s.uP'));
        self::assertNull($persisted->getGeneralizedUserAgent());
        self::assertNull($persisted->getScreenWidth());
        self::assertNull($persisted->getVisitorId());
        self::assertNull($persisted->getSessionId());
        self::assertNull($persisted->getConsentState());
        self::assertSame('purchase', $persisted->getGoalEvent());
        self::assertNull($persisted->getCustomData());
        self::assertFalse($persisted->isInternalTraffic());
    }

    public function testRecorderPersistsInternalTrafficAsReservedJsonMetadata(): void
    {
        $persisted = null;
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('persist')
            ->willReturnCallback(static function (object $event) use (&$persisted): void {
                $persisted = $event;
            });
        $entityManager->expects(self::once())->method('flush');

        (new AnonymousEventRecorder($entityManager))->record(
            websiteToken: 'site-token',
            eventName: 'view',
            pagePath: '/pricing',
            referrerChannel: 'direct',
            deviceClass: 'desktop',
            viewportBucket: 'large',
            internalTraffic: true,
        );

        self::assertInstanceOf(Event::class, $persisted);
        $persisted->enforcePrivacyInvariants();
        self::assertTrue($persisted->isInternalTraffic());
        self::assertSame([InternalTrafficSettings::JSON_KEY => true], $persisted->getCustomData());
        self::assertNull($persisted->getVisitorId());
        self::assertNull($persisted->getSessionId());
    }

    public function testRecorderPersistsEveryAnonymousEventAsItsOwnRowEvenWithinOneHour(): void
    {
        $persisted = [];
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::exactly(2))
            ->method('persist')
            ->willReturnCallback(static function (object $event) use (&$persisted): void {
                $persisted[] = $event;
            });
        $entityManager->expects(self::exactly(2))->method('flush');
        $recorder = new AnonymousEventRecorder($entityManager);
        $occurredAt = new \DateTimeImmutable('2026-07-24 17:34:56+00:00');

        for ($i = 0; $i < 2; ++$i) {
            $recorder->record(
                websiteToken: 'site-token',
                eventName: 'cta:click',
                pagePath: '/pricing',
                referrerChannel: 'direct',
                deviceClass: 'desktop',
                viewportBucket: 'large',
                occurredAt: $occurredAt,
            );
        }

        self::assertCount(2, $persisted);
        self::assertContainsOnlyInstancesOf(Event::class, $persisted);
        self::assertNotSame($persisted[0], $persisted[1]);
        self::assertSame('2026-07-24T17:00:00+00:00', $persisted[0]->getCreatedAt()->format(\DateTimeInterface::ATOM));
        self::assertEquals($persisted[0]->getCreatedAt(), $persisted[1]->getCreatedAt());
    }
}
