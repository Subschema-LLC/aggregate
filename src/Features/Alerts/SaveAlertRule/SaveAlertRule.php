<?php

declare(strict_types=1);

namespace App\Features\Alerts\SaveAlertRule;

use App\Entities\AlertRule\AlertRule;
use App\Entities\AlertRule\AlertRules;

/**
 * Creates or edits an alert rule, for the Alerts page and app:alerts:rule
 * alike. Creating refuses an ID that is taken; editing needs the rule to
 * exist. The rule itself is checked when it is saved.
 *
 * @phpstan-import-type RuleArray from AlertRule
 */
final class SaveAlertRule
{
    public function __construct(private readonly AlertRules $rules)
    {
    }

    /**
     * @param array<string, mixed> $candidate
     *
     * @return RuleArray
     */
    public function create(array $candidate): array
    {
        $id = self::idOf($candidate);
        if ($id !== '' && $this->rules->find($id) !== null) {
            throw new RuleAlreadyExists($id);
        }

        return $this->rules->save($candidate);
    }

    /**
     * @param array<string, mixed> $candidate
     *
     * @return RuleArray
     */
    public function edit(array $candidate): array
    {
        $id = self::idOf($candidate);
        if ($this->rules->find($id) === null) {
            throw new RuleNotFound($id);
        }

        return $this->rules->save($candidate);
    }

    /** @param array<string, mixed> $candidate */
    private static function idOf(array $candidate): string
    {
        $id = $candidate['id'] ?? $candidate['rule'] ?? '';

        return is_string($id) ? trim($id) : '';
    }
}
