<?php

declare(strict_types=1);

namespace App\Features\Incidents\EvaluateAlerts;

use App\Entities\AlertRule\AlertRule;
use App\Entities\AlertRule\WindowBreaches;
use App\Entities\Incident\IncidentState;

/**
 * Works out whether the completed hours since the last evaluation open or
 * close an incident. It reads only the counts WindowBreaches gives it and
 * changes nothing: each Evaluation lists the alerts to deliver and the states
 * to save, in order. One website is decided at a time, so the caller can act
 * on each before the next is counted and a failing count stops only the
 * websites after it.
 *
 * @phpstan-import-type RuleArray from AlertRule
 */
final class EvaluateAlerts
{
    private readonly IncidentWalk $walk;

    public function __construct(WindowBreaches $breaches)
    {
        $this->walk = new IncidentWalk($breaches);
    }

    /** @return list<\DateTimeImmutable> */
    public function hours(?\DateTimeImmutable $lastEvaluated, \DateTimeImmutable $completed): array
    {
        return HoursToEvaluate::between($lastEvaluated, $completed);
    }

    /**
     * One enabled rule on one website it covers.
     *
     * @param RuleArray                $rule
     * @param list<\DateTimeImmutable> $hours
     */
    public function evaluateWebsite(
        array $rule,
        string $token,
        array $hours,
        ?string $persisted,
    ): Evaluation {
        $evaluation = new Evaluation();
        [$state, $closing] = $this->walk->walk($rule, $token, $hours, $persisted);

        if ($closing !== null) {
            $evaluation->deliver($closing, false);
        }
        $current = IncidentState::currentAlert($state, $rule['id'], $token);
        if ($current !== null) {
            $evaluation->deliver($current, true);
        }
        if ($state !== $persisted) {
            $evaluation->save(IncidentState::subject($rule['id'], $token), $state);
        }

        return $evaluation;
    }

    /**
     * Incidents of rules turned off, deleted or no longer covering a website.
     *
     * @param array<string, ?string>      $states   persisted state per subject
     * @param array<string, true>         $checked  subjects evaluateWebsite() covered
     * @param array<string, RuleArray>    $rules    every configured rule
     * @param array<string, list<string>> $coverage website tokens each rule covers
     */
    public function closeUnchecked(
        array $states,
        array $checked,
        array $rules,
        array $coverage,
        \DateTimeImmutable $completedHour,
    ): Evaluation {
        $evaluation = new Evaluation();
        UncheckedIncidents::close(
            $states,
            $checked,
            $rules,
            $coverage,
            $completedHour,
            $evaluation,
        );

        return $evaluation;
    }
}
