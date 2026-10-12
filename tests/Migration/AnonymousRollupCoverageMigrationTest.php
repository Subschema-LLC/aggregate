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
use DoctrineMigrations\Version20261013000000;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once dirname(__DIR__, 2).'/migrations/Version20261013000000.php';

final class AnonymousRollupCoverageMigrationTest extends TestCase
{
    #[DataProvider('platforms')]
    public function testBuildsTheDailyViewsAndCoverageWithTheHourlyViewsThreshold(AbstractPlatform $platform, string $create): void
    {
        $statements = $this->statements($platform, 'WHERE (event_counts.event_count >= 9)');

        $created = array_values(array_filter(array_map(
            static fn (string $sql): ?string => preg_match('/^'.$create.' VIEW (bi_anonymous_[a-z_0-9]+) AS/', $sql, $match) === 1 ? $match[1] : null,
            $statements,
        )));
        // The daily view is rebuilt with the same page groups as the roll-up; the coverage view comes last.
        self::assertSame(['bi_anonymous_daily_v1', 'bi_anonymous_daily_rollup_v1', 'bi_anonymous_coverage_v1'], $created);
        self::assertStringContainsString('WHERE daily_cells.event_count >= 9', $this->find($statements, 'bi_anonymous_daily_v1'));
        self::assertStringContainsString('WHEN event_count >= 9', $this->find($statements, 'bi_anonymous_daily_rollup_v1'));
        self::assertStringContainsString('WHERE day_totals.total_count >= 9', $this->find($statements, 'bi_anonymous_coverage_v1'));
    }

    public static function platforms(): iterable
    {
        yield 'SQLite' => [new SQLitePlatform(), 'CREATE'];
        yield 'MySQL' => [new MySQL80Platform(), 'CREATE OR REPLACE'];
        yield 'PostgreSQL' => [new PostgreSQLPlatform(), 'CREATE OR REPLACE'];
        yield 'SQL Server' => [new SQLServerPlatform(), 'CREATE OR ALTER'];
    }

    public function testRebuildsTheHourlyViewWithTheSameThresholdWhenItCannotBeRead(): void
    {
        $statements = $this->statements(new PostgreSQLPlatform(), null);

        self::assertSame(1, preg_match('/event_counts\.event_count >= ([0-9]+)/', $this->find($statements, 'bi_anonymous_events_v1'), $hourly));
        self::assertSame(1, preg_match('/WHERE day_totals\.total_count >= ([0-9]+)/', $this->find($statements, 'bi_anonymous_coverage_v1'), $coverage));
        self::assertSame(1, preg_match('/WHERE daily_cells\.event_count >= ([0-9]+)/', $this->find($statements, 'bi_anonymous_daily_v1'), $daily));
        self::assertSame($hourly[1], $coverage[1]);
        self::assertSame($hourly[1], $daily[1]);
    }

    public function testDownDropsTheCoverageViewBeforeTheRollupItReads(): void
    {
        $migration = $this->migration(new SQLitePlatform(), null);
        $migration->down(new Schema());

        self::assertSame(
            ['DROP VIEW IF EXISTS bi_anonymous_coverage_v1', 'DROP VIEW IF EXISTS bi_anonymous_daily_rollup_v1'],
            array_map(static fn ($query): string => $query->getStatement(), $migration->getSql()),
        );
    }

    /** @param list<string> $statements */
    private function find(array $statements, string $view): string
    {
        foreach ($statements as $sql) {
            if (preg_match('/^CREATE (OR (REPLACE|ALTER) )?VIEW '.$view.' AS/', $sql) === 1) {
                return $sql;
            }
        }
        self::fail($view.' was not created.');
    }

    /** @return list<string> */
    private function statements(AbstractPlatform $platform, ?string $hourlyDefinition): array
    {
        $migration = $this->migration($platform, $hourlyDefinition);
        $migration->up(new Schema());

        return array_map(static fn ($query): string => $query->getStatement(), $migration->getSql());
    }

    private function migration(AbstractPlatform $platform, ?string $hourlyDefinition): Version20261013000000
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->method('fetchOne')->willReturn($hourlyDefinition ?? false);

        return new Version20261013000000($connection, $this->createStub(LoggerInterface::class));
    }
}
