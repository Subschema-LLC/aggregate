<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;

final class AnonymousBiViewManager
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function regenerate(int $minimumCellCount, int $geoMinimumCellCount): void
    {
        $this->dropViewIfExists('bi_anonymous_events_v1');
        $this->dropViewIfExists('bi_anonymous_goals_v1');
        $this->dropViewIfExists('bi_anonymous_geo_events_v1');

        $this->connection->executeStatement($this->eventViewSql($minimumCellCount));
        $this->connection->executeStatement($this->goalViewSql($minimumCellCount));
        $this->connection->executeStatement($this->geoViewSql($geoMinimumCellCount));
    }

    private function dropViewIfExists(string $viewName): void
    {
        if ($this->connection->getDatabasePlatform() instanceof SQLServerPlatform) {
            $this->connection->executeStatement(
                sprintf("IF OBJECT_ID('%1\$s', 'V') IS NOT NULL DROP VIEW %1\$s", $viewName),
            );

            return;
        }

        $this->connection->executeStatement(sprintf('DROP VIEW IF EXISTS %s', $viewName));
    }

    private function eventViewSql(int $minimumCellCount): string
    {
        return sprintf(<<<'SQL'
CREATE VIEW bi_anonymous_events_v1 AS
WITH combined_event_cells AS (
    SELECT
        archived_events.website_token,
        archived_events.event_hour,
        archived_events.event_name,
        archived_events.page_path,
        archived_events.referrer_channel,
        archived_events.device_class,
        archived_events.viewport_bucket,
        archived_events.event_count
    FROM analytics_archive_events archived_events
    WHERE archived_events.privacy_mode = 'anonymous'
      AND %1$s
    UNION ALL
    SELECT
        events.website_token,
        events.created_at AS event_hour,
        events.event_name,
        events.url AS page_path,
        COALESCE(events.referrer, 'unknown') AS referrer_channel,
        events.device_class,
        events.viewport_bucket,
        1 AS event_count
    FROM events
    WHERE events.archived_at IS NULL
      AND events.privacy_mode = 'anonymous'
      AND %2$s
),
event_counts AS (
    SELECT
        website_token,
        event_hour,
        event_name,
        page_path,
        referrer_channel,
        device_class,
        viewport_bucket,
        SUM(event_count) AS event_count
    FROM combined_event_cells
    GROUP BY
        website_token,
        event_hour,
        event_name,
        page_path,
        referrer_channel,
        device_class,
        viewport_bucket
)
SELECT
    event_counts.website_token,
    event_counts.event_hour,
    event_counts.event_name,
    event_counts.page_path,
    event_counts.referrer_channel,
    event_counts.device_class,
    event_counts.viewport_bucket,
    event_counts.event_count
FROM event_counts
WHERE event_counts.event_count >= %3$d
SQL, $this->completedHourPredicate('archived_events.event_hour'), $this->completedHourPredicate('events.created_at'), $minimumCellCount);
    }

    private function goalViewSql(int $minimumCellCount): string
    {
        return sprintf(<<<'SQL'
CREATE VIEW bi_anonymous_goals_v1 AS
WITH combined_goal_cells AS (
    SELECT
        archived_goals.website_token,
        archived_goals.event_day,
        archived_goals.goal_event,
        archived_goals.event_count
    FROM analytics_archive_goals archived_goals
    WHERE archived_goals.privacy_mode = 'anonymous'
      AND %1$s
    UNION ALL
    SELECT
        events.website_token,
        %2$s AS event_day,
        events.goal_event,
        1 AS event_count
    FROM events
    WHERE events.archived_at IS NULL
      AND events.privacy_mode = 'anonymous'
      AND events.goal_event IS NOT NULL
      AND %3$s
),
goal_counts AS (
    SELECT
        website_token,
        event_day,
        goal_event,
        SUM(event_count) AS event_count
    FROM combined_goal_cells
    GROUP BY
        website_token,
        event_day,
        goal_event
)
SELECT
    goal_counts.website_token,
    goal_counts.event_day,
    goal_counts.goal_event,
    goal_counts.event_count
FROM goal_counts
WHERE goal_counts.event_count >= %4$d
SQL, $this->completedDayPredicate('archived_goals.event_day'), $this->eventDayExpression('events.created_at'), $this->completedDayPredicate('events.created_at'), $minimumCellCount);
    }

    private function geoViewSql(int $geoMinimumCellCount): string
    {
        return sprintf(<<<'SQL'
CREATE VIEW bi_anonymous_geo_events_v1 AS
WITH combined_geo_cells AS (
    SELECT
        archived_geo.website_token,
        archived_geo.event_day,
        archived_geo.event_name,
        archived_geo.geo_area,
        archived_geo.event_count
    FROM analytics_archive_geo_events archived_geo
    WHERE archived_geo.privacy_mode = 'anonymous'
      AND (
          archived_geo.geo_area LIKE 'country:__'
          OR archived_geo.geo_area LIKE 'continent:__'
      )
      AND %1$s
    UNION ALL
    SELECT
        events.website_token,
        %2$s AS event_day,
        events.event_name,
        events.geo_area,
        1 AS event_count
    FROM events
    WHERE events.archived_at IS NULL
      AND events.privacy_mode = 'anonymous'
      AND (
          events.geo_area LIKE 'country:__'
          OR events.geo_area LIKE 'continent:__'
      )
      AND %3$s
),
geo_counts AS (
    SELECT
        combined_geo_cells.website_token,
        combined_geo_cells.event_day,
        combined_geo_cells.event_name,
        CASE
            WHEN combined_geo_cells.geo_area LIKE 'country:%%' THEN 'country'
            ELSE 'continent'
        END AS geo_level,
        combined_geo_cells.geo_area,
        SUM(combined_geo_cells.event_count) AS event_count,
        %4$d AS minimum_cell_count
    FROM combined_geo_cells
    GROUP BY
        combined_geo_cells.website_token,
        combined_geo_cells.event_day,
        combined_geo_cells.event_name,
        CASE
            WHEN combined_geo_cells.geo_area LIKE 'country:%%' THEN 'country'
            ELSE 'continent'
        END,
        combined_geo_cells.geo_area
),
ranked_geo_counts AS (
    SELECT
        website_token,
        event_day,
        event_name,
        geo_level,
        geo_area,
        event_count,
        minimum_cell_count,
        SUM(
            CASE WHEN event_count < minimum_cell_count THEN 1 ELSE 0 END
        ) OVER (
            PARTITION BY website_token, event_day, event_name, geo_level
        ) AS primary_suppressed_count,
        ROW_NUMBER() OVER (
            PARTITION BY website_token, event_day, event_name, geo_level
            ORDER BY
                CASE WHEN event_count >= minimum_cell_count THEN 0 ELSE 1 END,
                event_count,
                geo_area
        ) AS visible_rank
    FROM geo_counts
),
classified_geo_counts AS (
    SELECT
        website_token,
        event_day,
        event_name,
        geo_level,
        geo_area,
        event_count,
        minimum_cell_count,
        CASE
            WHEN event_count < minimum_cell_count THEN 1
            WHEN primary_suppressed_count = 1 AND visible_rank = 1 THEN 1
            ELSE 0
        END AS pool_in_other
    FROM ranked_geo_counts
)
SELECT
    website_token,
    event_day,
    event_name,
    geo_area,
    event_count
FROM classified_geo_counts
WHERE event_count >= minimum_cell_count
  AND pool_in_other = 0
UNION ALL
SELECT
    website_token,
    event_day,
    event_name,
    CASE
        WHEN geo_level = 'country' THEN 'country:other'
        ELSE 'continent:other'
    END AS geo_area,
    SUM(event_count) AS event_count
FROM classified_geo_counts
WHERE pool_in_other = 1
GROUP BY
    website_token,
    event_day,
    event_name,
    geo_level,
    minimum_cell_count
HAVING SUM(event_count) >= minimum_cell_count
SQL, $this->completedDayPredicate('archived_geo.event_day'), $this->eventDayExpression('events.created_at'), $this->completedDayPredicate('events.created_at'), $geoMinimumCellCount);
    }

    private function completedHourPredicate(string $column): string
    {
        $platform = $this->connection->getDatabasePlatform();

        return match (true) {
            $platform instanceof AbstractMySQLPlatform =>
                sprintf("%s < DATE_FORMAT(UTC_TIMESTAMP(), '%%Y-%%m-%%d %%H:00:00')", $column),
            $platform instanceof PostgreSQLPlatform =>
                sprintf("%s < date_trunc('hour', CURRENT_TIMESTAMP AT TIME ZONE 'UTC')", $column),
            $platform instanceof SQLServerPlatform =>
                sprintf('%s < DATEADD(hour, DATEDIFF(hour, 0, SYSUTCDATETIME()), 0)', $column),
            $platform instanceof SQLitePlatform =>
                sprintf("%s < strftime('%%Y-%%m-%%d %%H:00:00', 'now')", $column),
            default => '1 = 0',
        };
    }

    private function completedDayPredicate(string $column): string
    {
        $platform = $this->connection->getDatabasePlatform();

        return match (true) {
            $platform instanceof AbstractMySQLPlatform =>
                sprintf('%s < UTC_DATE()', $column),
            $platform instanceof PostgreSQLPlatform =>
                sprintf("%s < CAST(CURRENT_TIMESTAMP AT TIME ZONE 'UTC' AS date)", $column),
            $platform instanceof SQLServerPlatform =>
                sprintf('%s < CAST(SYSUTCDATETIME() AS date)', $column),
            $platform instanceof SQLitePlatform =>
                sprintf("%s < date('now')", $column),
            default => '1 = 0',
        };
    }

    private function eventDayExpression(string $column): string
    {
        $platform = $this->connection->getDatabasePlatform();

        return match (true) {
            $platform instanceof AbstractMySQLPlatform,
            $platform instanceof PostgreSQLPlatform,
            $platform instanceof SQLServerPlatform => sprintf('CAST(%s AS date)', $column),
            $platform instanceof SQLitePlatform => sprintf('date(%s)', $column),
            default => 'NULL',
        };
    }
}
