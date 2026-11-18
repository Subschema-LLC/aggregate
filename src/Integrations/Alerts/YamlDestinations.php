<?php

declare(strict_types=1);

namespace App\Integrations\Alerts;

use App\Entities\Destination\Destinations;

/** Alert destinations under `alerts.destinations` in config/aggregate.yaml. */
final class YamlDestinations implements Destinations
{
    public function __construct(private readonly AlertsYamlStore $store)
    {
    }

    public function find(string $id): ?array
    {
        return $this->store->load()['destinations'][$id] ?? null;
    }

    public function save(array $candidate): array
    {
        return $this->store->saveDestination($candidate);
    }

    public function delete(string $id): void
    {
        $this->store->deleteDestination($id);
    }
}
