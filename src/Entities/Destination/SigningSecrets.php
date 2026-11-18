<?php

declare(strict_types=1);

namespace App\Entities\Destination;

/**
 * The secrets webhook destinations sign with: one shared secret (a null
 * destination ID) and an optional secret of a destination's own. A secret set
 * in the environment wins over the saved one and cannot be changed here.
 */
interface SigningSecrets
{
    /** Whether the environment sets the secret this destination (or the shared one) uses. */
    public function isSetByEnvironment(?string $destinationId): bool;

    /** Whether the environment sets a secret of this destination's own. */
    public function ownIsSetByEnvironment(string $destinationId): bool;

    /** The environment variable that would set a destination's own secret. */
    public function environmentName(string $destinationId): string;

    public function set(#[\SensitiveParameter] string $value, ?string $destinationId): void;

    /** Saves a new random secret and returns it, to be shown once. */
    public function generate(?string $destinationId): string;

    public function remove(?string $destinationId): void;
}
