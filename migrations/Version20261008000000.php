<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\AnonymousBiViewSqlBuilder;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261008000000 extends AbstractMigration
{
    private const DEFAULT_MINIMUM_CELL_COUNT = 5;
    private const DEFAULT_GEO_MINIMUM_CELL_COUNT = 25;

    public function getDescription(): string
    {
        return 'Use configured BI thresholds in anonymous views and remove analytics_privacy_settings';
    }

    public function isTransactional(): bool
    {
        return !($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform);
    }

    public function up(Schema $schema): void
    {
        [$minimumCellCount, $geoMinimumCellCount] = $this->thresholdsFromDatabaseOrDefaults();
        $sql = new AnonymousBiViewSqlBuilder($this->connection->getDatabasePlatform());

        $this->addSql($sql->dropViewIfExistsSql('bi_anonymous_events_v1'));
        $this->addSql($sql->eventViewSql($minimumCellCount));
        $this->addSql($sql->dropViewIfExistsSql('bi_anonymous_goals_v1'));
        $this->addSql($sql->goalViewSql($minimumCellCount));
        $this->addSql($sql->dropViewIfExistsSql('bi_anonymous_geo_events_v1'));
        $this->addSql($sql->geoViewSql($geoMinimumCellCount));

        if ($schema->hasTable('analytics_privacy_settings')) {
            $schema->dropTable('analytics_privacy_settings');
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Restoring analytics_privacy_settings and historical threshold-backed views is not supported automatically.',
        );
    }

    /** @return array{int, int} */
    private function thresholdsFromDatabaseOrDefaults(): array
    {
        $schemaManager = $this->connection->createSchemaManager();
        if (!$schemaManager->tablesExist(['analytics_privacy_settings'])) {
            return [self::DEFAULT_MINIMUM_CELL_COUNT, self::DEFAULT_GEO_MINIMUM_CELL_COUNT];
        }

        $row = $this->connection->fetchAssociative(<<<'SQL'
SELECT anonymous_min_cell_count, anonymous_geo_min_cell_count
FROM analytics_privacy_settings
WHERE id = 1
SQL);
        if ($row === false) {
            return [self::DEFAULT_MINIMUM_CELL_COUNT, self::DEFAULT_GEO_MINIMUM_CELL_COUNT];
        }

        return [
            $this->validatedThreshold($row['anonymous_min_cell_count'] ?? null, 2, self::DEFAULT_MINIMUM_CELL_COUNT),
            $this->validatedThreshold($row['anonymous_geo_min_cell_count'] ?? null, 10, self::DEFAULT_GEO_MINIMUM_CELL_COUNT),
        ];
    }

    private function validatedThreshold(mixed $value, int $minimum, int $default): int
    {
        if (!is_int($value) && !(is_string($value) && preg_match('/^-?[0-9]+$/D', trim($value)) === 1)) {
            return $default;
        }

        $value = (int) $value;

        return $value >= $minimum && $value <= 1000 ? $value : $default;
    }
}
