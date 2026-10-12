<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;

/**
 * Regenerates the approved bi_anonymous_* views from the configured disclosure
 * thresholds and page groups. Server databases replace each view in place,
 * which keeps the reporting account's grants; SQLite has no grants and drops
 * and recreates.
 *
 * The hourly and daily event views are always regenerated together with the
 * same threshold: the daily view's complementary suppression assumes it.
 */
final class AnonymousBiViewManager
{
    public const VIEW_NAMES = ['bi_anonymous_events_v1', 'bi_anonymous_goals_v1', 'bi_anonymous_geo_events_v1', 'bi_anonymous_daily_v1'];

    public function __construct(
        private readonly Connection $connection,
        private readonly ?BiPageGroups $pageGroups = null,
    ) {
    }

    public function regenerate(int $minimumCellCount, int $geoMinimumCellCount): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $statements = $this->statements($platform, $minimumCellCount, $geoMinimumCellCount);

        if ($platform instanceof AbstractMySQLPlatform) {
            foreach ($statements as $statement) {
                $this->connection->executeStatement($statement);
            }

            return;
        }

        $this->connection->beginTransaction();
        try {
            foreach ($statements as $statement) {
                $this->connection->executeStatement($statement);
            }
            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    /**
     * The statements regenerate() runs, in order, without changing the database.
     *
     * @return list<string>
     */
    public function previewSql(int $minimumCellCount, int $geoMinimumCellCount): array
    {
        return $this->statements($this->connection->getDatabasePlatform(), $minimumCellCount, $geoMinimumCellCount);
    }

    /** @return list<string> */
    private function statements(AbstractPlatform $platform, int $minimumCellCount, int $geoMinimumCellCount): array
    {
        $sql = new AnonymousBiViewSqlBuilder($platform);
        // Read and validate page groups before any statement runs, so invalid
        // YAML leaves every existing view in place.
        $pageGroups = $this->pageGroups?->rules() ?? [];
        $views = [
            ['bi_anonymous_events_v1', $sql->eventViewSql($minimumCellCount)],
            ['bi_anonymous_goals_v1', $sql->goalViewSql($minimumCellCount)],
            ['bi_anonymous_geo_events_v1', $sql->geoViewSql($geoMinimumCellCount)],
            ['bi_anonymous_daily_v1', $sql->dailyViewSql($minimumCellCount, $pageGroups)],
        ];

        $statements = [];
        foreach ($views as [$name, $createSql]) {
            if ($platform instanceof SQLitePlatform) {
                $statements[] = $sql->dropViewIfExistsSql($name);
                $statements[] = $createSql;

                continue;
            }

            $statements[] = self::replaceViewSql($platform, $name, $createSql);
        }

        return $statements;
    }

    /**
     * Turns the builder's CREATE VIEW into the engine's in-place replacement.
     * Threshold and page-group changes alter only literals and CASE branches,
     * so column names, order and types stay the same, which CREATE OR REPLACE
     * requires on PostgreSQL.
     */
    public static function replaceViewSql(AbstractPlatform $platform, string $name, string $createSql): string
    {
        $prefix = 'CREATE VIEW '.$name.' AS';
        if (!str_starts_with($createSql, $prefix)) {
            throw new \LogicException(sprintf('Unexpected SQL for %s.', $name));
        }

        $verb = $platform instanceof SQLServerPlatform ? 'CREATE OR ALTER VIEW ' : 'CREATE OR REPLACE VIEW ';

        return $verb.$name.' AS'.substr($createSql, strlen($prefix));
    }
}
