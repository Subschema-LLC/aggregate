<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The page speed setting "Leave out unused tracker features"
 * (`tracker_omit_unused_features`, on by default) and the tracker build it
 * selects.
 *
 * public/aggregate.js marks the code of two features with build switches. The
 * Terser build (scripts/build-js.cjs) and BrowserScriptCompactor can leave that
 * code out. With the setting on, the configured, minified tracker is the
 * smallest build that behaves exactly like the full one for the settings it is
 * served with: without page depth while page depth is turned off, which no page
 * can turn back on, and the strict build while the strict profile is on, which
 * no page can relax. The readable /aggregate.js and static copies always keep
 * every feature.
 */
final class TrackerBuilds
{
    public const CONFIG_KEY = 'tracker_omit_unused_features';

    public const FULL = 'full';
    public const WITHOUT_PAGE_DEPTH = 'without-page-depth';
    public const STRICT = 'strict';

    /** Build => switch values; keep in step with TRACKER_BUILDS in scripts/build-js.cjs. */
    public const SWITCHES = [
        self::FULL => ['withPageDepth' => true, 'withStandardProfile' => true],
        self::WITHOUT_PAGE_DEPTH => ['withPageDepth' => false, 'withStandardProfile' => true],
        self::STRICT => ['withPageDepth' => false, 'withStandardProfile' => false],
    ];

    public function __construct(private readonly AggregateConfigLoader $config)
    {
    }

    public function enabled(): bool
    {
        return $this->config->getBoolWithEnvFallback(self::CONFIG_KEY, true);
    }

    public function hasEnvironmentOverride(): bool
    {
        return $this->config->hasEnvironmentOverride(self::CONFIG_KEY);
    }

    /**
     * Validates a submitted or configured value. Accepts a YAML boolean and the
     * same words as the environment variable (true/false, 1/0, yes/no, on/off).
     */
    public static function normalize(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) && in_array($value, [0, 1], true)) {
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

        throw new \InvalidArgumentException('Leave out unused tracker features must be on or off (true or false).');
    }

    /**
     * The build for the served settings. These tests mirror the tracker's own:
     * any profile other than "standard" is strict, and page depth can only be
     * enabled in the browser when the served settings enable it.
     *
     * @param array<string, mixed> $collection CollectionProfile::toBrowserConfig()
     * @param array<string, mixed> $customData CustomDataSettings::toBrowserConfig()
     */
    public static function select(bool $enabled, array $collection, array $customData): string
    {
        if (!$enabled) {
            return self::FULL;
        }
        if (($collection['profile'] ?? null) !== CollectionProfile::STANDARD) {
            return self::STRICT;
        }
        if (array_key_exists('pageSequenceEnabled', $customData) && $customData['pageSequenceEnabled'] !== true) {
            return self::WITHOUT_PAGE_DEPTH;
        }

        return self::FULL;
    }

    /** What a build leaves out, for the dashboard. */
    public static function describe(string $build): string
    {
        return match ($build) {
            self::WITHOUT_PAGE_DEPTH => 'Tracker without page depth',
            self::STRICT => 'Strict-profile tracker',
            default => 'Full tracker',
        };
    }
}
