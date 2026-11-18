<?php

declare(strict_types=1);

namespace App\Entities\Destination;

/**
 * Where alert destinations are kept. A destination a rule still uses cannot be
 * deleted; problems throw \InvalidArgumentException with a message for people.
 *
 * @phpstan-import-type DestinationArray from Destination
 */
interface Destinations
{
    /** @return DestinationArray|null */
    public function find(string $id): ?array;

    /**
     * @param array<string, mixed> $candidate
     *
     * @return DestinationArray
     */
    public function save(array $candidate): array;

    public function delete(string $id): void;
}
