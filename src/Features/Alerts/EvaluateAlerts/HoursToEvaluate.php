<?php

declare(strict_types=1);

namespace App\Features\Alerts\EvaluateAlerts;

/**
 * The completed UTC hours after the last evaluated one, at most
 * MAX_CATCHUP_HOURS of them; just the latest hour when there is no earlier one.
 */
final class HoursToEvaluate
{
    public const MAX_CATCHUP_HOURS = 24;

    /** @return list<\DateTimeImmutable> */
    public static function between(
        ?\DateTimeImmutable $cursor,
        \DateTimeImmutable $completed,
    ): array
    {
        if ($cursor === null || $cursor >= $completed) {
            return [$completed];
        }

        $earliest = $completed->modify('-'.(self::MAX_CATCHUP_HOURS - 1).' hours');
        $current = max($cursor->modify('+1 hour'), $earliest);

        $hours = [];
        while ($current <= $completed) {
            $hours[] = $current;
            $current = $current->modify('+1 hour');
        }

        return $hours === [] ? [$completed] : $hours;
    }
}
