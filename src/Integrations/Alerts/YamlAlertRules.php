<?php

declare(strict_types=1);

namespace App\Integrations\Alerts;

use App\Entities\AlertRule\AlertRules;

/** Alert rules under `alerts.rules` in config/aggregate.yaml. */
final class YamlAlertRules implements AlertRules
{
    public function __construct(private readonly AlertsYamlStore $store)
    {
    }

    public function find(string $id): ?array
    {
        return $this->store->load()['rules'][$id] ?? null;
    }

    public function save(array $candidate): array
    {
        return $this->store->saveRule($candidate);
    }

    public function delete(string $id): void
    {
        $this->store->deleteRule($id);
    }
}
