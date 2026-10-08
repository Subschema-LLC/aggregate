<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Shared settings for retrying failed enhanced tracking messages.
 */
final class TrackingFailureSettings
{
    public const KEY_ENABLED = 'tracking_retry_enabled';
    public const KEY_BATCH_SIZE = 'tracking_retry_batch_size';

    public function __construct(private readonly AggregateConfigLoader $config)
    {
    }

    /** @return array{tracking_retry_enabled: bool, tracking_retry_batch_size: int} */
    public static function defaults(): array
    {
        return [
            self::KEY_ENABLED => true,
            self::KEY_BATCH_SIZE => 100,
        ];
    }

    /** @return array{tracking_retry_enabled: bool, tracking_retry_batch_size: int} */
    public function toArray(): array
    {
        $this->config->assertHealthy();
        $values = [];
        foreach (self::defaults() as $key => $default) {
            $values[$key] = $this->config->getWithEnvFallback($key, $default);
        }

        return self::validate($values);
    }

    /** @return array<string, bool> */
    public function getEnvironmentOverrides(): array
    {
        $overrides = [];
        foreach (array_keys(self::defaults()) as $key) {
            $overrides[$key] = $this->config->hasEnvironmentOverride($key);
        }

        return $overrides;
    }

    /**
     * @param array<string, mixed> $candidate
     */
    public function save(array $candidate): void
    {
        if (array_diff_key($candidate, self::defaults()) !== []) {
            throw new \InvalidArgumentException('The form contained an unexpected tracking retry setting. Nothing was saved.');
        }
        $current = $this->toArray();
        $overrides = $this->getEnvironmentOverrides();
        foreach ($overrides as $key => $overridden) {
            if ($overridden) {
                $candidate[$key] = $current[$key];
            }
        }
        $validated = self::validate([...$current, ...$candidate]);
        $values = array_filter($validated, static fn (string $key): bool => !$overrides[$key], ARRAY_FILTER_USE_KEY);
        if ($values !== []) {
            $this->config->setMany($values);
        }
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array{tracking_retry_enabled: bool, tracking_retry_batch_size: int}
     */
    public static function validate(array $values): array
    {
        $values = array_replace(self::defaults(), $values);
        $enabled = self::boolean($values[self::KEY_ENABLED]);
        if ($enabled === null) {
            throw new \InvalidArgumentException('tracking_retry_enabled must be true or false.');
        }

        $batchSize = $values[self::KEY_BATCH_SIZE];
        if (is_string($batchSize) && preg_match('/^[0-9]{1,4}$/D', $batchSize) === 1) {
            $batchSize = (int) $batchSize;
        }
        if (!is_int($batchSize) || $batchSize < 1 || $batchSize > 1000) {
            throw new \InvalidArgumentException('tracking_retry_batch_size must be a whole number from 1 to 1000.');
        }

        return [
            self::KEY_ENABLED => $enabled,
            self::KEY_BATCH_SIZE => $batchSize,
        ];
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
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }

        return null;
    }
}
