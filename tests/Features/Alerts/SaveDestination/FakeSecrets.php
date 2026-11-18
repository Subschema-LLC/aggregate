<?php

declare(strict_types=1);

namespace App\Tests\Features\Alerts\SaveDestination;

use App\Entities\Destination\SigningSecrets;

/** Signing secrets in memory; a key in $environment counts as set by the environment. */
final class FakeSecrets implements SigningSecrets
{
    /** @var array<string, string> '' is the shared secret */
    public array $saved = [];
    /** @var array<string, string> */
    public array $environment = [];

    public function isSetByEnvironment(?string $destinationId): bool
    {
        return isset($this->environment[$destinationId ?? '']) || isset($this->environment['']);
    }

    public function ownIsSetByEnvironment(string $destinationId): bool
    {
        return isset($this->environment[$destinationId]);
    }

    public function environmentName(string $destinationId): string
    {
        return 'ALERTS_WEBHOOK_SECRET_'.strtoupper($destinationId);
    }

    public function set(#[\SensitiveParameter] string $value, ?string $destinationId): void
    {
        $this->saved[$destinationId ?? ''] = $value;
    }

    public function generate(?string $destinationId): string
    {
        return $this->saved[$destinationId ?? ''] = 'generated-secret';
    }

    public function remove(?string $destinationId): void
    {
        unset($this->saved[$destinationId ?? '']);
    }
}
