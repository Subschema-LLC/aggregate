<?php

declare(strict_types=1);

namespace App\Features\EvaluateAlerts;

use App\Entities\AlertRule\AlertRule;
use App\Entities\Incident\AlertRecovered;
use App\Entities\Incident\IncidentState;
use App\Entities\Incident\UtcHour;

/**
 * Closes incidents the rule check did not reach: a rule turned off or no
 * longer covering a website sends a recovery; a deleted rule has no
 * destinations left, so its incident closes without one. A recovery not yet
 * delivered is offered again.
 *
 * @phpstan-import-type RuleArray from AlertRule
 */
final class UncheckedIncidents
{
    /**
     * @param array<string, ?string>      $states   persisted state per subject
     * @param array<string, true>         $checked  subjects the rule check reached
     * @param array<string, RuleArray>    $rules    every configured rule
     * @param array<string, list<string>> $coverage website tokens each rule covers
     */
    public static function close(
        array $states,
        array $checked,
        array $rules,
        array $coverage,
        \DateTimeImmutable $completedHour,
        Evaluation $evaluation,
    ): void {
        foreach ($states as $subject => $state) {
            $subject = (string) $subject;
            if ($subject === '' || isset($checked[$subject])) {
                continue;
            }
            $before = IncidentState::parse($state);
            $since = $before['since'];
            $parts = explode(':', $subject, 2);
            if (!in_array($before['kind'], ['firing', 'recovered'], true)
                || count($parts) !== 2 || $since === null || str_starts_with($parts[1], '#')) {
                continue;
            }
            [$ruleId, $token] = $parts;

            $rule = $rules[$ruleId] ?? null;
            if ($rule === null) {
                if ($before['kind'] === 'firing') {
                    $evaluation->closeDeleted($subject, $ruleId, $token);
                }
                continue;
            }
            if ($rule['enabled'] && in_array($token, $coverage[$ruleId] ?? [], true)) {
                // Still covered but skipped for a reference problem: its state is unknown.
                continue;
            }

            if ($before['kind'] === 'firing') {
                $closeAt = UtcHour::format($completedHour);
                if (strcmp($closeAt, $since) <= 0) {
                    // Turned off in the hour it fired: close at the next check, so
                    // the recovery's window_end is later than the firing alert's.
                    continue;
                }
                $evaluation->deliver(new AlertRecovered($ruleId, $token, $closeAt, $since), true);
                $evaluation->save($subject, IncidentState::recovered($closeAt, $since));
                continue;
            }

            $recovered = IncidentState::recovered((string) $before['recovered_at'], $since);
            $current = IncidentState::currentAlert($recovered, $ruleId, $token);
            if ($current !== null) {
                $evaluation->deliver($current, true);
            }
        }
    }
}
