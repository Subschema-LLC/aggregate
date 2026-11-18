<?php

declare(strict_types=1);

namespace App\Entities\Incident;

/**
 * The state of one rule on one website: `ok`, `firing:<since>` or
 * `recovered:<window_end>:<since>`. The state names the current alert, the
 * one every destination should have.
 *
 * @phpstan-type ParsedState array{
 *     kind: 'none'|'ok'|'firing'|'recovered',
 *     since: ?string,
 *     recovered_at: ?string
 * }
 */
final class IncidentState
{
    public const OK = 'ok';

    /**
     * The key of a rule and website's state. A website token that would make
     * it longer than 191 characters is hashed; such a state cannot name its
     * website, so it is never closed automatically.
     */
    public static function subject(string $ruleId, string $websiteToken): string
    {
        $subject = $ruleId.':'.$websiteToken;

        return strlen($subject) <= 191 ? $subject : $ruleId.':#'.hash('sha256', $websiteToken);
    }

    public static function firing(string $since): string
    {
        return 'firing:'.$since;
    }

    public static function recovered(string $windowEnd, string $since): string
    {
        return 'recovered:'.$windowEnd.':'.$since;
    }

    /** @return ParsedState */
    public static function parse(?string $state): array
    {
        if ($state === null) {
            return ['kind' => 'none', 'since' => null, 'recovered_at' => null];
        }
        if (str_starts_with($state, 'firing:')) {
            $since = substr($state, 7);
            if (UtcHour::parse($since) !== null) {
                return ['kind' => 'firing', 'since' => $since, 'recovered_at' => null];
            }
        }
        if (str_starts_with($state, 'recovered:')) {
            $rest = substr($state, 10);
            $recoveredAt = substr($rest, 0, UtcHour::LENGTH);
            $since = substr($rest, UtcHour::LENGTH + 1);
            if (UtcHour::parse($recoveredAt) !== null && UtcHour::parse($since) !== null) {
                return ['kind' => 'recovered', 'since' => $since, 'recovered_at' => $recoveredAt];
            }
        }

        return ['kind' => 'ok', 'since' => null, 'recovered_at' => null];
    }

    /** The alert a state stands for, or null for none. */
    public static function currentAlert(
        string $state,
        string $ruleId,
        string $websiteToken,
    ): AlertFired|AlertRecovered|null {
        $parsed = self::parse($state);
        if ($parsed['kind'] === 'firing' && $parsed['since'] !== null) {
            return new AlertFired($ruleId, $websiteToken, $parsed['since']);
        }
        if ($parsed['kind'] === 'recovered' && $parsed['since'] !== null
            && $parsed['recovered_at'] !== null) {
            return new AlertRecovered(
                $ruleId,
                $websiteToken,
                $parsed['recovered_at'],
                $parsed['since'],
            );
        }

        return null;
    }
}
