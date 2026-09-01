<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260901000000 extends AbstractMigration
{
    private const EVENT_ARCHIVE_INDEX = 'IDX_EVENTS_ARCHIVED_CREATED_ID';

    public function getDescription(): string
    {
        return 'Add private analytics archives, maintenance lease, and archive-aware operational and BI views';
    }

    public function isTransactional(): bool
    {
        // MySQL and MariaDB implicitly commit table, index, and view DDL. The
        // other supported platforms can roll the migration back atomically.
        return !($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform);
    }

    public function up(Schema $schema): void
    {
        $this->assertMySqlFamilyPlatformMatchesServer();
        $this->assertGeoViewPlatformSupport();

        // These changes are queued explicitly so the tables and marker column
        // exist before this same migration creates views that reference them.
        $this->addEventArchiveSchemaSql($schema);
        $this->addCreateTableSql($schema, $this->archiveEventsTable());
        $this->addCreateTableSql($schema, $this->archiveGoalsTable());
        $this->addCreateTableSql($schema, $this->archiveGeoEventsTable());
        $this->addCreateTableSql($schema, $this->maintenanceLockTable());

        $this->addSql(<<<'SQL'
INSERT INTO analytics_maintenance_lock (id, owner_token, expires_at, updated_at)
SELECT 1, NULL, NULL, CURRENT_TIMESTAMP
WHERE NOT EXISTS (SELECT 1 FROM analytics_maintenance_lock WHERE id = 1)
SQL);

        foreach ($this->allViewNames() as $viewName) {
            $this->addDropViewSql($viewName);
        }

        $this->addOperationalArchiveViews();
        $this->addArchiveAwareEventBiView();
        $this->addArchiveAwareGoalBiView();
        $this->addArchiveAwareGeoBiView();
    }

    public function down(Schema $schema): void
    {
        $this->assertMySqlFamilyPlatformMatchesServer();
        $this->assertGeoViewPlatformSupport();

        foreach ($this->allViewNames() as $viewName) {
            $this->addDropViewSql($viewName);
        }

        $this->addRemoveEventArchiveSchemaSql($schema);

        foreach ([
            'analytics_archive_geo_events',
            'analytics_archive_goals',
            'analytics_archive_events',
            'analytics_maintenance_lock',
        ] as $tableName) {
            $this->addDropTableSql($tableName);
        }

        $this->addLegacyEventBiView();
        $this->addLegacyGoalBiView();
        $this->addLegacyGeoBiView();
    }

    /** @return list<string> */
    private function allViewNames(): array
    {
        return [
            'bi_anonymous_pageviews_v1',
            'bi_anonymous_events_v1',
            'bi_anonymous_goals_v1',
            'bi_anonymous_geo_events_v1',
            'analytics_archived_pageviews_v1',
            'analytics_archived_events_v1',
            'analytics_archived_goals_v1',
        ];
    }

    private function addOperationalArchiveViews(): void
    {
        $this->addSql(<<<'SQL'
CREATE VIEW analytics_archived_events_v1 AS
SELECT
    website_token,
    event_hour,
    privacy_mode,
    event_name,
    page_path,
    referrer_channel,
    device_class,
    viewport_bucket,
    event_count
FROM analytics_archive_events
SQL);

        $this->addSql(<<<'SQL'
CREATE VIEW analytics_archived_pageviews_v1 AS
SELECT
    website_token,
    event_hour,
    privacy_mode,
    page_path,
    referrer_channel,
    device_class,
    viewport_bucket,
    event_count
FROM analytics_archive_events
WHERE event_name = 'view'
SQL);

        $this->addSql(<<<'SQL'
CREATE VIEW analytics_archived_goals_v1 AS
SELECT
    website_token,
    event_day,
    privacy_mode,
    goal_event,
    event_count
FROM analytics_archive_goals
SQL);
    }

    private function addArchiveAwareEventBiView(): void
    {
        $this->addSql(sprintf(<<<'SQL'
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
CROSS JOIN analytics_privacy_settings privacy
WHERE privacy.id = 1
  AND privacy.anonymous_min_cell_count BETWEEN 2 AND 1000
  AND event_counts.event_count >= privacy.anonymous_min_cell_count
SQL, $this->completedHourPredicate('archived_events.event_hour'), $this->completedHourPredicate('events.created_at')));
    }

    private function addArchiveAwareGoalBiView(): void
    {
        $this->addSql(sprintf(<<<'SQL'
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
CROSS JOIN analytics_privacy_settings privacy
WHERE privacy.id = 1
  AND privacy.anonymous_min_cell_count BETWEEN 2 AND 1000
  AND goal_counts.event_count >= privacy.anonymous_min_cell_count
SQL,
            $this->completedDayPredicate('archived_goals.event_day'),
            $this->eventDayExpression('events.created_at'),
            $this->completedDayPredicate('events.created_at'),
        ));
    }

    private function addArchiveAwareGeoBiView(): void
    {
        $this->addSql(sprintf(<<<'SQL'
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
        privacy.anonymous_geo_min_cell_count AS minimum_cell_count
    FROM combined_geo_cells
    CROSS JOIN analytics_privacy_settings privacy
    WHERE privacy.id = 1
      AND privacy.anonymous_geo_min_cell_count BETWEEN 10 AND 1000
    GROUP BY
        combined_geo_cells.website_token,
        combined_geo_cells.event_day,
        combined_geo_cells.event_name,
        CASE
            WHEN combined_geo_cells.geo_area LIKE 'country:%%' THEN 'country'
            ELSE 'continent'
        END,
        combined_geo_cells.geo_area,
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
SQL,
            $this->completedDayPredicate('archived_geo.event_day'),
            $this->eventDayExpression('events.created_at'),
            $this->completedDayPredicate('events.created_at'),
        ));
    }

    private function addLegacyEventBiView(): void
    {
        $this->addSql(sprintf(<<<'SQL'
CREATE VIEW bi_anonymous_events_v1 AS
SELECT
    events.website_token,
    events.created_at AS event_hour,
    events.event_name,
    events.url AS page_path,
    COALESCE(events.referrer, 'unknown') AS referrer_channel,
    events.device_class,
    events.viewport_bucket,
    COUNT(*) AS event_count
FROM events
CROSS JOIN analytics_privacy_settings privacy
WHERE privacy.id = 1
  AND privacy.anonymous_min_cell_count BETWEEN 2 AND 1000
  AND events.privacy_mode = 'anonymous'
  AND %s
GROUP BY
    events.website_token,
    events.created_at,
    events.event_name,
    events.url,
    COALESCE(events.referrer, 'unknown'),
    events.device_class,
    events.viewport_bucket,
    privacy.anonymous_min_cell_count
HAVING COUNT(*) >= privacy.anonymous_min_cell_count
SQL, $this->completedHourPredicate('events.created_at')));
    }

    private function addLegacyGoalBiView(): void
    {
        $this->addSql(sprintf(<<<'SQL'
CREATE VIEW bi_anonymous_goals_v1 AS
SELECT
    events.website_token,
    %1$s AS event_day,
    events.goal_event,
    COUNT(*) AS event_count
FROM events
CROSS JOIN analytics_privacy_settings privacy
WHERE privacy.id = 1
  AND privacy.anonymous_min_cell_count BETWEEN 2 AND 1000
  AND events.privacy_mode = 'anonymous'
  AND events.goal_event IS NOT NULL
  AND %2$s
GROUP BY
    events.website_token,
    %1$s,
    events.goal_event,
    privacy.anonymous_min_cell_count
HAVING COUNT(*) >= privacy.anonymous_min_cell_count
SQL, $this->eventDayExpression('events.created_at'), $this->completedDayPredicate('events.created_at')));
    }

    private function addLegacyGeoBiView(): void
    {
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
SQL, $this->eventDayExpression('events.created_at'), $this->completedDayPredicate('events.created_at')));
    }

    private function addEventArchiveSchemaSql(Schema $schema): void
    {
        $hasEventsTable = $schema->hasTable('events');
        $from = $hasEventsTable
            ? clone $schema->getTable('events')
            : $this->minimalEventsTable();
        $to = clone $from;
        $addColumn = !$to->hasColumn('archived_at');
        $addIndex = !$to->hasIndex(self::EVENT_ARCHIVE_INDEX);

        if (!$addColumn && !$addIndex) {
            return;
        }

        if ($addColumn) {
            $to->addColumn('archived_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        }
        if ($addIndex) {
            $to->addIndex(
                ['archived_at', 'created_at', 'id'],
                self::EVENT_ARCHIVE_INDEX,
            );
        }

        if ($this->connection->getDatabasePlatform() instanceof SqlitePlatform) {
            if ($addColumn) {
                $columnOptions = $to->getColumn('archived_at')->toArray();
                $columnOptions['comment'] = '(DC2Type:datetime_immutable)';
                $declaration = $this->connection->getDatabasePlatform()
                    ->getColumnDeclarationSQL('archived_at', $columnOptions);

                // SQLite persists ADD COLUMN text inside sqlite_schema. Its
                // inline type hint ends with a line comment; the default binary
                // collation safely resumes the declaration on the next line so
                // the stored CREATE TABLE statement remains valid and Doctrine
                // can reverse-engineer the immutable type without rebuilding a
                // potentially large events table.
                $this->addSql('ALTER TABLE events ADD COLUMN '.$declaration.' COLLATE BINARY');
            }
            if ($addIndex) {
                $this->addSql($this->connection->getDatabasePlatform()->getCreateIndexSQL(
                    new Index(
                        self::EVENT_ARCHIVE_INDEX,
                        ['archived_at', 'created_at', 'id'],
                    ),
                    'events',
                ));
            }

            return;
        }

        $diff = (new Comparator($this->connection->getDatabasePlatform()))
            ->compareTables($from, $to);
        foreach ($this->connection->getDatabasePlatform()->getAlterTableSQL($diff) as $sql) {
            $this->addSql($sql);
        }
    }

    private function addRemoveEventArchiveSchemaSql(Schema $schema): void
    {
        if (!$schema->hasTable('events')) {
            return;
        }

        $from = clone $schema->getTable('events');
        $to = clone $from;
        if ($to->hasIndex(self::EVENT_ARCHIVE_INDEX)) {
            $to->dropIndex(self::EVENT_ARCHIVE_INDEX);
        }
        if ($to->hasColumn('archived_at')) {
            $to->dropColumn('archived_at');
        }

        $diff = (new Comparator($this->connection->getDatabasePlatform()))
            ->compareTables($from, $to);
        foreach ($this->connection->getDatabasePlatform()->getAlterTableSQL($diff) as $sql) {
            $this->addSql($sql);
        }
    }

    private function minimalEventsTable(): Table
    {
        $events = new Table('events');
        $events->addColumn('id', Types::INTEGER);
        $events->addColumn('created_at', Types::DATETIME_IMMUTABLE);

        return $events;
    }

    private function addCreateTableSql(Schema $schema, Table $table): void
    {
        if ($schema->hasTable($table->getName())) {
            return;
        }

        foreach ($this->connection->getDatabasePlatform()->getCreateTableSQL($table) as $sql) {
            $this->addSql($sql);
        }
    }

    private function archiveEventsTable(): Table
    {
        $table = $this->privateTable('analytics_archive_events');
        $table->addColumn('cell_key', Types::STRING, ['length' => 64]);
        $table->addColumn('website_token', Types::STRING, ['length' => 191]);
        $table->addColumn('event_hour', Types::DATETIME_IMMUTABLE);
        $table->addColumn('privacy_mode', Types::STRING, ['length' => 20]);
        $table->addColumn('event_name', Types::STRING, ['length' => 191]);
        $table->addColumn('page_path', Types::TEXT);
        $table->addColumn('referrer_channel', Types::TEXT);
        $table->addColumn('device_class', Types::STRING, ['length' => 20]);
        $table->addColumn('viewport_bucket', Types::STRING, ['length' => 20]);
        $table->addColumn('event_count', Types::BIGINT);
        $table->setPrimaryKey(['cell_key']);
        $table->addIndex(
            ['privacy_mode', 'event_hour'],
            'IDX_ARCHIVE_EVENTS_PRIVACY_HOUR',
        );
        $table->addIndex(
            ['event_hour', 'cell_key'],
            'IDX_ARCHIVE_EVENTS_RETENTION',
        );

        return $table;
    }

    private function archiveGoalsTable(): Table
    {
        $table = $this->privateTable('analytics_archive_goals');
        $table->addColumn('cell_key', Types::STRING, ['length' => 64]);
        $table->addColumn('website_token', Types::STRING, ['length' => 191]);
        $table->addColumn('event_day', Types::DATE_IMMUTABLE);
        $table->addColumn('privacy_mode', Types::STRING, ['length' => 20]);
        $table->addColumn('goal_event', Types::STRING, ['length' => 191]);
        $table->addColumn('event_count', Types::BIGINT);
        $table->setPrimaryKey(['cell_key']);
        $table->addIndex(
            ['privacy_mode', 'event_day'],
            'IDX_ARCHIVE_GOALS_PRIVACY_DAY',
        );
        $table->addIndex(
            ['event_day', 'cell_key'],
            'IDX_ARCHIVE_GOALS_RETENTION',
        );

        return $table;
    }

    private function archiveGeoEventsTable(): Table
    {
        $table = $this->privateTable('analytics_archive_geo_events');
        $table->addColumn('cell_key', Types::STRING, ['length' => 64]);
        $table->addColumn('website_token', Types::STRING, ['length' => 191]);
        $table->addColumn('event_day', Types::DATE_IMMUTABLE);
        $table->addColumn('privacy_mode', Types::STRING, ['length' => 20]);
        $table->addColumn('event_name', Types::STRING, ['length' => 191]);
        $table->addColumn('geo_area', Types::STRING, ['length' => 16]);
        $table->addColumn('event_count', Types::BIGINT);
        $table->setPrimaryKey(['cell_key']);
        $table->addIndex(
            ['privacy_mode', 'event_day'],
            'IDX_ARCHIVE_GEO_PRIVACY_DAY',
        );
        $table->addIndex(
            ['event_day', 'cell_key'],
            'IDX_ARCHIVE_GEO_RETENTION',
        );

        return $table;
    }

    private function maintenanceLockTable(): Table
    {
        $table = $this->privateTable('analytics_maintenance_lock');
        $table->addColumn('id', Types::INTEGER);
        $table->addColumn('owner_token', Types::STRING, [
            'length' => 64,
            'notnull' => false,
        ]);
        $table->addColumn('expires_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['id']);

        return $table;
    }

    private function privateTable(string $name): Table
    {
        $table = new Table($name);
        $table->addOption('charset', 'utf8mb4');
        $table->addOption('collation', 'utf8mb4_unicode_ci');

        return $table;
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

    private function addDropTableSql(string $tableName): void
    {
        $this->addSql(sprintf('DROP TABLE IF EXISTS %s', $tableName));
    }

    private function eventDayExpression(string $column): string
    {
        $platform = $this->connection->getDatabasePlatform();

        return match (true) {
            $platform instanceof AbstractMySQLPlatform,
            $platform instanceof PostgreSQLPlatform,
            $platform instanceof SQLServerPlatform => sprintf('CAST(%s AS date)', $column),
            $platform instanceof SqlitePlatform => sprintf('date(%s)', $column),
            default => 'NULL',
        };
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
            $platform instanceof SqlitePlatform =>
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
            $platform instanceof SqlitePlatform =>
                sprintf("%s < date('now')", $column),
            default => '1 = 0',
        };
    }

    private function assertGeoViewPlatformSupport(): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            $platform instanceof MySQLPlatform
                && !($platform instanceof MySQL80Platform)
                && !($platform instanceof MariaDBPlatform),
            'The geographic BI view requires MySQL 8.0+ or MariaDB 10.6+. Configure the DATABASE_URL serverVersion so Doctrine can verify support.',
        );
    }

    private function assertMySqlFamilyPlatformMatchesServer(): void
    {
        $platform = $this->connection->getDatabasePlatform();
        if (!$platform instanceof AbstractMySQLPlatform) {
            return;
        }

        $reportedVersion = $this->connection->fetchOne('SELECT VERSION()');
        if (!is_string($reportedVersion) || $reportedVersion === '') {
            // Keeps generated-SQL tests and other connections without a
            // reported server version deterministic.
            return;
        }

        $serverIsMariaDb = stripos($reportedVersion, 'mariadb') !== false;
        $platformIsMariaDb = $platform instanceof MariaDBPlatform;
        if ($serverIsMariaDb === $platformIsMariaDb) {
            return;
        }

        $versionHint = $reportedVersion;
        if ($serverIsMariaDb
            && preg_match('/^(?:5\.5\.5-)?(\d+\.\d+\.\d+)-MariaDB/i', $reportedVersion, $matches) === 1
        ) {
            $versionHint = $matches[1].'-MariaDB';
        } elseif (preg_match('/^(\d+\.\d+(?:\.\d+)?)/', $reportedVersion, $matches) === 1) {
            $versionHint = $matches[1];
        }

        $this->abortIf(
            true,
            sprintf(
                'Database server "%s" does not match Doctrine platform %s. Set DATABASE_URL serverVersion=%s, clear the application cache, and rerun this migration.',
                $reportedVersion,
                $platform::class,
                $versionHint,
            ),
        );
    }
}
