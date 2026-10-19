<?php

declare(strict_types=1);

namespace App\Service\Email;

use App\Service\AggregateConfigLoader;

/**
 * Transactional email settings: one source for the Email page, YAML
 * (config/aggregate.yaml), environment variables and the app:email:*
 * commands. The common settings choose the provider and the sender; each
 * provider adds its own email_<code>_* settings (EmailProvider). Uppercase
 * environment variables take precedence and lock the matching fields.
 *
 * Credentials are not settings: they live in config/secrets/email.yaml or the
 * environment (EmailSecrets), so this YAML can be shared and exported.
 */
class EmailSettings
{
    public const KEY_ENABLED = 'email_enabled';
    public const KEY_PROVIDER = 'email_provider';
    public const KEY_FROM_ADDRESS = 'email_from_address';
    public const KEY_FROM_NAME = 'email_from_name';
    public const KEY_REPLY_TO = 'email_reply_to';

    public const MAX_ADDRESS = 254;
    public const MAX_NAME = 100;

    /** Text settings an environment variable may set to empty, overriding YAML. */
    private const EMPTY_ENVIRONMENT_KEYS = [self::KEY_FROM_NAME, self::KEY_REPLY_TO];

    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly EmailProviders $providers,
    ) {
    }

    public function providers(): EmailProviders
    {
        return $this->providers;
    }

    /** @return array<string, mixed> every email setting and its default */
    public function defaults(): array
    {
        $defaults = [
            self::KEY_ENABLED => false,
            self::KEY_PROVIDER => '',
            self::KEY_FROM_ADDRESS => '',
            self::KEY_FROM_NAME => '',
            self::KEY_REPLY_TO => '',
        ];
        foreach ($this->providers->all() as $provider) {
            foreach ($provider->settingDefaults() as $key => $default) {
                if (!str_starts_with($key, 'email_'.$provider->code().'_') || array_key_exists($key, $defaults)) {
                    throw new \LogicException(sprintf('Email provider %s has a setting outside email_%s_*: %s.', $provider->code(), $provider->code(), $key));
                }
                $defaults[$key] = $default;
            }
        }

        return $defaults;
    }

    /**
     * The effective, validated settings.
     *
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException|\RuntimeException when YAML or the environment is invalid
     */
    public function toArray(): array
    {
        $this->config->assertHealthy();
        $values = [];
        foreach ($this->defaults() as $key => $default) {
            $values[$key] = $this->config->getWithEnvFallback($key, $default, in_array($key, self::EMPTY_ENVIRONMENT_KEYS, true));
        }

        return $this->validate($values);
    }

    /** @return array<string, bool> setting => controlled by an environment variable */
    public function environmentOverrides(): array
    {
        $overrides = [];
        foreach (array_keys($this->defaults()) as $key) {
            $overrides[$key] = $this->config->hasEnvironmentOverride($key, in_array($key, self::EMPTY_ENVIRONMENT_KEYS, true));
        }

        return $overrides;
    }

    /**
     * Validates a complete candidate and writes the values the environment does
     * not control. Nothing is written when any value is invalid.
     *
     * @param array<string, mixed> $candidate
     *
     * @return array<string, mixed> the validated settings now in effect
     */
    public function save(array $candidate): array
    {
        if (array_diff_key($candidate, $this->defaults()) !== []) {
            throw new \InvalidArgumentException('The form contained an unexpected email setting. Nothing was saved.');
        }
        $current = $this->toArray();
        $overrides = $this->environmentOverrides();
        foreach ($overrides as $key => $overridden) {
            if ($overridden) {
                $candidate[$key] = $current[$key];
            }
        }
        $validated = $this->validate([...$current, ...$candidate]);
        $values = array_filter($validated, static fn (string $key): bool => !$overrides[$key], ARRAY_FILTER_USE_KEY);
        if ($values !== []) {
            $this->config->setMany($values);
        }

        return $validated;
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    public function validate(array $values): array
    {
        $values = array_replace($this->defaults(), $values);

        $enabled = self::boolean($values[self::KEY_ENABLED]) ?? throw new \InvalidArgumentException(self::KEY_ENABLED.' must be true or false.');

        $provider = $values[self::KEY_PROVIDER];
        $provider = is_string($provider) ? strtolower(trim($provider)) : ($provider === null ? '' : $provider);
        if (!is_string($provider) || ($provider !== '' && !$this->providers->has($provider))) {
            throw new \InvalidArgumentException(self::KEY_PROVIDER.' must be one of '.implode(', ', $this->providers->codes()).'.');
        }
        if ($enabled && $provider === '') {
            throw new \InvalidArgumentException('Choose the email service before turning email on.');
        }

        $fromAddress = self::address($values[self::KEY_FROM_ADDRESS], self::KEY_FROM_ADDRESS);
        if ($enabled && $fromAddress === '') {
            throw new \InvalidArgumentException('Enter the address email is sent from before turning email on.');
        }
        $fromName = self::name($values[self::KEY_FROM_NAME]);
        $replyTo = self::address($values[self::KEY_REPLY_TO], self::KEY_REPLY_TO);

        $validated = [
            self::KEY_ENABLED => $enabled,
            self::KEY_PROVIDER => $provider,
            self::KEY_FROM_ADDRESS => $fromAddress,
            self::KEY_FROM_NAME => $fromName,
            self::KEY_REPLY_TO => $replyTo,
        ];
        foreach ($this->providers->all() as $code => $definition) {
            $validated += $definition->validate($values, $enabled && $code === $provider);
        }

        return $validated;
    }

    /** @param array<string, mixed> $settings validated settings */
    public static function isEnabled(array $settings): bool
    {
        return $settings[self::KEY_ENABLED] === true;
    }

    private static function address(mixed $value, string $key): string
    {
        if ($value === null) {
            return '';
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException($key.' must be an email address.');
        }
        $value = trim($value);
        if ($value !== '' && (strlen($value) > self::MAX_ADDRESS || filter_var($value, FILTER_VALIDATE_EMAIL) === false)) {
            throw new \InvalidArgumentException($key.' must be an email address, such as no-reply@example.com.');
        }

        return $value;
    }

    private static function name(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (!is_string($value) || mb_strlen($value) > self::MAX_NAME || preg_match('/[\x00-\x1F\x7F]/u', $value) === 1 || !mb_check_encoding($value, 'UTF-8')) {
            throw new \InvalidArgumentException(self::KEY_FROM_NAME.' must be a name of at most '.self::MAX_NAME.' characters on one line.');
        }

        return trim($value);
    }

    private static function boolean(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }
        if (is_string($value)) {
            return match (strtolower(trim($value))) {
                '1', 'true', 'yes', 'on' => true,
                '0', 'false', 'no', 'off' => false,
                default => null,
            };
        }

        return null;
    }
}
