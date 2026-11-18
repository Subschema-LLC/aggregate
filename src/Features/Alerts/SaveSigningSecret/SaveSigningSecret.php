<?php

declare(strict_types=1);

namespace App\Features\Alerts\SaveSigningSecret;

use App\Entities\Destination\SigningSecrets;

/**
 * Sets, generates or removes the shared webhook signing secret (a null
 * destination ID) or a destination's own. A destination signs with its own
 * secret from the environment, then its own saved one, then the shared one from
 * the environment, then the shared saved one; a secret is refused here only
 * when the environment sets that same secret, because the saved one would be
 * ignored.
 */
final class SaveSigningSecret
{
    public function __construct(private readonly SigningSecrets $secrets)
    {
    }

    public function set(#[\SensitiveParameter] string $value, ?string $destinationId): void
    {
        $this->assertChangeable($destinationId);
        $this->secrets->set($value, $destinationId);
    }

    /** @return string the new secret, to be shown once */
    public function generate(?string $destinationId): string
    {
        $this->assertChangeable($destinationId);

        return $this->secrets->generate($destinationId);
    }

    /** A destination without a secret of its own signs with the shared one. */
    public function remove(?string $destinationId): void
    {
        $this->assertChangeable($destinationId);
        $this->secrets->remove($destinationId);
    }

    /** Throws when the environment sets this secret, so a saved one would be ignored. */
    public function assertChangeable(?string $destinationId): void
    {
        $id = $destinationId === null ? '' : trim($destinationId);
        if ($id !== '' && $this->secrets->ownIsSetByEnvironment($id)) {
            throw new \InvalidArgumentException(sprintf(
                'This destination\'s signing secret is set by %s in the environment and cannot be '
                .'changed here.',
                $this->secrets->environmentName($id),
            ));
        }
        if ($id === '' && $this->secrets->isSetByEnvironment(null)) {
            throw new \InvalidArgumentException('The webhook signing secret is set in the '
                .'environment and cannot be changed here.');
        }
    }
}
