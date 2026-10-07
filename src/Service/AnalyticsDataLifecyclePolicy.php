<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Validated archive, deletion and record purge settings shared by the Data
 * lifecycle page, YAML, environment variables and app:analytics:maintain.
 * The two record settings purge the audit trail and processing tasks after
 * that many days; 0 keeps them.
 */
final class AnalyticsDataLifecyclePolicy
{
    public const KEY_ARCHIVING_ENABLED = 'analytics_archiving_enabled';
    public const KEY_ARCHIVE_AFTER_DAYS = 'analytics_archive_after_days';
    public const KEY_RETENTION_ENABLED = 'analytics_retention_enabled';
    public const KEY_ANONYMOUS_RETENTION_DAYS = 'analytics_anonymous_retention_days';
    public const KEY_ENHANCED_RETENTION_DAYS = 'analytics_enhanced_retention_days';
    public const KEY_ARCHIVE_RETENTION_DAYS = 'analytics_archive_retention_days';
    public const KEY_MAINTENANCE_BATCH_SIZE = 'analytics_maintenance_batch_size';
    public const KEY_AUDIT_TRAIL_RETENTION_DAYS = 'audit_trail_retention_days';
    public const KEY_PROCESSING_TASKS_RETENTION_DAYS = 'processing_tasks_retention_days';

    public const DEFAULT_ARCHIVING_ENABLED = false;
    public const DEFAULT_ARCHIVE_AFTER_DAYS = 90;
    public const DEFAULT_RETENTION_ENABLED = false;
    public const DEFAULT_ANONYMOUS_RETENTION_DAYS = 365;
    public const DEFAULT_ENHANCED_RETENTION_DAYS = 90;
    public const DEFAULT_ARCHIVE_RETENTION_DAYS = 730;
    public const DEFAULT_MAINTENANCE_BATCH_SIZE = 1000;
    public const DEFAULT_AUDIT_TRAIL_RETENTION_DAYS = 365;
    public const DEFAULT_PROCESSING_TASKS_RETENTION_DAYS = 90;

    public const MIN_DAYS = 1;
    /** Record retention may be 0: keep everything. */
    public const MIN_RECORD_DAYS = 0;
    public const MAX_DAYS = 36_500;
    public const MIN_BATCH_SIZE = 100;
    public const MAX_BATCH_SIZE = 10_000;

    /** @var array<string, bool|int> */
    private const DEFAULTS = [
        self::KEY_ARCHIVING_ENABLED => self::DEFAULT_ARCHIVING_ENABLED,
        self::KEY_ARCHIVE_AFTER_DAYS => self::DEFAULT_ARCHIVE_AFTER_DAYS,
        self::KEY_RETENTION_ENABLED => self::DEFAULT_RETENTION_ENABLED,
        self::KEY_ANONYMOUS_RETENTION_DAYS => self::DEFAULT_ANONYMOUS_RETENTION_DAYS,
        self::KEY_ENHANCED_RETENTION_DAYS => self::DEFAULT_ENHANCED_RETENTION_DAYS,
        self::KEY_ARCHIVE_RETENTION_DAYS => self::DEFAULT_ARCHIVE_RETENTION_DAYS,
        self::KEY_MAINTENANCE_BATCH_SIZE => self::DEFAULT_MAINTENANCE_BATCH_SIZE,
        self::KEY_AUDIT_TRAIL_RETENTION_DAYS => self::DEFAULT_AUDIT_TRAIL_RETENTION_DAYS,
        self::KEY_PROCESSING_TASKS_RETENTION_DAYS => self::DEFAULT_PROCESSING_TASKS_RETENTION_DAYS,
    ];

    /** @var array<string, string> */
    private const NORMALIZED_KEYS = [
        self::KEY_ARCHIVING_ENABLED => 'archiving_enabled',
        self::KEY_ARCHIVE_AFTER_DAYS => 'archive_after_days',
        self::KEY_RETENTION_ENABLED => 'retention_enabled',
        self::KEY_ANONYMOUS_RETENTION_DAYS => 'anonymous_retention_days',
        self::KEY_ENHANCED_RETENTION_DAYS => 'enhanced_retention_days',
        self::KEY_ARCHIVE_RETENTION_DAYS => 'archive_retention_days',
        self::KEY_MAINTENANCE_BATCH_SIZE => 'batch_size',
        self::KEY_AUDIT_TRAIL_RETENTION_DAYS => 'audit_trail_retention_days',
        self::KEY_PROCESSING_TASKS_RETENTION_DAYS => 'processing_tasks_retention_days',
    ];

