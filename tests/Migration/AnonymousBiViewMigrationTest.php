<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDB1060Platform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261008000000;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once dirname(__DIR__, 2).'/migrations/Version20261008000000.php';

final class AnonymousBiViewMigrationTest extends TestCase
{
    public function testBiViewUsesConfiguredThresholdInViewSql(): void
    {
        $viewSql = $this->viewSqlFor(new MySQL80Platform());

        self::assertNotSame('', $viewSql);
        self::assertStringNotContainsString('analytics_privacy_settings', $viewSql);
        self::assertStringContainsString("events.privacy_mode = 'anonymous'", $viewSql);
        self::assertStringContainsString('events.created_at AS event_hour', $viewSql);
        self::assertStringContainsString('SUM(event_count) AS event_count', $viewSql);
        self::assertStringContainsString(
            'WHERE event_counts.event_count >= 5',
            $viewSql,
        );
        self::assertStringNotContainsString('visitor_id', $viewSql);
        self::assertStringNotContainsString('session_id', $viewSql);
    }

    #[DataProvider('supportedCompletedHourPredicates')]
    public function testBiViewWithholdsTheCurrentUtcHourOnEverySupportedPlatform(
        AbstractPlatform $platform,
        string $expectedPredicate,
    ): void {
        $viewSql = $this->viewSqlFor($platform);

        self::assertStringContainsString($expectedPredicate, $viewSql);
        self::assertStringNotContainsString('AND 1 = 0', $viewSql);
    }

    public static function supportedCompletedHourPredicates(): iterable
    {
        $mysqlPredicate = "events.created_at < DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-%d %H:00:00')";

        yield 'MySQL' => [new MySQL80Platform(), $mysqlPredicate];
        yield 'MariaDB' => [new MariaDB1060Platform(), $mysqlPredicate];
        yield 'PostgreSQL' => [
            new PostgreSQLPlatform(),
            "events.created_at < date_trunc('hour', CURRENT_TIMESTAMP AT TIME ZONE 'UTC')",
        ];
        yield 'SQL Server' => [
            new SQLServerPlatform(),
            'events.created_at < DATEADD(hour, DATEDIFF(hour, 0, SYSUTCDATETIME()), 0)',
        ];
        yield 'SQLite' => [
            new SQLitePlatform(),
            "events.created_at < strftime('%Y-%m-%d %H:00:00', 'now')",
        ];
    }

    private function viewSqlFor(AbstractPlatform $platform): string
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('tablesExist')->willReturn(false);
        $connection->method('createSchemaManager')->willReturn($schemaManager);
        $migration = new Version20261008000000(
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
            static fn (string $sql): bool => str_contains($sql, 'CREATE VIEW bi_anonymous_events_v1'),
        ));
    }
}
