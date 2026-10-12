<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261012000000;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once dirname(__DIR__, 2).'/migrations/Version20261012000000.php';

final class AnonymousDailyBiViewMigrationTest extends TestCase
{
    #[DataProvider('definitions')]
    public function testReadsTheThresholdFromEachEnginesViewDefinition(?string $definition, ?int $expected): void
    {
        self::assertSame($expected, Version20261012000000::thresholdFromDefinition($definition));
    }

    public static function definitions(): iterable
    {
        yield 'SQLite and SQL Server keep the original text' => ["CREATE VIEW bi_anonymous_events_v1 AS\nWITH c AS (SELECT 1)\nSELECT SUM(event_count) AS event_count FROM x\nWHERE event_counts.event_count >= 7", 7];
        yield 'MySQL rewrites with backticks' => ['select `event_counts`.`event_count` AS `event_count` from `event_counts` where (`event_counts`.`event_count` >= 12)', 12];
        yield 'PostgreSQL adds parentheses' => [' SELECT event_counts.event_count FROM event_counts WHERE (event_counts.event_count >= 9);', 9];
        yield 'pre-2026.10 view' => ['SELECT COUNT(*) AS event_count FROM events GROUP BY url HAVING COUNT(*) >= 5', null];
        yield 'two comparisons are ambiguous' => ['WHERE a.event_count >= 5 AND b.event_count >= 6', null];
        yield 'out of range' => ['WHERE event_counts.event_count >= 1', null];
        yield 'no view' => [null, null];
    }

    #[DataProvider('platforms')]
    public function testCreatesTheDailyViewWithTheHourlyViewsThreshold(AbstractPlatform $platform, string $create): void
    {
        $statements = $this->statements($platform, 'WHERE event_counts.event_count >= 8');

        $daily = array_values(array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'VIEW bi_anonymous_daily_v1 AS')));
        self::assertCount(1, $daily);
        self::assertStringStartsWith($create.' VIEW bi_anonymous_daily_v1 AS', $daily[0]);
        self::assertStringContainsString('WHERE daily_cells.event_count >= 8', $daily[0]);
        self::assertStringContainsString('page_path AS page_group', $daily[0]);
        // The hourly view keeps its definition and its grants.
        self::assertSame([], array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'bi_anonymous_events_v1')));
    }

    public static function platforms(): iterable
    {
        yield 'SQLite' => [new SQLitePlatform(), 'CREATE'];
        yield 'MySQL' => [new MySQL80Platform(), 'CREATE OR REPLACE'];
        yield 'PostgreSQL' => [new PostgreSQLPlatform(), 'CREATE OR REPLACE'];
        yield 'SQL Server' => [new SQLServerPlatform(), 'CREATE OR ALTER'];
    }

    public function testRebuildsBothViewsWithOneThresholdWhenTheHourlyOneCannotBeRead(): void
    {
        $statements = $this->statements(new PostgreSQLPlatform(), null);

        $hourly = array_values(array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'VIEW bi_anonymous_events_v1 AS')));
        $daily = array_values(array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'VIEW bi_anonymous_daily_v1 AS')));
        self::assertCount(1, $hourly);
        self::assertCount(1, $daily);
        self::assertSame(1, preg_match('/event_counts\.event_count >= ([0-9]+)/', $hourly[0], $hourlyMatch));
        self::assertSame(1, preg_match('/WHERE daily_cells\.event_count >= ([0-9]+)/', $daily[0], $dailyMatch));
        self::assertSame($hourlyMatch[1], $dailyMatch[1]);
    }

    public function testDownDropsOnlyTheDailyView(): void
    {
        $migration = $this->migration(new SQLitePlatform(), null);
        $migration->down(new Schema());

        self::assertSame(['DROP VIEW IF EXISTS bi_anonymous_daily_v1'], array_map(static fn ($query): string => $query->getStatement(), $migration->getSql()));
    }

    /** @return list<string> */
    private function statements(AbstractPlatform $platform, ?string $hourlyDefinition): array
    {
        $migration = $this->migration($platform, $hourlyDefinition);
        $migration->up(new Schema());

        return array_map(static fn ($query): string => $query->getStatement(), $migration->getSql());
    }

    private function migration(AbstractPlatform $platform, ?string $hourlyDefinition): Version20261012000000
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->method('fetchOne')->willReturn($hourlyDefinition ?? false);

        return new Version20261012000000($connection, $this->createStub(LoggerInterface::class));
    }
}
