<?php

declare(strict_types=1);

namespace App\Service\ReportingDatabase;

use App\Service\AggregateConfigLoader;
use App\Service\BigQuery\BigQueryViewCatalog;

/**
 * Reporting database sync settings: one source for the admin page, YAML,
 * environment variables and the sync command. Uppercase environment variables
 * take precedence and lock the matching admin fields; lists in the environment
 * are comma-separated.
 *
 * The connection itself is infrastructure, like DATABASE_URL: it comes from
 * REPORTING_DATABASE_URL in .env.local or the server environment, because it
 * holds a password (see ReportingDatabaseConnection).
 */
final class ReportingDatabaseSettings
{
    public const KEY_ENABLED = 'reporting_database_enabled';
    public const KEY_VIEWS = 'reporting_database_views';
    public const KEY_PRIVATE_VIEWS = 'reporting_database_private_views';
    public const KEY_INTERVAL = 'reporting_database_interval_minutes';

    public const INTERVALS = [15, 30, 60, 120, 180, 360, 720, 1440];

    public function __construct(private readonly AggregateConfigLoader $config)
    {
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            self::KEY_ENABLED => false,
            self::KEY_VIEWS => array_keys(BigQueryViewCatalog::APPROVED),
            self::KEY_PRIVATE_VIEWS => [],
            self::KEY_INTERVAL => 60,
        ];
    }

    /**
     * The effective, validated settings.
     *
     * @return array{reporting_database_enabled: bool, reporting_database_views: list<string>, reporting_database_private_views: list<string>, reporting_database_interval_minutes: int}
     */
    public function toArray(): array
    {
        $this->config->assertHealthy();
        $values = [];
        foreach (self::defaults() as $key => $default) {
            $values[$key] = $this->config->getWithEnvFallback($key, $default, $key === self::KEY_PRIVATE_VIEWS);
        }

        return self::validate($values);
    }

    /** @return array<string, bool> */
    public function getEnvironmentOverrides(): array
    {
        $overrides = [];
        foreach (array_keys(self::defaults()) as $key) {
            $overrides[$key] = $this->config->hasEnvironmentOverride($key, $key === self::KEY_PRIVATE_VIEWS);
        }

        return $overrides;
    }

    /**
     * Validates a complete candidate and writes the values the environment does
     * not control. Nothing is written when any value is invalid.
     *
     * @param array<string, mixed> $candidate
     */
    public function save(array $candidate): void
    {
        if (array_diff_key($candidate, self::defaults()) !== []) {
            throw new \InvalidArgumentException('The form contained an unexpected reporting database setting. Nothing was saved.');
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
     * @return array{reporting_database_enabled: bool, reporting_database_views: list<string>, reporting_database_private_views: list<string>, reporting_database_interval_minutes: int}
     */
    public static function validate(array $values): array
    {
        $values = array_replace(self::defaults(), $values);

        $enabled = self::boolean($values[self::KEY_ENABLED]);
        if ($enabled === null) {
            throw new \InvalidArgumentException('reporting_database_enabled must be true or false.');
        }

        $views = self::viewList($values[self::KEY_VIEWS], self::KEY_VIEWS);
        foreach ($views as $view) {
            if (BigQueryViewCatalog::isPrivate($view)) {
                throw new \InvalidArgumentException($view.' holds row-level or unsuppressed data. List it under reporting_database_private_views to confirm that it may be copied to the reporting database.');
            }
            if (!BigQueryViewCatalog::isApproved($view)) {
                throw new \InvalidArgumentException($view.' is not a reporting view that can be copied to the reporting database.');
            }
        }
        $privateViews = self::viewList($values[self::KEY_PRIVATE_VIEWS], self::KEY_PRIVATE_VIEWS);
        foreach ($privateViews as $view) {
            if (!BigQueryViewCatalog::isPrivate($view)) {
                throw new \InvalidArgumentException($view.' is not a private reporting view. List approved views under reporting_database_views.');
            }
        }
        if ($enabled && $views === [] && $privateViews === []) {
            throw new \InvalidArgumentException('Choose at least one view to copy, or turn the reporting database sync off.');
        }

        $interval = $values[self::KEY_INTERVAL];
        if (is_string($interval) && preg_match('/^[0-9]{1,5}$/D', $interval) === 1) {
            $interval = (int) $interval;
        }
        if (!is_int($interval) || !in_array($interval, self::INTERVALS, true)) {
            throw new \InvalidArgumentException('The sync interval must be one of '.implode(', ', self::INTERVALS).' minutes.');
        }

        return [
            self::KEY_ENABLED => $enabled,
            self::KEY_VIEWS => self::inCatalogOrder($views, BigQueryViewCatalog::APPROVED),
            self::KEY_PRIVATE_VIEWS => self::inCatalogOrder($privateViews, BigQueryViewCatalog::PRIVATE),
            self::KEY_INTERVAL => $interval,
        ];
    }

    /**
     * @param array{reporting_database_views: list<string>, reporting_database_private_views: list<string>} $settings
     * @return list<string>
     */
    public static function selectedViews(array $settings): array
    {
        return [...$settings[self::KEY_VIEWS], ...$settings[self::KEY_PRIVATE_VIEWS]];
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

    /** @return list<string> */
    private static function viewList(mixed $value, string $key): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        if (!is_array($value) || ($value !== [] && !array_is_list($value))) {
            throw new \InvalidArgumentException($key.' must be a list of view names.');
        }
        $views = [];
        foreach ($value as $view) {
            if (!is_string($view)) {
                throw new \InvalidArgumentException($key.' must be a list of view names.');
            }
            $view = trim($view);
            if ($view !== '') {
                $views[$view] = true;
            }
        }

        return array_keys($views);
    }

    /**
     * @param list<string> $views
     * @param array<string, string> $catalog
     * @return list<string>
     */
    private static function inCatalogOrder(array $views, array $catalog): array
    {
        return array_values(array_filter(array_keys($catalog), static fn (string $view): bool => in_array($view, $views, true)));
    }
}
