<?php

declare(strict_types=1);

namespace App\Entities\AlertRule;

/**
 * The registered websites a rule covers, in the order they are registered.
 *
 * @phpstan-import-type RuleArray from AlertRule
 */
interface RuleCoverage
{
    /**
     * @param RuleArray $rule
     *
     * @return list<array{token: string, name: string, domain: string}>
     */
    public function of(array $rule): array;
}
