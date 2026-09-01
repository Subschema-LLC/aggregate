<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDb1060Platform;
use Doctrine\DBAL\Platforms\MySQL57Platform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Exception\AbortMigration;
use DoctrineMigrations\Version20260901000000;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once dirname(__DIR__, 2).'/migrations/Version20260901000000.php';

final class AnalyticsArchiveMigrationTest extends TestCase
{
    public function testCreatesPrivateArchivesAndOperationalViewsWithoutIdentifiers(): void
    {
        $sql = $this->sqlFor(new MySQL80Platform());

        foreach ([
            'analytics_archive_events',
            'analytics_archive_goals',
            'analytics_archive_geo_events',
            'analytics_maintenance_lock',
        ] as $table) {
            self::assertStringContainsString('CREATE TABLE '.$table, $sql);
        }

        self::assertStringContainsString('CREATE VIEW analytics_archived_events_v1', $sql);
        self::assertStringContainsString('CREATE VIEW analytics_archived_pageviews_v1', $sql);
        self::assertStringContainsString("WHERE event_name = 'view'", $sql);
        self::assertStringContainsString('CREATE VIEW analytics_archived_goals_v1', $sql);

        $operationalSql = $this->matchingSql($sql, 'CREATE VIEW analytics_archived_');
        self::assertStringNotContainsString('cell_key', $operationalSql);
        self::assertStringNotContainsString('visitor_id', $operationalSql);
        self::assertStringNotContainsString('session_id', $operationalSql);
        self::assertStringNotContainsString('custom_data', $operationalSql);
    }

    public function testBiViewsMergeArchiveCellsWithOnlyUnarchivedRawRowsBeforeSuppression(): void
    {
        $sql = $this->sqlFor(new MySQL80Platform());

        $events = $this->viewSql($sql, 'bi_anonymous_events_v1');
        self::assertStringContainsString('FROM analytics_archive_events archived_events', $events);
        self::assertStringContainsString('events.archived_at IS NULL', $events);
        self::assertStringContainsString('SUM(event_count) AS event_count', $events);
        self::assertStringContainsString('event_counts.event_count >= privacy.anonymous_min_cell_count', $events);

        $goals = $this->viewSql($sql, 'bi_anonymous_goals_v1');
        self::assertStringContainsString('FROM analytics_archive_goals archived_goals', $goals);
        self::assertStringContainsString('events.archived_at IS NULL', $goals);
        self::assertStringContainsString('SUM(event_count) AS event_count', $goals);

        $geo = $this->viewSql($sql, 'bi_anonymous_geo_events_v1');
        self::assertStringContainsString('FROM analytics_archive_geo_events archived_geo', $geo);
        self::assertStringContainsString('events.archived_at IS NULL', $geo);
        self::assertStringContainsString('SUM(combined_geo_cells.event_count) AS event_count', $geo);
        self::assertStringContainsString('primary_suppressed_count', $geo);
    }

    public function testArchiveTablesHaveSeparateReportingAndRetentionIndexes(): void
    {
        $sql = $this->sqlFor(new MySQL80Platform());

        self::assertStringContainsString(
            'INDEX IDX_ARCHIVE_EVENTS_PRIVACY_HOUR (privacy_mode, event_hour)',
            $sql,
        );
        self::assertStringContainsString(
            'INDEX IDX_ARCHIVE_EVENTS_RETENTION (event_hour, cell_key)',
            $sql,
        );
        self::assertStringContainsString(
            'INDEX IDX_ARCHIVE_GOALS_RETENTION (event_day, cell_key)',
            $sql,
        );
        self::assertStringContainsString(
            'INDEX IDX_ARCHIVE_GEO_RETENTION (event_day, cell_key)',
            $sql,
        );
    }

    public function testSqliteAddsTheArchiveMarkerWithoutRebuildingTheEventsTable(): void
    {
        $sql = $this->sqlFor(new SqlitePlatform());

        self::assertStringContainsString('ALTER TABLE events ADD COLUMN archived_at DATETIME DEFAULT NULL', $sql);
        self::assertStringContainsString('(DC2Type:datetime_immutable)', $sql);
        self::assertStringNotContainsString('DROP TABLE events', $sql);
        self::assertStringNotContainsString('CREATE TEMPORARY TABLE', $sql);
    }

    public function testOnlyMySqlFamilyMigrationDdlIsNonTransactional(): void
    {
        self::assertFalse($this->migrationFor(new MySQL80Platform())->isTransactional());
        self::assertFalse($this->migrationFor(new MariaDb1060Platform())->isTransactional());
        self::assertTrue($this->migrationFor(new PostgreSQLPlatform())->isTransactional());
        self::assertTrue($this->migrationFor(new SQLServerPlatform())->isTransactional());
        self::assertTrue($this->migrationFor(new SqlitePlatform())->isTransactional());
    }

