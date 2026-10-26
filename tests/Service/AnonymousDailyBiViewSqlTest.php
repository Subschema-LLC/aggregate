<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AnonymousBiViewSqlBuilder;
use App\Service\BiPageGroups;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDB1060Platform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AnonymousDailyBiViewSqlTest extends TestCase
{
    private const RULES = [
        ['pattern' => '/inventory/**', 'group' => 'inventory'],
        ['pattern' => '/locations/*', 'group' => 'location'],
        ['pattern' => '/files/*_v1.pdf', 'group' => 'pdf v1'],
        ['pattern' => '/100%/off', 'group' => 'promo'],
    ];

    #[DataProvider('platforms')]
    public function testDailyViewUsesCompletedDaysAndNoIdentifiers(AbstractPlatform $platform, string $completedDay): void
    {
        $sql = (new AnonymousBiViewSqlBuilder($platform))->dailyViewSql(6);

        self::assertStringStartsWith('CREATE VIEW bi_anonymous_daily_v1 AS', $sql);
        self::assertStringContainsString(sprintf($completedDay, 'events.created_at'), $sql);
        self::assertStringContainsString(sprintf($completedDay, 'archived_events.event_hour'), $sql);
        self::assertStringContainsString("events.privacy_mode = 'anonymous'", $sql);
        self::assertStringContainsString('page_path AS page_group', $sql);
        foreach (['visitor_id', 'session_id', 'user_agent', 'ip_address', 'custom_data'] as $identifier) {
            self::assertStringNotContainsString($identifier, $sql);
        }
    }

    public static function platforms(): iterable
    {
        yield 'SQLite' => [new SQLitePlatform(), "%s < date('now')"];
        yield 'MySQL' => [new MySQL80Platform(), '%s < UTC_DATE()'];
        yield 'MariaDB' => [new MariaDB1060Platform(), '%s < UTC_DATE()'];
        yield 'PostgreSQL' => [new PostgreSQLPlatform(), "%s < CAST(CURRENT_TIMESTAMP AT TIME ZONE 'UTC' AS date)"];
        yield 'SQL Server' => [new SQLServerPlatform(), '%s < CAST(SYSUTCDATETIME() AS date)'];
    }

    public function testDailyCellsAreReleasedOnlyWhenTheyCannotRevealAWithheldHourlyCount(): void
    {
        $sql = (new AnonymousBiViewSqlBuilder(new PostgreSQLPlatform()))->dailyViewSql(7);

        self::assertStringContainsString('CASE WHEN event_count >= 7 THEN event_count ELSE 0 END AS released_hourly_count', $sql);
        self::assertStringContainsString('WHERE daily_cells.event_count >= 7', $sql);
        self::assertStringContainsString('daily_cells.event_count = daily_cells.released_hourly_count', $sql);
        self::assertStringContainsString('daily_cells.event_count - daily_cells.released_hourly_count >= 7', $sql);
        // The hourly cells are rebuilt with the hourly view's exact grouping.
        self::assertStringContainsString("GROUP BY\n        website_token,\n        event_hour,\n        event_name,\n        page_path,\n        referrer_channel,\n        device_class,\n        viewport_bucket", $sql);
    }

    /**
     * The daily views release a day only when it cannot reveal an hourly cell
     * the hourly view withholds, which holds only if they rebuild exactly the
     * hourly view's cells: same sources, filters, columns and grouping. Only
     * the period (completed hours or completed days) may differ.
     */
    #[DataProvider('periods')]
    public function testDailyViewsRebuildTheHourlyViewCellsExactly(AbstractPlatform $platform, string $completedHour, string $completedDay): void
    {
        $sql = new AnonymousBiViewSqlBuilder($platform);
        $cells = static function (string $view, string $name, string $period): string {
            $start = strpos($view, "\nWITH combined_event_cells AS (\n");
            $end = strpos($view, "\n)", (int) strpos($view, "\n".$name.' AS ('));
            self::assertIsInt($start);
            self::assertIsInt($end);
            $periods = [sprintf($period, 'archived_events.event_hour'), sprintf($period, 'events.created_at')];
            $cte = str_replace([$name, ...$periods], ['cells', '<period>', '<period>'], substr($view, $start, $end - $start));
            self::assertSame(2, substr_count($cte, '<period>'));

            return $cte;
        };

        $hourly = $cells($sql->eventViewSql(7), 'event_counts', $completedHour);
        self::assertSame($hourly, $cells($sql->dailyViewSql(7), 'hourly_cells', $completedDay));
        self::assertSame($hourly, $cells($sql->rollupViewSql(7), 'hourly_cells', $completedDay));
        self::assertStringEndsWith("\nWHERE event_counts.event_count >= 7", $sql->eventViewSql(7));
        self::assertStringContainsString('CASE WHEN event_count >= 7 THEN event_count ELSE 0 END AS released_hourly_count', $sql->rollupViewSql(7));
    }

    public static function periods(): iterable
    {
        $mysqlHour = "%s < DATE_FORMAT(UTC_TIMESTAMP(), '%%Y-%%m-%%d %%H:00:00')";
        yield 'SQLite' => [new SQLitePlatform(), "%s < strftime('%%Y-%%m-%%d %%H:00:00', 'now')", "%s < date('now')"];
        yield 'MySQL' => [new MySQL80Platform(), $mysqlHour, '%s < UTC_DATE()'];
        yield 'MariaDB' => [new MariaDB1060Platform(), $mysqlHour, '%s < UTC_DATE()'];
        yield 'PostgreSQL' => [new PostgreSQLPlatform(), "%s < date_trunc('hour', CURRENT_TIMESTAMP AT TIME ZONE 'UTC')", "%s < CAST(CURRENT_TIMESTAMP AT TIME ZONE 'UTC' AS date)"];
        yield 'SQL Server' => [new SQLServerPlatform(), '%s < DATEADD(hour, DATEDIFF(hour, 0, SYSUTCDATETIME()), 0)', '%s < CAST(SYSUTCDATETIME() AS date)'];
    }

    public function testPageGroupsCompileToEscapedLikeConditions(): void
    {
        $expression = (new AnonymousBiViewSqlBuilder(new PostgreSQLPlatform()))->pageGroupExpression('page_path', self::RULES);

        self::assertStringContainsString("WHEN (page_path = '/inventory' OR page_path LIKE '/inventory/%' ESCAPE '!') THEN 'inventory'", $expression);
        self::assertStringContainsString("WHEN (page_path LIKE '/locations/%' ESCAPE '!' AND page_path NOT LIKE '/locations/%/%' ESCAPE '!') THEN 'location'", $expression);
        self::assertStringContainsString("page_path LIKE '/files/%!_v1.pdf' ESCAPE '!'", $expression);
        self::assertStringContainsString("WHEN page_path = '/100%/off' THEN 'promo'", $expression);
        self::assertStringEndsWith('ELSE page_path'."\n        END", $expression);
    }

    public function testInvalidRulesNeverReachTheSql(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new AnonymousBiViewSqlBuilder(new PostgreSQLPlatform()))->pageGroupExpression('page_path', [['pattern' => "/x' OR 1=1 --", 'group' => 'x']]);
    }

    /** SQLite runs the generated expression here; the fresh-install job covers the other engines. */
    public function testSqliteGroupsPathsLikeThePhpMatcher(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('PRAGMA case_sensitive_like = ON');
        $expression = (new AnonymousBiViewSqlBuilder($connection->getDatabasePlatform()))->pageGroupExpression(':path', self::RULES);
        $rules = BiPageGroups::validate(self::RULES);

        foreach (['/inventory', '/inventory/', '/inventory/2024-camry', '/inventory/a/b', '/inventoryx', '/locations', '/locations/dallas',
            '/locations/dallas/hours', '/files/a_v1.pdf', '/files/aXv1.pdf', '/files/sub/a_v1.pdf', '/100%/off', '/100x/off', '/about', '/'] as $path) {
            self::assertSame(
                BiPageGroups::groupFor($path, $rules),
                $connection->fetchOne('SELECT '.$expression, ['path' => $path]),
                $path,
            );
        }
    }

    public function testWebsiteGroupColumnCompilesDisplayNamesAndUngroupedFallbackAcrossAllApprovedViews(): void
    {
        $groups = [
            'amazon' => ['name' => 'Amazon', 'members' => ['site-a', 'site-b']],
            'retail' => ['name' => 'Retail', 'members' => ['site-c']],
            'empty' => ['name' => 'Empty', 'members' => []],
        ];
        $postgres = new AnonymousBiViewSqlBuilder(new PostgreSQLPlatform(), 'UTC', 'website_group', $groups);
        $mysql = new AnonymousBiViewSqlBuilder(new MySQL80Platform(), 'UTC', 'brand', $groups);
        $noGroupsPostgres = new AnonymousBiViewSqlBuilder(new PostgreSQLPlatform(), 'UTC', 'website_group', []);

        self::assertStringContainsString("WHEN event_counts.website_token IN ('site-a', 'site-b') THEN 'Amazon'", $postgres->eventViewSql(5));
        self::assertStringContainsString("WHEN event_counts.website_token = 'site-c' THEN 'Retail'", $postgres->eventViewSql(5));
        self::assertStringContainsString("ELSE '(ungrouped)'\n        END AS website_group", $postgres->eventViewSql(5));
        self::assertStringNotContainsString('Empty', $postgres->eventViewSql(5));
        self::assertStringContainsString("ELSE '(ungrouped)'\n        END AS website_group", $postgres->goalViewSql(5));
        self::assertSame(2, substr_count($postgres->geoViewSql(25), 'END AS website_group'));
        self::assertStringContainsString("ELSE '(ungrouped)'\n        END AS website_group", $postgres->dailyViewSql(5));
        self::assertSame(5, substr_count($postgres->rollupViewSql(5), 'END AS website_group'));
        self::assertStringContainsString("ELSE '(ungrouped)'\n        END AS website_group", $postgres->coverageViewSql(5));
        self::assertStringContainsString("THEN 'Amazon' COLLATE utf8mb4_unicode_ci", $mysql->dailyViewSql(5));
        self::assertStringContainsString("ELSE '(ungrouped)' COLLATE utf8mb4_unicode_ci\n        END AS brand", $mysql->dailyViewSql(5));
        self::assertStringContainsString("CAST('(ungrouped)' AS text) AS website_group", $noGroupsPostgres->dailyViewSql(5));
    }

    public function testInvalidWebsiteGroupDefinitionsAreRejectedBeforeEnteringSql(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AnonymousBiViewSqlBuilder(new PostgreSQLPlatform(), 'UTC', 'website_group', [
            'a' => ['name' => 'Brand A', 'members' => ['same-token']],
            'b' => ['name' => 'Brand B', 'members' => ['same-token']],
        ]);
    }

    public function testSqliteExecutesAllApprovedBiViewsWithWebsiteGroupRollup(): void
    {
        $python = (new \Symfony\Component\Process\ExecutableFinder())->find('python3');
        if ($python === null) {
            self::markTestSkipped('Python SQLite is required for SQL execution checks.');
        }
        $builder = new AnonymousBiViewSqlBuilder(new SQLitePlatform(), 'UTC', 'website_group', [
            'amazon' => ['name' => 'Amazon', 'members' => ['site-a', 'site-b']],
        ]);
        $views = [
            $builder->eventViewSql(5),
            $builder->goalViewSql(5),
            $builder->geoViewSql(10),
            $builder->dailyViewSql(5),
            $builder->rollupViewSql(5),
            $builder->coverageViewSql(5),
        ];
        $process = new \Symfony\Component\Process\Process([$python, '-c', <<<'PY'
import datetime, json, sqlite3, sys
db = sqlite3.connect(':memory:')
db.execute('CREATE TABLE events (id INTEGER PRIMARY KEY, website_token TEXT, created_at TEXT, event_name TEXT, url TEXT, referrer TEXT, device_class TEXT, viewport_bucket TEXT, privacy_mode TEXT, archived_at TEXT, goal_event TEXT, geo_area TEXT)')
db.execute('CREATE TABLE analytics_archive_events (website_token TEXT, event_hour TEXT, event_name TEXT, page_path TEXT, referrer_channel TEXT, device_class TEXT, viewport_bucket TEXT, privacy_mode TEXT, event_count INTEGER)')
db.execute('CREATE TABLE analytics_archive_goals (website_token TEXT, event_day TEXT, goal_event TEXT, privacy_mode TEXT, event_count INTEGER)')
db.execute('CREATE TABLE analytics_archive_geo_events (website_token TEXT, event_day TEXT, event_name TEXT, geo_area TEXT, privacy_mode TEXT, event_count INTEGER)')
yesterday = (datetime.datetime.now(datetime.timezone.utc) - datetime.timedelta(days=1)).strftime('%Y-%m-%d')
hour = yesterday + ' 10:00:00'
db.execute("INSERT INTO analytics_archive_events VALUES ('site-a', ?, 'view', '/', 'direct', 'desktop', 'large', 'anonymous', 6)", (hour,))
db.execute("INSERT INTO analytics_archive_events VALUES ('site-b', ?, 'view', '/', 'direct', 'desktop', 'large', 'anonymous', 8)", (hour,))
db.execute("INSERT INTO analytics_archive_events VALUES ('site-c', ?, 'view', '/', 'direct', 'desktop', 'large', 'anonymous', 5)", (hour,))
db.execute("INSERT INTO analytics_archive_events VALUES ('site-small', ?, 'view', '/', 'direct', 'desktop', 'large', 'anonymous', 3)", (hour,))
db.execute("INSERT INTO analytics_archive_goals VALUES ('site-a', ?, 'signup', 'anonymous', 5)", (yesterday,))
db.execute("INSERT INTO analytics_archive_geo_events VALUES ('site-b', ?, 'view', 'country:US', 'anonymous', 12)", (yesterday,))
for sql in json.load(sys.stdin):
    db.executescript(sql)
rollup = db.execute('SELECT website_group, SUM(event_count) FROM bi_anonymous_daily_rollup_v1 GROUP BY website_group ORDER BY website_group').fetchall()
assert rollup == [('(ungrouped)', 5), ('Amazon', 14)], rollup
goals = db.execute('SELECT website_token, website_group, event_count FROM bi_anonymous_goals_v1').fetchall()
assert goals == [('site-a', 'Amazon', 5)], goals
geo = db.execute('SELECT website_token, website_group, event_count FROM bi_anonymous_geo_events_v1').fetchall()
assert geo == [('site-b', 'Amazon', 12)], geo
coverage = db.execute("SELECT DISTINCT website_token, website_group FROM bi_anonymous_coverage_v1 ORDER BY website_token").fetchall()
assert coverage == [('site-a', 'Amazon'), ('site-b', 'Amazon'), ('site-c', '(ungrouped)')], coverage
print('SQLite BI views website_group rollup verified')
PY]);
        $process->setInput(json_encode($views, JSON_THROW_ON_ERROR));
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('SQLite BI views website_group rollup verified', $process->getOutput());
    }
}
