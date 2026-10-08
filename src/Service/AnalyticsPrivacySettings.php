<?php

declare(strict_types=1);

namespace App\Service;

/** Config-backed disclosure thresholds used to regenerate anonymous BI views. */
class AnalyticsPrivacySettings
{
    public const ANONYMOUS_MINIMUM_KEY = 'anonymous_min_cell_count';
    public const GEO_MINIMUM_KEY = 'anonymous_geo_min_cell_count';
    public const DEFAULT_MINIMUM_CELL_COUNT = 5;
    public const MINIMUM_CELL_COUNT = 2;
    public const DEFAULT_GEO_MINIMUM_CELL_COUNT = 25;
    public const GEO_MINIMUM_CELL_COUNT = 10;
    public const MAXIMUM_CELL_COUNT = 1000;

    public function __construct(
        private readonly AggregateConfigLoader $config,
    ) {
    }

    /** @return array{anonymous: int, geo: int} */
    public function getMinimumCellCounts(): array
    {
        $anonymous = $this->config->getWithEnvFallback(
            self::ANONYMOUS_MINIMUM_KEY,
            self::DEFAULT_MINIMUM_CELL_COUNT,
        );
        $geo = $this->config->getWithEnvFallback(
            self::GEO_MINIMUM_KEY,
            self::DEFAULT_GEO_MINIMUM_CELL_COUNT,
        );

        return [
            'anonymous' => $this->configuredMinimum(
                $anonymous,
                self::ANONYMOUS_MINIMUM_KEY,
                self::MINIMUM_CELL_COUNT,
            ),
            'geo' => $this->configuredMinimum(
                $geo,
                self::GEO_MINIMUM_KEY,
                self::GEO_MINIMUM_CELL_COUNT,
            ),
        ];
    }

    public function saveMinimumCellCounts(
        int $minimum,
        int $geoMinimum,
    ): void {
        self::assertMinimumIsValid($minimum, self::ANONYMOUS_MINIMUM_KEY, self::MINIMUM_CELL_COUNT);
        self::assertMinimumIsValid($geoMinimum, self::GEO_MINIMUM_KEY, self::GEO_MINIMUM_CELL_COUNT);
        $this->config->setMany([
            self::ANONYMOUS_MINIMUM_KEY => $minimum,
            self::GEO_MINIMUM_KEY => $geoMinimum,
        ]);
    }

    public function hasAnonymousMinimumEnvironmentOverride(): bool
    {
        return $this->config->hasEnvironmentOverride(self::ANONYMOUS_MINIMUM_KEY);
    }

    public function hasGeoMinimumEnvironmentOverride(): bool
    {
        return $this->config->hasEnvironmentOverride(self::GEO_MINIMUM_KEY);
    }

    private static function assertMinimumIsValid(int $value, string $name, int $minimum): void
    {
        if ($value < $minimum || $value > self::MAXIMUM_CELL_COUNT) {
            throw new \InvalidArgumentException(sprintf(
                '%s must be between %d and %d.',
                $name,
                $minimum,
                self::MAXIMUM_CELL_COUNT,
            ));
        }
    }

    private function configuredMinimum(mixed $value, string $name, int $minimum): int
    {
        if (!is_int($value)
            && !(is_string($value) && preg_match('/^-?[0-9]+$/D', trim($value)) === 1)) {
            throw new \RuntimeException(sprintf(
                '%s is invalid.',
                $name,
            ));
        }

        $value = (int) $value;
        if ($value < $minimum || $value > self::MAXIMUM_CELL_COUNT) {
            throw new \RuntimeException(sprintf(
                '%s must be between %d and %d.',
                $name,
                $minimum,
                self::MAXIMUM_CELL_COUNT,
            ));
        }

        return $value;
    }
}
