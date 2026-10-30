<?php

declare(strict_types=1);

namespace App\Service\ViewSync;

use App\Service\AggregateConfigLoader;

/**
 * Settings of a view sync, BigQuery or the reporting database: one source for
 * the admin page, YAML, environment variables and the commands. Every view
 * sync has an on/off switch, the approved and private views to copy and an
 * interval; a destination adds settings of its own. Uppercase environment
 * variables take precedence and lock the matching admin fields; lists in the
 * environment are comma-separated.
 */
abstract class ViewSyncSettings
{
    public const INTERVALS = [15, 30, 60, 120, 180, 360, 720, 1440];

    public function __construct(protected readonly AggregateConfigLoader $config)
    {
    }

    /** @return array<string, mixed> */
    abstract public static function defaults(): array;

    /**
     * The validated settings; throws for the first invalid value.
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    abstract public static function validate(array $values): array;

    /**
     * The keys of the settings every view sync has.
     *
     * @return array{enabled: string, views: string, private_views: string, interval: string}
     */
    abstract public static function keys(): array;

    /**
     * Keys an environment variable can set to an empty value, overriding YAML.
     *
     * @return list<string>
     */
    abstract protected static function emptyEnvironmentKeys(): array;

    /** What sets this sync apart, read by its runner, job, launcher and admin page. */
    abstract public static function definition(): ViewSyncDefinition;

    /**
     * The effective, validated settings.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $this->config->assertHealthy();
        $values = [];
        foreach (static::defaults() as $key => $default) {
            $values[$key] = $this->config->getWithEnvFallback($key, $default, in_array($key, static::emptyEnvironmentKeys(), true));
        }

        return static::validate($values);
    }

    /** @return array<string, bool> */
    public function getEnvironmentOverrides(): array
    {
        $overrides = [];
        foreach (array_keys(static::defaults()) as $key) {
            $overrides[$key] = $this->config->hasEnvironmentOverride($key, in_array($key, static::emptyEnvironmentKeys(), true));
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
        if (array_diff_key($candidate, static::defaults()) !== []) {
            throw new \InvalidArgumentException('The form contained an unexpected '.static::definition()->settingsName.' setting. Nothing was saved.');
        }
        $current = $this->toArray();
        $overrides = $this->getEnvironmentOverrides();
        foreach ($overrides as $key => $overridden) {
            if ($overridden) {
                $candidate[$key] = $current[$key];
            }
        }
        $validated = static::validate([...$current, ...$candidate]);
        $values = array_filter($validated, static fn (string $key): bool => !$overrides[$key], ARRAY_FILTER_USE_KEY);
        if ($values !== []) {
            $this->config->setMany($values);
        }
    }

    /** @param array<string, mixed> $settings validated settings */
    public static function isEnabled(array $settings): bool
    {
        return $settings[static::keys()['enabled']] === true;
    }

    /** @param array<string, mixed> $settings validated settings */
    public static function intervalMinutes(array $settings): int
    {
        return (int) $settings[static::keys()['interval']];
    }

    /**
     * The approved views, then the private ones.
     *
     * @param array<string, mixed> $settings validated settings
     *
     * @return list<string>
     */
    public static function selectedViews(array $settings): array
    {
        $keys = static::keys();

        return [...$settings[$keys['views']], ...$settings[$keys['private_views']]];
    }

    protected static function enabled(mixed $value, string $key): bool
    {
        return self::boolean($value) ?? throw new \InvalidArgumentException($key.' must be true or false.');
    }

    /**
     * The approved and private views to copy, each in catalog order. A private
     * view is accepted only from its own list, so it is never copied by accident.
     *
     * @param array<string, mixed> $values
     *
     * @return array{list<string>, list<string>}
     */
    protected static function views(array $values, bool $enabled): array
    {
        $keys = static::keys();
        $sync = static::definition();
        $views = self::viewList($values[$keys['views']], $keys['views']);
        foreach ($views as $view) {
            if (ViewCatalog::isPrivate($view)) {
                throw new \InvalidArgumentException($view.' holds row-level or unsuppressed data. List it under '.$keys['private_views'].' to confirm that it may be copied to '.$sync->destination.'.');
            }
            if (!ViewCatalog::isApproved($view)) {
                throw new \InvalidArgumentException($view.' is not a reporting view that can be '.$sync->copiedTo.'.');
            }
        }
        $privateViews = self::viewList($values[$keys['private_views']], $keys['private_views']);
        foreach ($privateViews as $view) {
            if (!ViewCatalog::isPrivate($view)) {
                throw new \InvalidArgumentException($view.' is not a private reporting view. List approved views under '.$keys['views'].'.');
            }
        }
        if ($enabled && $views === [] && $privateViews === []) {
            throw new \InvalidArgumentException($sync->noneChosen);
        }

        return [self::inCatalogOrder($views, ViewCatalog::APPROVED), self::inCatalogOrder($privateViews, ViewCatalog::PRIVATE)];
    }

    protected static function interval(mixed $interval): int
    {
        if (is_string($interval) && preg_match('/^[0-9]{1,5}$/D', $interval) === 1) {
            $interval = (int) $interval;
        }
        if (!is_int($interval) || !in_array($interval, self::INTERVALS, true)) {
            throw new \InvalidArgumentException('The sync interval must be one of '.implode(', ', self::INTERVALS).' minutes.');
        }

        return $interval;
    }

    protected static function boolean(mixed $value): ?bool
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
    protected static function viewList(mixed $value, string $key): array
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
     * @param list<string>          $views
     * @param array<string, mixed>  $catalog
     *
     * @return list<string>
     */
    protected static function inCatalogOrder(array $views, array $catalog): array
    {
        return array_values(array_filter(array_keys($catalog), static fn (string $view): bool => in_array($view, $views, true)));
    }
}
