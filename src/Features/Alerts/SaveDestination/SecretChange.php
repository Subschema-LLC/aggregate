<?php

declare(strict_types=1);

namespace App\Features\Alerts\SaveDestination;

use App\Entities\Destination\SigningSecrets;

/** What to do with a webhook destination's own signing secret when it is saved. */
final class SecretChange
{
    private function __construct(
        private readonly string $action,
        private readonly string $value = '',
    ) {
    }

    public static function keep(): self
    {
        return new self('keep');
    }

    public static function generate(): self
    {
        return new self('generate');
    }

    /** Sign with the shared secret: the destination's own is removed. */
    public static function useShared(): self
    {
        return new self('shared');
    }

    /** A blank value keeps the saved secret. */
    public static function set(#[\SensitiveParameter] string $value): self
    {
        $value = trim($value);

        return $value === '' ? self::keep() : new self('set', $value);
    }

    public function changes(): bool
    {
        return $this->action !== 'keep';
    }

    /** @return string|null a generated secret, to be shown once */
    public function applyTo(SigningSecrets $secrets, string $destinationId): ?string
    {
        return match ($this->action) {
            'generate' => $secrets->generate($destinationId),
            'shared' => $this->remove($secrets, $destinationId),
            'set' => $this->store($secrets, $destinationId),
            default => null,
        };
    }

    private function remove(SigningSecrets $secrets, string $destinationId): null
    {
        $secrets->remove($destinationId);

        return null;
    }

    private function store(SigningSecrets $secrets, string $destinationId): null
    {
        $secrets->set($this->value, $destinationId);

        return null;
    }
}
