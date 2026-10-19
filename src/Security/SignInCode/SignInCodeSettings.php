<?php

declare(strict_types=1);

namespace App\Security\SignInCode;

use App\Service\AggregateConfigLoader;
use App\Service\Email\TransactionalMailer;

/**
 * The two uses of emailed codes, each a YAML switch (off by default) with an
 * uppercase environment override, also on the Email page:
 *
 * - sign_in_codes_enabled: a code instead of the password (sign-in codes);
 * - mfa_required: a code after the password, for every user (two-step
 *   sign-in).
 *
 * While two-step sign-in is required, a code alone signs nobody in, since
 * the code is then the second factor. Sign-in codes are offered only while
 * email can be sent; two-step sign-in is enforced whatever the state of email,
 * failing closed, so an email outage stops sign-ins rather than skipping the
 * second step.
 */
class SignInCodeSettings
{
    public const KEY = 'sign_in_codes_enabled';
    public const KEY_MFA = 'mfa_required';

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

    /** Switched on, two-step sign-in not required, and email can be sent: whether the sign-in page offers a code. */
    public function isAvailable(): bool
    {
        try {
            return $this->isEnabled() && !$this->isSecondStepRequired() && $this->mailer->problem() === null;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Whether every password sign-in needs an emailed code too. An invalid
     * value counts as required: the setting fails closed.
     */
    public function isSecondStepRequired(): bool
    {
        try {
            $this->config->assertHealthy();

            return self::validate($this->config->getWithEnvFallback(self::KEY_MFA, false), self::KEY_MFA);
        } catch (\Throwable) {
            return true;
        }
    }

    public function isSecondStepEnvironmentControlled(): bool
    {
        return $this->config->hasEnvironmentOverride(self::KEY_MFA);
    }

    /** What stops a second step from being sent now, or null; see TransactionalMailer::problem(). */
    public function secondStepProblem(): ?string
    {
        return $this->mailer->problem();
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

    /** Writes the two-step switch unless the environment controls it; returns the value in effect. */
    public function saveSecondStep(mixed $value): bool
    {
        $required = self::validate($value, self::KEY_MFA);
        if ($this->isSecondStepEnvironmentControlled()) {
            return $this->isSecondStepRequired();
        }
        if ($this->config->get(self::KEY_MFA) !== $required) {
            $this->config->set(self::KEY_MFA, $required);
        }

        return $required;
    }

    public static function validate(mixed $value, string $key = self::KEY): bool
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

        throw new \InvalidArgumentException($key.' must be true or false.');
    }
}
