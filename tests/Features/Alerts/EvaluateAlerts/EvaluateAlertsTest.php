<?php

declare(strict_types=1);

namespace App\Tests\Features\Alerts\EvaluateAlerts;

use App\Entities\AlertRule\WindowBreaches;
use App\Entities\Incident\AlertFired;
use App\Entities\Incident\AlertRecovered;
use App\Features\Alerts\EvaluateAlerts\EvaluateAlerts;
use App\Features\Alerts\EvaluateAlerts\Evaluation;
use PHPUnit\Framework\TestCase;

final class EvaluateAlertsTest extends TestCase
{
    /** @var array<string, bool> breached per window-end hour */
    private array $breached = [];

    public function testCatchesUpAtMostADayOfHours(): void
    {
        $evaluate = $this->evaluate();
        $now = self::hour('2026-11-17T12:00:00Z');

        self::assertEquals([$now], $evaluate->hours(null, $now));
        self::assertEquals([$now], $evaluate->hours($now, $now));
        self::assertCount(2, $evaluate->hours(self::hour('2026-11-17T10:00:00Z'), $now));
        $hours = $evaluate->hours(self::hour('2026-11-10T00:00:00Z'), $now);
        self::assertCount(24, $hours);
        self::assertEquals(self::hour('2026-11-16T13:00:00Z'), $hours[0]);
    }

    public function testABreachFiresAndIsSaved(): void
    {
        $this->breached = ['2026-11-17T11:00:00Z' => true, '2026-11-17T12:00:00Z' => true];
        $evaluation = $this->evaluate()->evaluateWebsite(self::rule(), 'tok', $this->hours(), null);

        $since = '2026-11-17T11:00:00Z';
        self::assertEquals([
            ['kind' => 'deliver', 'alert' => new AlertFired('quiet', 'tok', $since), 'retryable' => true],
            ['kind' => 'save', 'subject' => 'quiet:tok', 'state' => 'firing:'.$since],
        ], $evaluation->steps());
    }

    public function testAStillFiringIncidentIsOfferedAgainButNotSaved(): void
    {
        $this->breached = ['2026-11-17T11:00:00Z' => true, '2026-11-17T12:00:00Z' => true];
        $state = 'firing:2026-11-17T10:00:00Z';
        $evaluation = $this->evaluate()->evaluateWebsite(self::rule(), 'tok', $this->hours(), $state);

        self::assertCount(1, $evaluation->steps());
        self::assertSame('deliver', $evaluation->steps()[0]['kind']);
    }

    public function testRecoveringAndBreakingAgainClosesTheEarlierIncidentFirst(): void
    {
        $this->breached = ['2026-11-17T11:00:00Z' => false, '2026-11-17T12:00:00Z' => true];
        $before = 'firing:2026-11-17T08:00:00Z';
        $steps = $this->evaluate()->evaluateWebsite(self::rule(), 'tok', $this->hours(), $before)->steps();

        $closing = new AlertRecovered('quiet', 'tok', '2026-11-17T11:00:00Z', '2026-11-17T08:00:00Z');
        self::assertEquals(['kind' => 'deliver', 'alert' => $closing, 'retryable' => false], $steps[0]);
        self::assertEquals(new AlertFired('quiet', 'tok', '2026-11-17T12:00:00Z'), $steps[1]['alert'] ?? null);
        self::assertSame('firing:2026-11-17T12:00:00Z', $steps[2]['state'] ?? null);
    }

    public function testIncidentsOfRulesTurnedOffOrDeletedAreClosed(): void
    {
        $since = '2026-11-17T08:00:00Z';
        $rules = ['quiet' => self::rule(['enabled' => false])];
        $states = ['quiet:tok' => 'firing:'.$since, 'gone:tok' => 'firing:'.$since, 'quiet:#ab' => 'firing:'.$since];
        $now = self::hour('2026-11-17T12:00:00Z');

        $steps = $this->evaluate()->closeUnchecked($states, [], $rules, ['quiet' => ['tok']], $now)->steps();

        $recovery = new AlertRecovered('quiet', 'tok', '2026-11-17T12:00:00Z', $since);
        self::assertEquals([
            ['kind' => 'deliver', 'alert' => $recovery, 'retryable' => true],
            ['kind' => 'save', 'subject' => 'quiet:tok', 'state' => 'recovered:2026-11-17T12:00:00Z:'.$since],
            ['kind' => 'deleted', 'subject' => 'gone:tok', 'rule_id' => 'gone', 'website_token' => 'tok'],
        ], $steps);
    }

    public function testAnIncidentTurnedOffInTheHourItFiredClosesAtTheNextCheck(): void
    {
        $since = '2026-11-17T12:00:00Z';
        $rules = ['quiet' => self::rule(['enabled' => false])];
        $evaluation = $this->evaluate()->closeUnchecked(
            ['quiet:tok' => 'firing:'.$since],
            [],
            $rules,
            [],
            self::hour($since),
        );

        self::assertSame([], $evaluation->steps());
    }

    private function evaluate(): EvaluateAlerts
    {
        $breaches = new class($this->breached) implements WindowBreaches {
            /** @param array<string, bool> $breached */
            public function __construct(private array &$breached)
            {
            }

            public function isBreached(
                array $rule,
                string $websiteToken,
                \DateTimeImmutable $hourEnd,
            ): bool {
                return $this->breached[$hourEnd->format('Y-m-d\TH:00:00\Z')] ?? false;
            }
        };

        return new EvaluateAlerts($breaches);
    }

    /** @return list<\DateTimeImmutable> */
    private function hours(): array
    {
        return [self::hour('2026-11-17T11:00:00Z'), self::hour('2026-11-17T12:00:00Z')];
    }

    private static function hour(string $iso): \DateTimeImmutable
    {
        return new \DateTimeImmutable($iso, new \DateTimeZone('UTC'));
    }

    /**
     * @param array<string, mixed> $change
     *
     * @return array{id: string, enabled: bool, covers: string, source: 'events', privacy_mode: 'all',
     *     event_name: string, condition: 'no_events', threshold: int, window: 'hour',
     *     destinations: list<string>}
     */
    private static function rule(array $change = []): array
    {
        /** @var array{id: string, enabled: bool, covers: string, source: 'events', privacy_mode: 'all', event_name: string, condition: 'no_events', threshold: int, window: 'hour', destinations: list<string>} */
        return [...[
            'id' => 'quiet',
            'enabled' => true,
            'covers' => 'all',
            'source' => 'events',
            'privacy_mode' => 'all',
            'event_name' => '',
            'condition' => 'no_events',
            'threshold' => 0,
            'window' => 'hour',
            'destinations' => ['ops'],
        ], ...$change];
    }
}
