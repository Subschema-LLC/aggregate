<?php

declare(strict_types=1);

namespace App\Features\Alerts\ListAlerts;

use App\Entities\AlertRule\AlertRule;
use App\Entities\AlertRule\RuleCoverage;
use App\Entities\AlertRule\RuleReferences;
use App\Entities\Incident\IncidentState;
use App\Entities\Incident\UtcHour;

/**
 * A rule is off, not checked (with why), firing since its earliest open
 * incident, OK once any website was evaluated, or waiting for its first hour.
 *
 * @phpstan-import-type RuleArray from AlertRule
 *
 * @phpstan-type Status array{
 *     state: 'ok'|'firing'|'waiting'|'off'|'problem',
 *     label: string,
 *     since: ?string,
 *     since_formatted: ?string
 * }
 * @phpstan-type Statuses array{
 *     last_check: ?\DateTimeImmutable,
 *     last_window_end: ?string,
 *     firing_count: int,
 *     rules: array<string, Status>
 * }
 */
final class RuleStatuses
{
    /**
     * @param array<string, RuleArray> $rules
     * @param array<string, ?string>   $states
     *
     * @return array{firing_count: int, rules: array<string, Status>}
     */
    public static function of(
        array $rules,
        array $states,
        RuleReferences $references,
        RuleCoverage $coverage,
    ): array {
        $statuses = [];
        $firing = 0;
        foreach ($rules as $id => $rule) {
            if (!$rule['enabled']) {
                $statuses[$id] = self::status(ListAlerts::STATE_OFF, 'Off');
                continue;
            }
            $problem = self::problem($rule, $references);
            if ($problem !== null) {
                $statuses[$id] = self::status(ListAlerts::STATE_PROBLEM, 'Not checked: '.$problem);
                continue;
            }
            $sites = $coverage->of($rule);
            if ($sites === []) {
                $label = 'Not checked: it covers no websites.';
                $statuses[$id] = self::status(ListAlerts::STATE_PROBLEM, $label);
                continue;
            }

            $earliest = null;
            $evaluated = false;
            foreach ($sites as $site) {
                $state = $states[IncidentState::subject((string) $id, $site['token'])] ?? null;
                if ($state === null) {
                    continue;
                }
                $evaluated = true;
                $parsed = IncidentState::parse($state);
                $since = $parsed['since'];
                if ($parsed['kind'] === 'firing' && $since !== null
                    && ($earliest === null || strcmp($since, $earliest) < 0)) {
                    $earliest = $since;
                }
            }

            if ($earliest !== null) {
                ++$firing;
                $date = UtcHour::parse($earliest);
                $formatted = $date !== null ? $date->format('Y-m-d H:00').' UTC' : $earliest;
                $label = 'Firing since '.$formatted;
                $firingState = ListAlerts::STATE_FIRING;
                $statuses[$id] = self::status($firingState, $label, $earliest, $formatted);
            } elseif ($evaluated) {
                $statuses[$id] = self::status(ListAlerts::STATE_OK, 'OK');
            } else {
                $label = 'Waiting for the first full hour';
                $statuses[$id] = self::status(ListAlerts::STATE_WAITING, $label);
            }
        }

        return ['firing_count' => $firing, 'rules' => $statuses];
    }

    /** @param RuleArray $rule */
    private static function problem(array $rule, RuleReferences $references): ?string
    {
        try {
            $references->assertCoversExist($rule['covers']);
            $source = $rule['source'];
            $references->assertWindowFitsRetention($source, $rule['privacy_mode'], $rule['window']);
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * @param 'ok'|'firing'|'waiting'|'off'|'problem' $state
     *
     * @return Status
     */
    private static function status(
        string $state,
        string $label,
        ?string $since = null,
        ?string $formatted = null,
    ): array {
        return [
            'state' => $state,
            'label' => $label,
            'since' => $since,
            'since_formatted' => $formatted,
        ];
    }
}
