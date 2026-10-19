<?php

declare(strict_types=1);

namespace App\Service\Email;

/**
 * The one secret an email provider needs: its key in
 * config/secrets/email.yaml, the environment variable that overrides it
 * (EMAIL_ plus the key in capitals), and how the settings page names it.
 */
final readonly class EmailCredential
{
    public string $environmentVariable;

    public function __construct(
        public string $key,
        public string $label,
        public string $help,
        public bool $required = true,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $key) !== 1) {
            throw new \InvalidArgumentException('An email credential key must be a short lowercase code.');
        }
        $this->environmentVariable = 'EMAIL_'.strtoupper($key);
    }
}
