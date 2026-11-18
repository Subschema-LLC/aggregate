<?php

declare(strict_types=1);

namespace App\Entities\AlertRule;

/**
 * Whether a rule's window ending at $hourEnd breaks the rule for a website:
 * too few matching events, or more tracking failures than its threshold.
 *
 * @phpstan-import-type RuleArray from AlertRule
 */
interface WindowBreaches
{
    /** @param RuleArray $rule */
    public function isBreached(
        array $rule,
        string $websiteToken,
        \DateTimeImmutable $hourEnd,
    ): bool;
}
