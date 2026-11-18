<?php

declare(strict_types=1);

namespace App\Tests\Features\ListAlerts;

use App\Entities\AlertRule\AlertRules;
use App\Entities\AlertRule\RuleCoverage;
use App\Entities\AlertRule\RuleReferences;
use App\Entities\Incident\DeliveryHistory;
use App\Entities\Incident\IncidentStates;
use App\Features\ListAlerts\ListAlerts;
use PHPUnit\Framework\TestCase;

final class ListAlertsTest extends TestCase
{
    public function testARuleFiresSinceItsEarliestOpenIncident(): void
    {
        $statuses = $this->list([
            'quiet:a' => 'firing:2026-11-17T10:00:00Z',
            'quiet:b' => 'firing:2026-11-17T08:00:00Z',
            'calm:a' => 'ok',
        ])->statuses();

        self::assertSame(1, $statuses['firing_count']);
        self::assertSame('Firing since 2026-11-17 08:00 UTC', $statuses['rules']['quiet']['label']);
        self::assertSame('OK', $statuses['rules']['calm']['label']);
        self::assertSame('Off', $statuses['rules']['off']['label']);
        self::assertSame('Waiting for the first full hour', $statuses['rules']['new']['label']);
    }

    public function testRecentDeliveriesReadTheirSubjectBack(): void
    {
        $recent = $this->list([])->recentAlerts(1);

        self::assertTrue($recent['has_more']);
        self::assertSame('quiet', $recent['entries'][0]['rule']);
        self::assertSame('site.example.com', $recent['entries'][0]['website']);
        self::assertSame('Hub', $recent['entries'][0]['destination']);
        self::assertSame('Recovered', $recent['entries'][0]['type_label']);
        self::assertSame('Suppressed', $recent['entries'][0]['outcome_label']);
    }

    /** @param array<string, ?string> $states */
    private function list(array $states): ListAlerts
    {
        $rule = static fn (string $id, bool $enabled = true): array => [
            'id' => $id, 'enabled' => $enabled, 'covers' => 'all', 'source' => 'events',
            'privacy_mode' => 'all', 'event_name' => '', 'condition' => 'no_events', 'threshold' => 0,
            'window' => 'hour', 'destinations' => ['hub'],
        ];
        $rules = $this->createStub(AlertRules::class);
        $rules->method('all')->willReturn([
            'quiet' => $rule('quiet'), 'calm' => $rule('calm'), 'off' => $rule('off', false), 'new' => $rule('new'),
        ]);
        $coverage = $this->createStub(RuleCoverage::class);
        $coverage->method('of')->willReturn([
            ['token' => 'a', 'name' => 'A', 'domain' => 'a.example.com'],
            ['token' => 'b', 'name' => 'B', 'domain' => 'b.example.com'],
        ]);
        $incidents = $this->createStub(IncidentStates::class);
        $incidents->method('snapshot')->willReturn(['last_check' => null, 'last_window_end' => null, 'states' => $states]);
        $history = $this->createStub(DeliveryHistory::class);
        $history->method('recent')->willReturn(['total' => 2, 'rows' => [[
            'id' => 7, 'occurred_at' => new \DateTimeImmutable('2026-11-17 09:00', new \DateTimeZone('UTC')),
            'operation' => 'alert_recovered', 'subject' => 'quiet · site.example.com · Hub',
            'outcome' => 'suppressed', 'details' => 'Not sent.',
        ]]]);

        return new ListAlerts($rules, $this->createStub(RuleReferences::class), $coverage, $incidents, $history);
    }
}
