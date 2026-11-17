<?php

declare(strict_types=1);

namespace App\Entities\Incident;

/**
 * Alert and incident IDs. Both are derived, never stored first: the same
 * alert gets the same ID on every retry and every installation-wide re-check,
 * so a receiver can drop duplicates and a destination is never sent one twice.
 */
final class AlertIds
{
    /** Distinct across installations, rules, websites, alert types and windows. */
    public static function alert(
        string $installation,
        string $ruleId,
        string $websiteToken,
        string $type,
        string $windowEndIso,
    ): string {
        $parts = ['alert-v1', $installation, $ruleId, $websiteToken, $type, $windowEndIso];

        return substr(hash('sha256', implode("\0", $parts)), 0, 24);
    }

    /** Shared by a firing alert and the recovery that closes it, keyed by when it started. */
    public static function incident(
        string $installation,
        string $ruleId,
        string $websiteToken,
        string $sinceIso,
    ): string {
        $parts = ['incident-v1', $installation, $ruleId, $websiteToken, $sinceIso];

        return substr(hash('sha256', implode("\0", $parts)), 0, 24);
    }
}
