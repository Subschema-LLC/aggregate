<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;

/**
 * Regenerates the approved bi_anonymous_* views from the configured disclosure
 * thresholds and page groups. Server databases replace each view in place,
 * which keeps the reporting account's grants; SQLite has no grants and drops
 * and recreates.
 *
 * The hourly, daily, roll-up and coverage views are always regenerated
 * together with one threshold, one set of page groups and one reporting
 * timezone: the daily views' complementary suppression, and the roll-up
 * matching the daily view row for row, assume it.
 */
final class AnonymousBiViewManager
{
    /** In creation order: the coverage view reads the event views, so it comes last. */
    public const VIEW_NAMES = ['bi_anonymous_events_v1', 'bi_anonymous_goals_v1', 'bi_anonymous_geo_events_v1', 'bi_anonymous_daily_v1', 'bi_anonymous_daily_rollup_v1', 'bi_anonymous_coverage_v1'];

    public function __construct(
        private readonly Connection $connection,
        private readonly ?BiPageGroups $pageGroups = null,
        private readonly ?ReportingTimezone $reportingTimezone = null,
    ) {
    }

    public function regenerate(int $minimumCellCount, int $geoMinimumCellCount): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->assertConfiguredTimezoneCanBeCompiled();
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
        $this->assertConfiguredTimezoneCanBeCompiled();

        return $this->statements($this->connection->getDatabasePlatform(), $minimumCellCount, $geoMinimumCellCount);
    }

    /**
     * Refuse a timezone this engine cannot compile, before any view is replaced.
     * MySQL named zones need loaded time zone tables; SQL Server needs a Windows name.
     */
    public function assertReportingTimezoneCanBeCompiled(string $timezone): void
    {
        $this->assertPlatformCanCompile(ReportingTimezone::validate($timezone));
    }

    /** @return list<string> */
    private function statements(AbstractPlatform $platform, int $minimumCellCount, int $geoMinimumCellCount): array
    {
        $sql = new AnonymousBiViewSqlBuilder($platform, $this->reportingTimezoneName());
        // Read and validate page groups before any statement runs, so invalid
        // YAML leaves every existing view in place.
        $pageGroups = $this->pageGroups?->rules() ?? [];
        $views = [
            ['bi_anonymous_events_v1', $sql->eventViewSql($minimumCellCount)],
            ['bi_anonymous_goals_v1', $sql->goalViewSql($minimumCellCount)],
            ['bi_anonymous_geo_events_v1', $sql->geoViewSql($geoMinimumCellCount)],
            ['bi_anonymous_daily_v1', $sql->dailyViewSql($minimumCellCount, $pageGroups)],
            ['bi_anonymous_daily_rollup_v1', $sql->rollupViewSql($minimumCellCount, $pageGroups)],
            ['bi_anonymous_coverage_v1', $sql->coverageViewSql($minimumCellCount)],
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
     * The threshold compiled into bi_anonymous_events_v1, read from the view
     * definition the engine reports, or null when there is no view or it
     * cannot be read. Migrations that add a view depending on the hourly one
     * use it so the new view matches the threshold already published.
     */
    public function compiledEventThreshold(): ?int
    {
        $platform = $this->connection->getDatabasePlatform();
        $query = match (true) {
            $platform instanceof SQLitePlatform => "SELECT sql FROM sqlite_master WHERE type = 'view' AND name = 'bi_anonymous_events_v1'",
            $platform instanceof PostgreSQLPlatform => "SELECT pg_get_viewdef(c.oid) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE c.relname = 'bi_anonymous_events_v1' AND c.relkind = 'v' AND n.nspname = current_schema()",
            $platform instanceof SQLServerPlatform => "SELECT OBJECT_DEFINITION(OBJECT_ID('bi_anonymous_events_v1', 'V'))",
            $platform instanceof AbstractMySQLPlatform => "SELECT VIEW_DEFINITION FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bi_anonymous_events_v1'",
            default => null,
        };
        if ($query === null) {
            return null;
        }

        try {
            $definition = $this->connection->fetchOne($query);
        } catch (\Throwable) {
            return null;
        }

        return self::thresholdFromDefinition(is_string($definition) ? $definition : null);
    }

    /**
     * The threshold in an hourly view definition (MySQL and MariaDB rewrite it
     * with backticks and parentheses). Null unless exactly one valid threshold
     * comparison is found.
     */
    public static function thresholdFromDefinition(?string $definition): ?int
    {
        if ($definition === null
            || preg_match_all('/event_count`?\s*\)?\s*>=\s*\(?\s*([0-9]{1,4})\b/i', $definition, $matches) !== 1) {
            return null;
        }
        $value = (int) $matches[1][0];

        return $value >= AnalyticsPrivacySettings::MINIMUM_CELL_COUNT && $value <= AnalyticsPrivacySettings::MAXIMUM_CELL_COUNT ? $value : null;
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

    private function reportingTimezoneName(): string
    {
        return $this->reportingTimezone?->name() ?? ReportingTimezone::DEFAULT;
    }

    private function assertConfiguredTimezoneCanBeCompiled(): void
    {
        $this->assertPlatformCanCompile($this->reportingTimezoneName());
    }

    private function assertPlatformCanCompile(string $timezone): void
    {
        if (ReportingTimezone::isIdentity($timezone)) {
            return;
        }

        $platform = $this->connection->getDatabasePlatform();
        if ($platform instanceof SQLServerPlatform) {
            ReportingTimezone::windowsName($timezone);
        }
        if ($platform instanceof AbstractMySQLPlatform) {
            $this->assertMysqlNamedZone($timezone);
        }
    }

    private function assertMysqlNamedZone(string $timezone): void
    {
        foreach (['2024-01-15 12:00:00', '2024-07-15 12:00:00'] as $instant) {
            $converted = $this->connection->fetchOne('SELECT CONVERT_TZ(?, ?, ?)', [$instant, 'UTC', $timezone]);
            if ($converted === null || $converted === false || $converted === '') {
                throw new \RuntimeException(sprintf(
                    'MySQL cannot convert %s. Load the time zone tables (mysql_tzinfo_to_sql or mariadb-tzinfo-to-sql) and run app:analytics:bi-views:regenerate again. The existing views were left unchanged.',
                    $timezone,
                ));
            }
        }
    }
}