    public function __construct(private readonly AggregateConfigLoader $config)
    {
    }

    public function isArchivingEnabled(): bool
    {
        return $this->toArray()[self::KEY_ARCHIVING_ENABLED];
    }

    public function getArchiveAfterDays(): int
    {
        return $this->toArray()[self::KEY_ARCHIVE_AFTER_DAYS];
    }

    public function isRetentionEnabled(): bool
    {
        return $this->toArray()[self::KEY_RETENTION_ENABLED];
    }

    public function getAnonymousRetentionDays(): int
    {
        return $this->toArray()[self::KEY_ANONYMOUS_RETENTION_DAYS];
    }

    public function getEnhancedRetentionDays(): int
    {
        return $this->toArray()[self::KEY_ENHANCED_RETENTION_DAYS];
    }

    public function getArchiveRetentionDays(): int
    {
        return $this->toArray()[self::KEY_ARCHIVE_RETENTION_DAYS];
    }

    public function getMaintenanceBatchSize(): int
    {
        return $this->toArray()[self::KEY_MAINTENANCE_BATCH_SIZE];
    }

    public function getAuditTrailRetentionDays(): int
    {
        return $this->toArray()[self::KEY_AUDIT_TRAIL_RETENTION_DAYS];
    }

    public function getProcessingTasksRetentionDays(): int
    {
        return $this->toArray()[self::KEY_PROCESSING_TASKS_RETENTION_DAYS];
    }

    /**
     * Return effective, typed values using YAML defaults and uppercase
     * environment-variable overrides.
     *
     * @return array<string, bool|int>
     */
    public function toArray(): array
    {
        if ($this->config->hasLoadError()) {
            throw new \RuntimeException('Application configuration is invalid.');
        }

        $configured = $this->config->all();
        $values = [];
        foreach (self::DEFAULTS as $key => $default) {
            $values[$key] = $this->config->hasEnvironmentOverride($key)
                ? $this->config->getWithEnvFallback($key, $default)
                : (array_key_exists($key, $configured) ? $configured[$key] : $default);
        }

        return self::validate($values);
    }

    /**
     * Normalized payload used by the maintenance runner.
     *
     * @return array{
     *     archiving_enabled: bool,
     *     archive_after_days: int,
     *     retention_enabled: bool,
     *     anonymous_retention_days: int,
     *     enhanced_retention_days: int,
     *     archive_retention_days: int,
     *     batch_size: int,
     *     audit_trail_retention_days: int,
     *     processing_tasks_retention_days: int,
     *     environment_overrides: array<string, bool>
     * }
     */
    public function getPolicy(): array
    {
        $values = $this->toArray();
        $overrides = $this->getEnvironmentOverrides();
        $policy = [];
        $normalizedOverrides = [];

        foreach (self::NORMALIZED_KEYS as $configKey => $normalizedKey) {
            $policy[$normalizedKey] = $values[$configKey];
            $normalizedOverrides[$normalizedKey] = $overrides[$configKey];
        }
        $policy['environment_overrides'] = $normalizedOverrides;

        return $policy;
    }

    /** @return array<string, bool> keyed by the full YAML setting names */
    public function getEnvironmentOverrides(): array
    {
        $overrides = [];
        foreach (array_keys(self::DEFAULTS) as $key) {
            $overrides[$key] = $this->config->hasEnvironmentOverride($key);
        }

        return $overrides;
    }

    public function isEnvironmentOverridden(string $key): bool
    {
        if (isset(self::NORMALIZED_KEYS[$key])) {
            return $this->config->hasEnvironmentOverride($key);
        }

        $configKey = array_search($key, self::NORMALIZED_KEYS, true);
        if (!is_string($configKey)) {
            throw new \InvalidArgumentException(sprintf('Unknown analytics lifecycle setting "%s".', $key));
        }

        return $this->config->hasEnvironmentOverride($configKey);
    }

    public function assertValid(): void
    {
        $this->toArray();
    }

