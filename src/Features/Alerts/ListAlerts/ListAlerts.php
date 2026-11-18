<?php

declare(strict_types=1);

namespace App\Features\Alerts\ListAlerts;

use App\Entities\AlertRule\AlertRules;
use App\Entities\AlertRule\RuleCoverage;
use App\Entities\AlertRule\RuleReferences;
use App\Entities\Incident\DeliveryHistory;
use App\Entities\Incident\IncidentStates;

/**
 * What the Alerts page and app:alerts:status show: each rule's status, when
 * the alerts were last checked, and the most recent deliveries. A setting or
 * record that cannot be read shows as empty rather than failing the page.
 *
 * @phpstan-import-type Statuses from RuleStatuses
 * @phpstan-import-type Recent from RecentDeliveries
 */
final class ListAlerts
{
    public const STATE_OK = 'ok';
    public const STATE_FIRING = 'firing';
    public const STATE_WAITING = 'waiting';
    public const STATE_OFF = 'off';
    public const STATE_PROBLEM = 'problem';
    public const RECENT_LIMIT = 20;

    public function __construct(
        private readonly AlertRules $rules,
        private readonly RuleReferences $references,
        private readonly RuleCoverage $coverage,
        private readonly IncidentStates $states,
        private readonly DeliveryHistory $history,
    ) {
    }

    /** @return Statuses */
    public function statuses(): array
    {
        $snapshot = $this->states->snapshot();
        try {
            $rules = $this->rules->all();
        } catch (\Throwable) {
            $rules = [];
        }
        $states = $snapshot['states'];
        $statuses = RuleStatuses::of($rules, $states, $this->references, $this->coverage);

        return [
            'last_check' => $snapshot['last_check'],
            'last_window_end' => $snapshot['last_window_end'],
            'firing_count' => $statuses['firing_count'],
            'rules' => $statuses['rules'],
        ];
    }

    /** @return Recent */
    public function recentAlerts(int $limit = self::RECENT_LIMIT): array
    {
        return RecentDeliveries::of($this->history, $limit);
    }
}
