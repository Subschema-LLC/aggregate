<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDb1060Platform;
use Doctrine\DBAL\Platforms\MySQL57Platform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Exception\AbortMigration;
use DoctrineMigrations\Version20260724002000;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once dirname(__DIR__, 2).'/migrations/Version20260724002000.php';

final class AnonymousGeoBiViewMigrationTest extends TestCase
{
    public function testGeoViewUsesDailyCoarseCellsAndTheSeparateThreshold(): void
    {
        $viewSql = $this->viewSqlFor(new MySQL80Platform());

        self::assertStringContainsString('CREATE VIEW bi_anonymous_geo_events_v1', $viewSql);
        self::assertStringContainsString('CROSS JOIN analytics_privacy_settings privacy', $viewSql);
        self::assertStringContainsString(
            'privacy.anonymous_geo_min_cell_count BETWEEN 10 AND 1000',
            $viewSql,
        );
        self::assertStringContainsString("events.privacy_mode = 'anonymous'", $viewSql);
        self::assertStringContainsString('CAST(events.created_at AS date) AS event_day', $viewSql);
        self::assertStringContainsString('events.event_name', $viewSql);
        self::assertStringContainsString('events.geo_area', $viewSql);
        self::assertStringContainsString("THEN 'country'", $viewSql);
        self::assertStringContainsString("ELSE 'continent'", $viewSql);
        self::assertStringContainsString(<<<'SQL'
SELECT
    website_token,
    event_day,
    event_name,
    geo_area,
    event_count
FROM classified_geo_counts
SQL, $viewSql);
        self::assertStringNotContainsString('events.url', $viewSql);
        self::assertStringNotContainsString('referrer', $viewSql);
        self::assertStringNotContainsString('device_class', $viewSql);
        self::assertStringNotContainsString('viewport_bucket', $viewSql);
        self::assertStringNotContainsString('visitor_id', $viewSql);
        self::assertStringNotContainsString('session_id', $viewSql);
    }

    public function testGeoViewPoolsPrimaryAndComplementarySuppressedAreas(): void
    {
        $viewSql = $this->viewSqlFor(new PostgreSQLPlatform());

        self::assertStringContainsString(
            'CASE WHEN event_count < minimum_cell_count THEN 1 ELSE 0 END',
            $viewSql,
        );
        self::assertStringContainsString(
            'PARTITION BY website_token, event_day, event_name, geo_level',
            $viewSql,
        );
        self::assertStringContainsString('ROW_NUMBER() OVER', $viewSql);
        self::assertStringContainsString(
            'WHEN primary_suppressed_count = 1 AND visible_rank = 1 THEN 1',
            $viewSql,
        );
        self::assertStringContainsString("THEN 'country:other'", $viewSql);
        self::assertStringContainsString("ELSE 'continent:other'", $viewSql);
        self::assertStringContainsString('WHERE pool_in_other = 1', $viewSql);
        self::assertStringContainsString(
            'HAVING SUM(event_count) >= minimum_cell_count',
            $viewSql,
        );
    }

    #[DataProvider('supportedCompletedDayExpressions')]
    public function testGeoViewWithholdsTheCurrentUtcDayOnEverySupportedPlatform(
        AbstractPlatform $platform,
        string $expectedDayExpression,
        string $expectedPredicate,
    ): void {
        $viewSql = $this->viewSqlFor($platform);

        self::assertStringContainsString($expectedDayExpression, $viewSql);
        self::assertStringContainsString($expectedPredicate, $viewSql);
        self::assertStringNotContainsString('AND 1 = 0', $viewSql);
    }

    public static function supportedCompletedDayExpressions(): iterable
    {
        yield 'MySQL' => [
            new MySQL80Platform(),
            'CAST(events.created_at AS date) AS event_day',
            'events.created_at < UTC_DATE()',
        ];
        yield 'MariaDB' => [
            new MariaDb1060Platform(),
            'CAST(events.created_at AS date) AS event_day',
            'events.created_at < UTC_DATE()',
        ];
        yield 'PostgreSQL' => [
            new PostgreSQLPlatform(),
            'CAST(events.created_at AS date) AS event_day',
            "events.created_at < CAST(CURRENT_TIMESTAMP AT TIME ZONE 'UTC' AS date)",
        ];
        yield 'SQL Server' => [
            new SQLServerPlatform(),
            'CAST(events.created_at AS date) AS event_day',
            'events.created_at < CAST(SYSUTCDATETIME() AS date)',
        ];
        yield 'SQLite' => [
            new SqlitePlatform(),
            'date(events.created_at) AS event_day',
            "events.created_at < date('now')",
        ];
    }

    public function testUnknownPlatformFailsClosed(): void
    {
        $viewSql = $this->viewSqlFor(new OraclePlatform());

        self::assertStringContainsString('NULL AS event_day', $viewSql);
        self::assertStringContainsString('AND 1 = 0', $viewSql);
    }

    #[DataProvider('unsupportedMySqlPlatforms')]
    public function testLegacyOrGenericMySqlAbortsBeforeSchedulingViewSql(
        AbstractPlatform $platform,
    ): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $migration = new Version20260724002000(
            $connection,
            $this->createStub(LoggerInterface::class),
        );

        try {
            $migration->up(new Schema());
            self::fail('MySQL 5.7 must not receive unsupported CTE/window-function SQL.');
        } catch (AbortMigration $exception) {
            self::assertStringContainsString('requires MySQL 8.0+', $exception->getMessage());
            self::assertStringContainsString('serverVersion', $exception->getMessage());
            self::assertSame([], $migration->getSql());
        }
    }

    public static function unsupportedMySqlPlatforms(): iterable
    {
        yield 'MySQL 5.7' => [new MySQL57Platform()];
        yield 'generic or legacy MySQL' => [new MySQLPlatform()];
    }

    private function viewSqlFor(AbstractPlatform $platform): string
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $migration = new Version20260724002000(
            $connection,
            $this->createStub(LoggerInterface::class),
        );

        $migration->up(new Schema());
        $statements = array_map(
            static fn ($query): string => $query->getStatement(),
            $migration->getSql(),
        );

        return implode("\n", array_filter(
            $statements,
            static fn (string $sql): bool =>
                str_contains($sql, 'CREATE VIEW bi_anonymous_geo_events_v1'),
        ));
    }
}
