<?php

declare(strict_types=1);

namespace App\Security\SignInCode;

use App\Service\AggregateConfigLoader;
use App\Service\Email\TransactionalMailer;

/**
 * Whether users may sign in with a code emailed to their address, instead of
 * their password: sign_in_codes_enabled in YAML (off by default), the
 * SIGN_IN_CODES_ENABLED environment variable, or the Email page. Codes are
 * offered only while email can also be sent, so turning email off or losing
 * its credential hides the option rather than sending nothing.
 */
class SignInCodeSettings
{
    public const KEY = 'sign_in_codes_enabled';

    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly TransactionalMailer $mailer,
    ) {
    }

    /** @throws \InvalidArgumentException|\RuntimeException when YAML or the environment is invalid */
    public function isEnabled(): bool
    {
        $this->config->assertHealthy();

        return self::validate($this->config->getWithEnvFallback(self::KEY, false));
    }

    public function isEnvironmentControlled(): bool
    {
        return $this->config->hasEnvironmentOverride(self::KEY);
    }

    /** Switched on and email can be sent: whether the sign-in page offers a code. */
    public function isAvailable(): bool
    {
        try {
            return $this->isEnabled() && $this->mailer->problem() === null;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Writes the switch unless the environment controls it; returns the value in effect. */
    public function save(mixed $value): bool
    {
        $enabled = self::validate($value);
        if ($this->isEnvironmentControlled()) {
            return $this->isEnabled();
        }
        if ($this->config->get(self::KEY) !== $enabled) {
            $this->config->set(self::KEY, $enabled);
        }

        return $enabled;
    }

    public static function validate(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }
        if (is_string($value)) {
            $value = strtolower(trim($value));
            if (in_array($value, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($value, ['0', 'false', 'no', 'off', ''], true)) {
                return false;
            }
        }

        throw new \InvalidArgumentException(self::KEY.' must be true or false.');
    }
}
