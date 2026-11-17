<?php

declare(strict_types=1);

namespace App\Features\EvaluateAlerts;

use App\Entities\Incident\AlertFired;
use App\Entities\Incident\AlertRecovered;

/**
 * What one evaluation decided, in the order it must happen: alerts to
 * deliver, states to save, and incidents of deleted rules to close without a
 * recovery. An alert that is not retryable gets one attempt only.
 *
 * @phpstan-type Step array{kind: 'deliver', alert: AlertFired|AlertRecovered, retryable: bool}
 *     |array{kind: 'save', subject: string, state: string}
 *     |array{kind: 'deleted', subject: string, rule_id: string, website_token: string}
 */
final class Evaluation
{
    /** @var list<Step> */
    private array $steps = [];

    public function deliver(AlertFired|AlertRecovered $alert, bool $retryable): void
    {
        $this->steps[] = ['kind' => 'deliver', 'alert' => $alert, 'retryable' => $retryable];
    }

    public function save(string $subject, string $state): void
    {
        $this->steps[] = ['kind' => 'save', 'subject' => $subject, 'state' => $state];
    }

    public function closeDeleted(string $subject, string $ruleId, string $websiteToken): void
    {
        $this->steps[] = [
            'kind' => 'deleted',
            'subject' => $subject,
            'rule_id' => $ruleId,
            'website_token' => $websiteToken,
        ];
    }

    /** @return list<Step> */
    public function steps(): array
    {
        return $this->steps;
    }
}
