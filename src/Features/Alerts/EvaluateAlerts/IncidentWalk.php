<?php

declare(strict_types=1);

namespace App\Features\Alerts\EvaluateAlerts;

use App\Entities\AlertRule\AlertRule;
use App\Entities\AlertRule\WindowBreaches;
use App\Entities\Incident\AlertRecovered;
use App\Entities\Incident\IncidentState;
use App\Entities\Incident\UtcHour;

/**
 * Walks the hours to evaluate for one rule and website and works out the new
 * state. Only the net change counts: an incident that opened and closed during
 * catch-up sends nothing, and one that closed and opened again closes the
 * earlier incident (the closing alert) before the new one fires.
 *
 * @phpstan-import-type RuleArray from AlertRule
 */
final class IncidentWalk
{
    public function __construct(private readonly WindowBreaches $breaches)
    {
    }

    /**
     * @param RuleArray                $rule
     * @param list<\DateTimeImmutable> $hours
     *
     * @return array{0: string, 1: ?AlertRecovered} the new state and the closing alert
     */
    public function walk(array $rule, string $token, array $hours, ?string $persisted): array
    {
        $before = IncidentState::parse($persisted);
        $wasFiring = $before['kind'] === 'firing';
        $firing = $wasFiring;
        $since = $before['since'];
        $persistedClosedAt = null;

        foreach ($hours as $hourEnd) {
            $breached = $this->breaches->isBreached($rule, $token, $hourEnd);
            if ($breached && !$firing) {
                $firing = true;
                $since = UtcHour::format($hourEnd);
            } elseif (!$breached && $firing) {
                if ($wasFiring && $persistedClosedAt === null && $since === $before['since']) {
                    $persistedClosedAt = UtcHour::format($hourEnd);
                }
                $firing = false;
            }
        }

        $closesPersisted = $wasFiring && $persistedClosedAt !== null && $before['since'] !== null;
        if ($firing && $since !== null && (!$wasFiring || $since !== $before['since'])) {
            $closing = $closesPersisted
                ? new AlertRecovered(
                    $rule['id'],
                    $token,
                    (string) $persistedClosedAt,
                    (string) $before['since'],
                )
                : null;

            return [IncidentState::firing($since), $closing];
        }
        if ($closesPersisted) {
            return [
                IncidentState::recovered((string) $persistedClosedAt, (string) $before['since']),
                null,
            ];
        }

        return [$persisted ?? IncidentState::OK, null];
    }
}
