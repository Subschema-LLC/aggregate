<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260724002000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Expose completed-day coarse geographic event cells with primary and secondary suppression';
    }

    public function isTransactional(): bool
    {
        // MySQL and MariaDB implicitly commit view DDL statements.
        return false;
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            $platform instanceof MySQLPlatform
                && !($platform instanceof MySQL80Platform)
                && !($platform instanceof MariaDBPlatform),
            'The geographic BI view requires MySQL 8.0+ or MariaDB 10.6+. Configure the DATABASE_URL serverVersion so Doctrine can verify support.',
        );

        $this->addDropViewSql('bi_anonymous_geo_events_v1');
        $this->addSql(sprintf(<<<'SQL'
CREATE VIEW bi_anonymous_geo_events_v1 AS
WITH geo_counts AS (
    SELECT
        events.website_token,
        %1$s AS event_day,
        events.event_name,
        CASE
            WHEN events.geo_area LIKE 'country:%%' THEN 'country'
            ELSE 'continent'
        END AS geo_level,
        events.geo_area,
        COUNT(*) AS event_count,
        privacy.anonymous_geo_min_cell_count AS minimum_cell_count
    FROM events
    CROSS JOIN analytics_privacy_settings privacy
    WHERE privacy.id = 1
      AND privacy.anonymous_geo_min_cell_count BETWEEN 10 AND 1000
      AND events.privacy_mode = 'anonymous'
      AND (
          events.geo_area LIKE 'country:__'
          OR events.geo_area LIKE 'continent:__'
      )
      AND %2$s
    GROUP BY
        events.website_token,
        %1$s,
        events.event_name,
        CASE
            WHEN events.geo_area LIKE 'country:%%' THEN 'country'
            ELSE 'continent'
        END,
        events.geo_area,
        privacy.anonymous_geo_min_cell_count
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
SQL, $this->eventDayExpression(), $this->completedDayPredicate()));
    }

    public function down(Schema $schema): void
    {
        $this->addDropViewSql('bi_anonymous_geo_events_v1');
    }

    private function addDropViewSql(string $viewName): void
    {
        if ($this->connection->getDatabasePlatform() instanceof SQLServerPlatform) {
            $this->addSql(
                sprintf("IF OBJECT_ID('%1\$s', 'V') IS NOT NULL DROP VIEW %1\$s", $viewName),
            );

            return;
        }

        $this->addSql(sprintf('DROP VIEW IF EXISTS %s', $viewName));
    }

    private function eventDayExpression(): string
    {
        $platform = $this->connection->getDatabasePlatform();

        return match (true) {
            $platform instanceof AbstractMySQLPlatform,
            $platform instanceof PostgreSQLPlatform,
            $platform instanceof SQLServerPlatform => 'CAST(events.created_at AS date)',
            $platform instanceof SQLitePlatform => 'date(events.created_at)',
            default => 'NULL',
        };
    }

    private function completedDayPredicate(): string
    {
        $platform = $this->connection->getDatabasePlatform();

        return match (true) {
            $platform instanceof AbstractMySQLPlatform =>
                'events.created_at < UTC_DATE()',
            $platform instanceof PostgreSQLPlatform =>
                "events.created_at < CAST(CURRENT_TIMESTAMP AT TIME ZONE 'UTC' AS date)",
            $platform instanceof SQLServerPlatform =>
                'events.created_at < CAST(SYSUTCDATETIME() AS date)',
            $platform instanceof SQLitePlatform =>
                "events.created_at < date('now')",
            default => '1 = 0',
        };
    }
}
