<?php

declare(strict_types=1);

namespace App\Entities\AlertRule;

/**
 * Where alert rules are kept. Saving checks the rule and what it refers to,
 * and throws \InvalidArgumentException with a message for the person saving.
 *
 * @phpstan-import-type RuleArray from AlertRule
 */
interface AlertRules
{
    /**
     * Every rule in its saved order, keyed by ID.
     *
     * @return array<string, RuleArray>
     */
    public function all(): array;

    /** @return RuleArray|null */
    public function find(string $id): ?array;

    /**
     * @param array<string, mixed> $candidate
     *
     * @return RuleArray
     */
    public function save(array $candidate): array;

    public function delete(string $id): void;
}
