<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AnonymousBiViewManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDB1060Platform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AnonymousBiViewManagerTest extends TestCase
{
    private const VIEWS = ['bi_anonymous_events_v1', 'bi_anonymous_goals_v1', 'bi_anonymous_geo_events_v1'];

    public function testSqliteDropsAndRecreatesEachViewInOneTransaction(): void
    {
        $executed = [];
        $connection = $this->connection(new SQLitePlatform(), $executed);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::once())->method('commit');

        (new AnonymousBiViewManager($connection))->regenerate(7, 30);

        self::assertCount(6, $executed);
        foreach (self::VIEWS as $index => $view) {
            self::assertSame('DROP VIEW IF EXISTS '.$view, $executed[$index * 2]);
            self::assertStringStartsWith('CREATE VIEW '.$view.' AS', $executed[$index * 2 + 1]);
        }
    }

    /** Replacing in place keeps the reporting account's grants on server databases. */
    #[DataProvider('serverPlatforms')]
    public function testServerDatabasesReplaceViewsInPlaceWithoutDropping(AbstractPlatform $platform, string $verb): void
    {
        $executed = [];
        $connection = $this->connection($platform, $executed);

        (new AnonymousBiViewManager($connection))->regenerate(7, 30);

        self::assertCount(3, $executed);
        foreach (self::VIEWS as $index => $view) {
            self::assertStringStartsWith($verb.' VIEW '.$view.' AS', $executed[$index]);
            self::assertStringNotContainsString('DROP VIEW', $executed[$index]);
        }
        self::assertStringContainsString('event_counts.event_count >= 7', $executed[0]);
        self::assertStringContainsString('goal_counts.event_count >= 7', $executed[1]);
        self::assertStringContainsString('30 AS minimum_cell_count', $executed[2]);
    }

    public static function serverPlatforms(): iterable
    {
        yield 'PostgreSQL' => [new PostgreSQLPlatform(), 'CREATE OR REPLACE'];
        yield 'MySQL' => [new MySQL80Platform(), 'CREATE OR REPLACE'];
        yield 'MariaDB' => [new MariaDB1060Platform(), 'CREATE OR REPLACE'];
        yield 'SQL Server' => [new SQLServerPlatform(), 'CREATE OR ALTER'];
    }

    public function testMysqlRunsWithoutATransactionBecauseItsDdlCommitsImplicitly(): void
    {
        $executed = [];
        $connection = $this->connection(new MySQL80Platform(), $executed);
        $connection->expects(self::never())->method('beginTransaction');

        (new AnonymousBiViewManager($connection))->regenerate(5, 25);

        self::assertCount(3, $executed);
    }

    public function testAFailedStatementRollsBackAndRethrows(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $connection->expects(self::once())->method('beginTransaction');
        $connection->method('executeStatement')->willThrowException(new \RuntimeException('view failed'));
        $connection->expects(self::never())->method('commit');
        $connection->expects(self::once())->method('rollBack');

        $this->expectExceptionMessage('view failed');
        (new AnonymousBiViewManager($connection))->regenerate(5, 25);
    }

    public function testPreviewReturnsTheSameStatementsWithoutExecutingThem(): void
    {
        $executed = [];
        $connection = $this->connection(new PostgreSQLPlatform(), $executed);
        $manager = new AnonymousBiViewManager($connection);

        $preview = $manager->previewSql(9, 40);
        self::assertSame([], $executed);

        $manager->regenerate(9, 40);
        self::assertSame($preview, $executed);
    }

    /** @param list<string> $executed */
    private function connection(AbstractPlatform $platform, array &$executed): Connection&\PHPUnit\Framework\MockObject\MockObject
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->method('executeStatement')->willReturnCallback(static function (string $sql) use (&$executed): int {
            $executed[] = $sql;

            return 0;
        });

        return $connection;
    }
}
