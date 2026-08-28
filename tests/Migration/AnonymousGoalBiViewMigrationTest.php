<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDb1060Platform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20260828000000;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once dirname(__DIR__, 2).'/migrations/Version20260828000000.php';

final class AnonymousGoalBiViewMigrationTest extends TestCase
{
    public function testGoalViewContainsOnlyThresholdedAnonymousGoalCounts(): void
    {
        $viewSql = $this->viewSqlFor(new MySQL80Platform());

        self::assertStringContainsString('CREATE VIEW bi_anonymous_goals_v1', $viewSql);
        self::assertStringContainsString('CROSS JOIN analytics_privacy_settings privacy', $viewSql);
        self::assertStringContainsString('privacy.anonymous_min_cell_count BETWEEN 2 AND 1000', $viewSql);
        self::assertStringContainsString("events.privacy_mode = 'anonymous'", $viewSql);
        self::assertStringContainsString('events.goal_event IS NOT NULL', $viewSql);
        self::assertStringContainsString('CAST(events.created_at AS date) AS event_day', $viewSql);
        self::assertStringContainsString('events.goal_event', $viewSql);
        self::assertStringContainsString('COUNT(*) AS event_count', $viewSql);
        self::assertStringContainsString(
            'HAVING COUNT(*) >= privacy.anonymous_min_cell_count',
            $viewSql,
        );
        self::assertStringNotContainsString('visitor_id', $viewSql);
        self::assertStringNotContainsString('session_id', $viewSql);
        self::assertStringNotContainsString('custom_data', $viewSql);
        self::assertStringNotContainsString('events.url', $viewSql);
        self::assertStringNotContainsString('referrer', $viewSql);
        self::assertStringNotContainsString('device_class', $viewSql);
        self::assertStringNotContainsString('viewport_bucket', $viewSql);
    }

    #[DataProvider('supportedCompletedDayExpressions')]
    public function testGoalViewWithholdsTheCurrentUtcDayOnEverySupportedPlatform(
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

    private function viewSqlFor(AbstractPlatform $platform): string
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $migration = new Version20260828000000(
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
                str_contains($sql, 'CREATE VIEW bi_anonymous_goals_v1'),
        ));
    }
}
