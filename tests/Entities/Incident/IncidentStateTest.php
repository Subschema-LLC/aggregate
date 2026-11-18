<?php

declare(strict_types=1);

namespace App\Tests\Entities\Incident;

use App\Entities\Incident\AlertFired;
use App\Entities\Incident\AlertRecovered;
use App\Entities\Incident\IncidentState;
use App\Entities\Incident\UtcHour;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IncidentStateTest extends TestCase
{
    /** @param array{kind: string, since: ?string, recovered_at: ?string} $parsed */
    #[DataProvider('states')]
    public function testParsesStoredStates(?string $state, array $parsed): void
    {
        self::assertSame($parsed, IncidentState::parse($state));
    }

    /** @return iterable<string, array{?string, array{kind: string, since: ?string, recovered_at: ?string}}> */
    public static function states(): iterable
    {
        $h1 = '2026-11-17T09:00:00Z';
        $h2 = '2026-11-17T12:00:00Z';
        yield 'nothing stored' => [null, ['kind' => 'none', 'since' => null, 'recovered_at' => null]];
        yield 'ok' => ['ok', ['kind' => 'ok', 'since' => null, 'recovered_at' => null]];
        yield 'firing' => ['firing:'.$h1, ['kind' => 'firing', 'since' => $h1, 'recovered_at' => null]];
        yield 'recovered' => [
            'recovered:'.$h2.':'.$h1,
            ['kind' => 'recovered', 'since' => $h1, 'recovered_at' => $h2],
        ];
        yield 'bad firing hour' => ['firing:yesterday', ['kind' => 'ok', 'since' => null, 'recovered_at' => null]];
        yield 'bad recovery' => ['recovered:'.$h2, ['kind' => 'ok', 'since' => null, 'recovered_at' => null]];
        yield 'unknown' => ['paused', ['kind' => 'ok', 'since' => null, 'recovered_at' => null]];
    }

    public function testTheCurrentAlertIsTheOneEveryDestinationShouldHave(): void
    {
        $h1 = '2026-11-17T09:00:00Z';
        $h2 = '2026-11-17T12:00:00Z';

        $fired = IncidentState::currentAlert(IncidentState::firing($h1), 'quiet', 'tok');
        self::assertEquals(new AlertFired('quiet', 'tok', $h1), $fired);
        self::assertSame(['type' => 'firing', 'window_end' => $h1, 'since' => $h1], $fired?->toArray());

        $recovered = IncidentState::currentAlert(IncidentState::recovered($h2, $h1), 'quiet', 'tok');
        self::assertEquals(new AlertRecovered('quiet', 'tok', $h2, $h1), $recovered);
        self::assertNull(IncidentState::currentAlert('ok', 'quiet', 'tok'));
    }

    public function testLongWebsiteTokensAreHashedToFitTheSubject(): void
    {
        self::assertSame('quiet:tok', IncidentState::subject('quiet', 'tok'));
        $long = IncidentState::subject('quiet', str_repeat('t', 200));
        self::assertSame('quiet:#'.hash('sha256', str_repeat('t', 200)), $long);
    }

    public function testUtcHoursRoundTripAndRejectPartialHours(): void
    {
        $hour = new \DateTimeImmutable('2026-11-17 03:00:00', new \DateTimeZone('America/Chicago'));
        self::assertSame('2026-11-17T09:00:00Z', UtcHour::format($hour));
        self::assertEquals($hour, UtcHour::parse('2026-11-17T09:00:00Z'));
        self::assertNull(UtcHour::parse('2026-11-17T09:30:00Z'));
        self::assertNull(UtcHour::parse(null));
    }
}
