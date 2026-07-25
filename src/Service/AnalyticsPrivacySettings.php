<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;

/** Database-backed disclosure thresholds used directly by the anonymous BI views. */
final class AnalyticsPrivacySettings
{
    public const DEFAULT_MINIMUM_CELL_COUNT = 5;
    public const MINIMUM_CELL_COUNT = 2;
    public const DEFAULT_GEO_MINIMUM_CELL_COUNT = 25;
    public const GEO_MINIMUM_CELL_COUNT = 10;
    public const MAXIMUM_CELL_COUNT = 1000;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /** @return array{anonymous: int, geo: int} */
    public function getMinimumCellCounts(): array
    {
        $settings = $this->connection->fetchAssociative(<<<'SQL'
SELECT anonymous_min_cell_count, anonymous_geo_min_cell_count
FROM analytics_privacy_settings
WHERE id = 1
SQL);

        if ($settings === false) {
            throw $this->missingSettingsException();
        }

        return [
            'anonymous' => $this->storedMinimum(
                $settings['anonymous_min_cell_count'] ?? null,
                'anonymous_min_cell_count',
                self::MINIMUM_CELL_COUNT,
            ),
            'geo' => $this->storedMinimum(
                $settings['anonymous_geo_min_cell_count'] ?? null,
                'anonymous_geo_min_cell_count',
                self::GEO_MINIMUM_CELL_COUNT,
            ),
        ];
    }

    public function saveMinimumCellCounts(
        int $minimum,
        int $geoMinimum,
        ?\DateTimeImmutable $now = null,
    ): void {
        self::assertMinimumIsValid($minimum, 'anonymous_min_cell_count', self::MINIMUM_CELL_COUNT);
        self::assertMinimumIsValid($geoMinimum, 'anonymous_geo_min_cell_count', self::GEO_MINIMUM_CELL_COUNT);

        $now = ($now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone('UTC'));

        $parameters = [
            'minimum' => $minimum,
            'geo_minimum' => $geoMinimum,
            'updated_at' => $now,
        ];
        $types = [
            'minimum' => Types::INTEGER,
            'geo_minimum' => Types::INTEGER,
            'updated_at' => Types::DATETIME_IMMUTABLE,
        ];

        $affectedRows = $this->update($parameters, $types);

        // Some drivers report zero for an unchanged UPDATE, so distinguish that
        // from a missing singleton row before attempting an explicit repair.
        if ((int) $affectedRows !== 0
            || $this->connection->fetchOne('SELECT id FROM analytics_privacy_settings WHERE id = 1') !== false) {
            return;
        }

        try {
            $this->connection->executeStatement(
                <<<'SQL'
INSERT INTO analytics_privacy_settings (
    id,
    anonymous_min_cell_count,
    anonymous_geo_min_cell_count,
    updated_at
)
VALUES (1, :minimum, :geo_minimum, :updated_at)
SQL,
                $parameters,
                $types,
            );
        } catch (UniqueConstraintViolationException) {
            // A concurrent repair inserted singleton row 1 after our existence
            // check. Apply the administrator's values to that row.
            $this->update($parameters, $types);
        }
    }

    /**
     * @param array{minimum: int, geo_minimum: int, updated_at: \DateTimeImmutable} $parameters
     * @param array{minimum: string, geo_minimum: string, updated_at: string} $types
     */
    private function update(array $parameters, array $types): int|string
    {
        return $this->connection->executeStatement(
            <<<'SQL'
UPDATE analytics_privacy_settings
SET anonymous_min_cell_count = :minimum,
    anonymous_geo_min_cell_count = :geo_minimum,
    updated_at = :updated_at
WHERE id = 1
SQL,
            $parameters,
            $types,
        );
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

    private function storedMinimum(mixed $value, string $name, int $minimum): int
    {
        if (!is_int($value)
            && !(is_string($value) && preg_match('/^-?[0-9]+$/D', trim($value)) === 1)) {
            throw new \RuntimeException(sprintf(
                'analytics_privacy_settings.%s is invalid.',
                $name,
            ));
        }

        $value = (int) $value;
        if ($value < $minimum || $value > self::MAXIMUM_CELL_COUNT) {
            throw new \RuntimeException(sprintf(
                'analytics_privacy_settings.%s must be between %d and %d.',
                $name,
                $minimum,
                self::MAXIMUM_CELL_COUNT,
            ));
        }

        return $value;
    }

    private function missingSettingsException(): \RuntimeException
    {
        return new \RuntimeException(
            'The analytics privacy settings row is missing.',
        );
    }
}
