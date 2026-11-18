<?php

declare(strict_types=1);

namespace App\Integrations\Alerts;

use App\Entities\Destination\SigningSecrets;
use App\Service\Alerts\AlertSecrets;

/** Signing secrets in config/secrets/alerts.yaml, with environment overrides. */
final class FileSigningSecrets implements SigningSecrets
{
    public function __construct(private readonly AlertSecrets $secrets)
    {
    }

    public function isSetByEnvironment(?string $destinationId): bool
    {
        return $this->secrets->isEnvironmentControlled($destinationId);
    }

    public function ownIsSetByEnvironment(string $destinationId): bool
    {
        return $this->secrets->hasOwnInEnvironment($destinationId);
    }

    public function environmentName(string $destinationId): string
    {
        return AlertSecrets::envNameFor($destinationId);
    }

    public function set(#[\SensitiveParameter] string $value, ?string $destinationId): void
    {
        $this->secrets->set($value, $destinationId);
    }

    public function generate(?string $destinationId): string
    {
        return $this->secrets->generate($destinationId);
    }

    public function remove(?string $destinationId): void
    {
        $this->secrets->remove($destinationId);
    }
}
