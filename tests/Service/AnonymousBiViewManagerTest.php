<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AggregateConfigLoader;
use App\Service\AnonymousBiViewManager;
use App\Service\BiPageGroups;
use App\Service\CustomDataSettings;
use App\Service\ReportingTimezone;
use App\Service\WebsiteScopeCatalog;
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
    private const VIEWS = ['bi_anonymous_events_v1', 'bi_anonymous_goals_v1', 'bi_anonymous_geo_events_v1', 'bi_anonymous_daily_v1', 'bi_anonymous_daily_rollup_v1', 'bi_anonymous_coverage_v1'];

    public function testSqliteDropsAndRecreatesEachViewInOneTransaction(): void
    {
        $executed = [];
        $connection = $this->connection(new SQLitePlatform(), $executed);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::once())->method('commit');

        (new AnonymousBiViewManager($connection))->regenerate(7, 30);

        self::assertCount(12, $executed);
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

        self::assertCount(6, $executed);
        foreach (self::VIEWS as $index => $view) {
            self::assertStringStartsWith($verb.' VIEW '.$view.' AS', $executed[$index]);
            self::assertStringNotContainsString('DROP VIEW', $executed[$index]);
        }
        self::assertStringContainsString('event_counts.event_count >= 7', $executed[0]);
        self::assertStringContainsString('goal_counts.event_count >= 7', $executed[1]);
        self::assertStringContainsString('30 AS minimum_cell_count', $executed[2]);
        self::assertStringContainsString('WHERE daily_cells.event_count >= 7', $executed[3]);
        self::assertStringContainsString('daily_cells.event_count - daily_cells.released_hourly_count >= 7', $executed[3]);
        self::assertStringContainsString('WHEN event_count >= 7', $executed[4]);
        self::assertStringContainsString('WHERE day_totals.total_count >= 7', $executed[5]);
    }

    public function testTheCoverageViewIsCreatedAfterTheViewsItReads(): void
    {
        self::assertSame('bi_anonymous_coverage_v1', AnonymousBiViewManager::VIEW_NAMES[array_key_last(AnonymousBiViewManager::VIEW_NAMES)]);
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

        self::assertCount(6, $executed);
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

    public function testHourlyAndDailyViewsAlwaysShareOneThreshold(): void
    {
        $executed = [];
        (new AnonymousBiViewManager($this->connection(new PostgreSQLPlatform(), $executed)))->regenerate(11, 40);

        self::assertStringContainsString('event_counts.event_count >= 11', $executed[0]);
        self::assertStringContainsString('THEN event_count ELSE 0 END AS released_hourly_count', $executed[3]);
        self::assertStringContainsString('CASE WHEN event_count >= 11 THEN', $executed[3]);
    }

    public function testConfiguredPageGroupsReachTheDailyViewOnly(): void
    {
        $executed = [];
        $manager = new AnonymousBiViewManager($this->connection(new PostgreSQLPlatform(), $executed), $this->pageGroups([
            ['pattern' => '/inventory/**', 'group' => 'inventory'],
        ]));

        $manager->regenerate(5, 25);

        self::assertStringNotContainsString('inventory', $executed[0]);
        self::assertStringContainsString("THEN 'inventory'", $executed[3]);
    }

    public function testMysqlWithoutTimezoneTablesLeavesTheViewsUntouched(): void
    {
        $directory = sys_get_temp_dir().'/aggregate-tz-mysql-'.bin2hex(random_bytes(4));
        mkdir($directory.'/config', 0700, true);
        file_put_contents($directory.'/config/aggregate.yaml', "reporting_timezone: America/Chicago\n");
        $executed = [];
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQL80Platform());
        $connection->method('fetchOne')->willReturn(null);
        $connection->expects(self::never())->method('executeStatement');

        try {
            (new AnonymousBiViewManager($connection, null, new ReportingTimezone(new AggregateConfigLoader($directory, 'test'))))->regenerate(5, 25);
            self::fail('MySQL regenerated views without time zone tables.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('MySQL cannot convert America/Chicago', $e->getMessage());
            self::assertStringContainsString('mysql_tzinfo_to_sql /usr/share/zoneinfo', $e->getMessage());
            self::assertStringContainsString('app:analytics:mysql-timezones:load', $e->getMessage());
            self::assertStringContainsString('app:analytics:bi-views:regenerate', $e->getMessage());
        }
        self::assertSame([], $executed);
        unlink($directory.'/config/aggregate.yaml');
        rmdir($directory.'/config');
        rmdir($directory);
    }

    public function testInvalidPageGroupsStopRegenerationBeforeAnyStatement(): void
    {
        $executed = [];
        $connection = $this->connection(new PostgreSQLPlatform(), $executed);
        $connection->expects(self::never())->method('beginTransaction');
        $manager = new AnonymousBiViewManager($connection, $this->pageGroups([['pattern' => 'inventory', 'group' => 'x']]));

        try {
            $manager->regenerate(5, 25);
            self::fail('Invalid page groups were accepted.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('bi_page_groups is invalid', $e->getMessage());
        }
        self::assertSame([], $executed);
    }

    public function testWebsiteGroupColumnIsCompiledFromCatalogAndForceDropsViewsInReverseOrder(): void
    {
        $directory = sys_get_temp_dir().'/aggregate-bi-groups-'.bin2hex(random_bytes(4));
        mkdir($directory.'/config', 0700, true);
        file_put_contents($directory.'/config/aggregate.yaml', "custom_data_properties:\n  website_group:\n    column: brand\nquery_parameter_mappings: {}\n");
        file_put_contents($directory.'/config/website-scopes.yaml', "label:\n  singular: Brand\n  plural: Brands\ngroups:\n  amazon:\n    name: Amazon\n    members:\n      - site-a\n");

        $executed = [];
        $connection = $this->connection(new PostgreSQLPlatform(), $executed);
        $config = new AggregateConfigLoader($directory, 'test');
        $customData = new CustomDataSettings($config);
        $catalog = new \App\Service\WebsiteScopeCatalog($directory, 'test');
        $manager = new AnonymousBiViewManager($connection, null, null, null, $customData, $catalog);

        try {
            $manager->regenerate(5, 25);
            self::assertCount(6, $executed);
            foreach ($executed as $statement) {
                self::assertStringContainsString(" = 'site-a' THEN 'Amazon'", $statement);
                self::assertStringContainsString('END AS brand', $statement);
            }

            $forced = [];
            $forceConnection = $this->connection(new PostgreSQLPlatform(), $forced);
            (new AnonymousBiViewManager($forceConnection, null, null, null, $customData, $catalog))->regenerate(5, 25, true);
            self::assertCount(12, $forced);
            self::assertSame('DROP VIEW IF EXISTS bi_anonymous_coverage_v1', $forced[0]);
            self::assertSame('DROP VIEW IF EXISTS bi_anonymous_events_v1', $forced[5]);
            self::assertStringStartsWith('CREATE VIEW bi_anonymous_events_v1 AS', $forced[6]);

            file_put_contents($directory.'/config/website-scopes.yaml', "groups: [invalid\n");
            $catalog->reset();
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Website group configuration is invalid');
            $manager->regenerate(5, 25);
        } finally {
            @unlink($directory.'/config/aggregate.yaml');
            @unlink($directory.'/config/website-scopes.yaml');
            @rmdir($directory.'/config');
            @rmdir($directory);
        }
    }

    /** @param list<array<string, string>> $rules */
    private function pageGroups(array $rules): BiPageGroups
    {
        $config = $this->createMock(AggregateConfigLoader::class);
        $config->method('get')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => $key === BiPageGroups::KEY ? $rules : $default);

        return new BiPageGroups($config);
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
