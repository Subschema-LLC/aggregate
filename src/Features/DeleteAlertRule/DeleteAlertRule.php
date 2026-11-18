<?php

declare(strict_types=1);

namespace App\Features\DeleteAlertRule;

use App\Entities\AlertRule\AlertRules;

/** Deletes an alert rule; an incident it left open is closed at the next check. */
final class DeleteAlertRule
{
    public function __construct(private readonly AlertRules $rules)
    {
    }

    public function delete(string $id): void
    {
        $this->rules->delete(trim($id));
    }
}