    /**
     * Strictly normalize and validate a complete or partial policy. Missing
     * values receive the documented defaults; unknown keys are rejected.
     *
     * @param array<string, mixed> $values
     * @return array<string, bool|int>
     */
    public static function validate(array $values): array
    {
        $unknownKeys = array_diff_key($values, self::DEFAULTS);
        if ($unknownKeys !== []) {
            $key = (string) array_key_first($unknownKeys);
            throw new \InvalidArgumentException(sprintf('Unknown analytics lifecycle setting "%s".', $key));
        }

        $values = array_replace(self::DEFAULTS, $values);
        $validated = [
            self::KEY_ARCHIVING_ENABLED => self::parseBoolean($values[self::KEY_ARCHIVING_ENABLED], self::KEY_ARCHIVING_ENABLED),
            self::KEY_ARCHIVE_AFTER_DAYS => self::parseInteger(
                $values[self::KEY_ARCHIVE_AFTER_DAYS],
                self::KEY_ARCHIVE_AFTER_DAYS,
                self::MIN_DAYS,
                self::MAX_DAYS,
            ),
            self::KEY_RETENTION_ENABLED => self::parseBoolean($values[self::KEY_RETENTION_ENABLED], self::KEY_RETENTION_ENABLED),
            self::KEY_ANONYMOUS_RETENTION_DAYS => self::parseInteger(
                $values[self::KEY_ANONYMOUS_RETENTION_DAYS],
                self::KEY_ANONYMOUS_RETENTION_DAYS,
                self::MIN_DAYS,
                self::MAX_DAYS,
            ),
            self::KEY_ENHANCED_RETENTION_DAYS => self::parseInteger(
                $values[self::KEY_ENHANCED_RETENTION_DAYS],
                self::KEY_ENHANCED_RETENTION_DAYS,
                self::MIN_DAYS,
                self::MAX_DAYS,
            ),
            self::KEY_ARCHIVE_RETENTION_DAYS => self::parseInteger(
                $values[self::KEY_ARCHIVE_RETENTION_DAYS],
                self::KEY_ARCHIVE_RETENTION_DAYS,
                self::MIN_DAYS,
                self::MAX_DAYS,
            ),
            self::KEY_MAINTENANCE_BATCH_SIZE => self::parseInteger(
                $values[self::KEY_MAINTENANCE_BATCH_SIZE],
                self::KEY_MAINTENANCE_BATCH_SIZE,
                self::MIN_BATCH_SIZE,
                self::MAX_BATCH_SIZE,
            ),
            self::KEY_AUDIT_TRAIL_RETENTION_DAYS => self::parseInteger(
                $values[self::KEY_AUDIT_TRAIL_RETENTION_DAYS],
                self::KEY_AUDIT_TRAIL_RETENTION_DAYS,
                self::MIN_RECORD_DAYS,
                self::MAX_DAYS,
            ),
            self::KEY_PROCESSING_TASKS_RETENTION_DAYS => self::parseInteger(
                $values[self::KEY_PROCESSING_TASKS_RETENTION_DAYS],
                self::KEY_PROCESSING_TASKS_RETENTION_DAYS,
                self::MIN_RECORD_DAYS,
                self::MAX_DAYS,
            ),
        ];

        if ($validated[self::KEY_RETENTION_ENABLED]) {
            $archiveAfter = $validated[self::KEY_ARCHIVE_AFTER_DAYS];
            $anonymousRetention = $validated[self::KEY_ANONYMOUS_RETENTION_DAYS];
            $enhancedRetention = $validated[self::KEY_ENHANCED_RETENTION_DAYS];
            $archiveRetention = $validated[self::KEY_ARCHIVE_RETENTION_DAYS];

            if ($validated[self::KEY_ARCHIVING_ENABLED]
                && ($anonymousRetention < $archiveAfter || $enhancedRetention < $archiveAfter)) {
                throw new \InvalidArgumentException(
                    'Raw anonymous and enhanced retention periods must be at least the archive-after period when archiving and retention are enabled.',
                );
            }

            if ($archiveRetention < max($anonymousRetention, $enhancedRetention)) {
                throw new \InvalidArgumentException(
                    'Archive retention must be at least the longest raw-data retention period when retention is enabled.',
                );
            }
        }

        return $validated;
    }

    private static function parseBoolean(mixed $value, string $key): bool
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
            if (in_array($value, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }

        throw new \InvalidArgumentException(sprintf(
            '%s must be a boolean (true/false, yes/no, on/off, or 1/0).',
            $key,
        ));
    }

    private static function parseInteger(mixed $value, string $key, int $minimum, int $maximum): int
    {
        if (is_int($value)) {
            $integer = $value;
        } elseif (is_string($value) && preg_match('/^[0-9]+$/D', trim($value)) === 1) {
            $integer = (int) trim($value);
        } else {
            throw new \InvalidArgumentException(sprintf('%s must be a whole number.', $key));
        }

        if ($integer < $minimum || $integer > $maximum) {
            throw new \InvalidArgumentException(sprintf(
                '%s must be between %d and %d.',
                $key,
                $minimum,
                $maximum,
            ));
        }

        return $integer;
    }
}