    public function testFreshResetScriptRemovesPrivateArchiveDataBeforeReinstalling(): void
    {
        $sql = file_get_contents(dirname(__DIR__, 2).'/docs/install_fresh.sql');
        self::assertIsString($sql);

        foreach ([
            'analytics_archived_events_v1',
            'analytics_archived_pageviews_v1',
            'analytics_archived_goals_v1',
        ] as $view) {
            self::assertStringContainsString(sprintf('DROP VIEW IF EXISTS `%s`', $view), $sql);
        }
        foreach ([
            'analytics_archive_events',
            'analytics_archive_goals',
            'analytics_archive_geo_events',
            'analytics_maintenance_lock',
        ] as $table) {
            self::assertStringContainsString(sprintf('DROP TABLE IF EXISTS `%s`', $table), $sql);
        }
        self::assertLessThan(
            strpos($sql, 'DROP TABLE IF EXISTS `analytics_archive_events`'),
            strpos($sql, 'DROP VIEW IF EXISTS `analytics_archived_events_v1`'),
            'Archive-dependent views must be dropped before their private tables.',
        );
    }

    #[DataProvider('supportedPlatforms')]
    public function testMigrationUsesCompletedUtcBoundariesOnSupportedPlatforms(
        AbstractPlatform $platform,
        string $hourPredicate,
        string $dayPredicate,
    ): void {
        $sql = $this->sqlFor($platform);

        self::assertStringContainsString($hourPredicate, $sql);
        self::assertStringContainsString($dayPredicate, $sql);
        self::assertStringNotContainsString('AND 1 = 0', $sql);
    }

    public static function supportedPlatforms(): iterable
    {
        yield 'MySQL 8' => [
            new MySQL80Platform(),
            "events.created_at < DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-%d %H:00:00')",
            'events.created_at < UTC_DATE()',
        ];
        yield 'MariaDB' => [
            new MariaDb1060Platform(),
            "events.created_at < DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-%d %H:00:00')",
            'events.created_at < UTC_DATE()',
        ];
        yield 'PostgreSQL' => [
            new PostgreSQLPlatform(),
            "events.created_at < date_trunc('hour', CURRENT_TIMESTAMP AT TIME ZONE 'UTC')",
            "events.created_at < CAST(CURRENT_TIMESTAMP AT TIME ZONE 'UTC' AS date)",
        ];
        yield 'SQL Server' => [
            new SQLServerPlatform(),
            'events.created_at < DATEADD(hour, DATEDIFF(hour, 0, SYSUTCDATETIME()), 0)',
            'events.created_at < CAST(SYSUTCDATETIME() AS date)',
        ];
        yield 'SQLite' => [
            new SqlitePlatform(),
            "events.created_at < strftime('%Y-%m-%d %H:00:00', 'now')",
            "events.created_at < date('now')",
        ];
    }

    public function testMySql57IsRejectedBeforeAnySqlIsScheduled(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQL57Platform());
        $migration = new Version20260901000000(
            $connection,
            $this->createStub(LoggerInterface::class),
        );

        try {
            $migration->up(new Schema());
            self::fail('MySQL 5.7 must not receive CTE/window-function view SQL.');
        } catch (AbortMigration $e) {
            self::assertStringContainsString('requires MySQL 8.0+', $e->getMessage());
            self::assertSame([], $migration->getSql());
        }
    }

    public function testMariaDbServerConfiguredAsMySqlIsRejectedBeforeAnySqlIsScheduled(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQL80Platform());
        $connection->method('fetchOne')
            ->with('SELECT VERSION()')
            ->willReturn('10.11.14-MariaDB-0+deb12u2');
        $migration = new Version20260901000000(
            $connection,
            $this->createStub(LoggerInterface::class),
        );

        try {
            $migration->up(new Schema());
            self::fail('MariaDB must not be migrated through Doctrine\'s MySQL platform.');
        } catch (AbortMigration $e) {
            self::assertStringContainsString(
                'does not match Doctrine platform Doctrine\\DBAL\\Platforms\\MySQL80Platform',
                $e->getMessage(),
            );
            self::assertStringContainsString('serverVersion=10.11.14-MariaDB', $e->getMessage());
            self::assertSame([], $migration->getSql());
        }
    }

    private function sqlFor(AbstractPlatform $platform): string
    {
        $migration = $this->migrationFor($platform);
        $migration->up(new Schema());

        return implode("\n\n", array_map(
            static fn ($query): string => $query->getStatement(),
            $migration->getSql(),
        ));
    }

    private function migrationFor(AbstractPlatform $platform): Version20260901000000
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);

        return new Version20260901000000(
            $connection,
            $this->createStub(LoggerInterface::class),
        );
    }

    private function viewSql(string $sql, string $view): string
    {
        if (preg_match(
            sprintf('/CREATE VIEW %s AS.*?(?=\\n\\n(?:CREATE|DROP|ALTER|INSERT) |\\z)/s', preg_quote($view, '/')),
            $sql,
            $matches,
        ) !== 1) {
            self::fail(sprintf('View SQL for %s was not generated.', $view));
        }

        return $matches[0];
    }

    private function matchingSql(string $sql, string $needle): string
    {
        return implode("\n", array_filter(
            explode("\n\n", $sql),
            static fn (string $statement): bool => str_contains($statement, $needle),
        ));
    }
}
