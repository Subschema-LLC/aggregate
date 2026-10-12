<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AnonymousBiViewManager;
use App\Service\AnonymousBiViewSqlBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs the roll-up and coverage views on SQLite with a small, hand-checked
 * day of traffic. The fresh-install job runs the same scenario on every engine.
 */
final class AnonymousRollupCoverageViewsTest extends TestCase
{
    private Connection $db;
    private string $yesterday;

    protected function setUp(): void
    {
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->db->executeStatement('CREATE TABLE events (id INTEGER PRIMARY KEY, website_token TEXT, created_at TEXT, event_name TEXT, url TEXT, referrer TEXT, device_class TEXT, viewport_bucket TEXT, privacy_mode TEXT, archived_at TEXT, goal_event TEXT, geo_area TEXT)');
        $this->db->executeStatement('CREATE TABLE analytics_archive_events (website_token TEXT, event_hour TEXT, event_name TEXT, page_path TEXT, referrer_channel TEXT, device_class TEXT, viewport_bucket TEXT, privacy_mode TEXT, event_count INTEGER)');
        $this->db->executeStatement('CREATE TABLE analytics_archive_goals (website_token TEXT, event_day TEXT, goal_event TEXT, privacy_mode TEXT, event_count INTEGER)');
        $this->db->executeStatement('CREATE TABLE analytics_archive_geo_events (website_token TEXT, event_day TEXT, event_name TEXT, geo_area TEXT, privacy_mode TEXT, event_count INTEGER)');
        (new AnonymousBiViewManager($this->db))->regenerate(6, 25);
        $this->yesterday = gmdate('Y-m-d', time() - 86400);

        // Too small on their own; rolled up by page, then by channel.
        $this->send('pool_check', '/pool/a', 'search', 'desktop', 3, 9);
        $this->send('pool_check', '/pool/a', 'social', 'desktop', 3, 9);
        $this->send('pool_check', '/pool/b', 'search', 'desktop', 2, 9);
        $this->send('pool_check', '/pool/c', 'search', 'mobile', 2, 9);
        $this->send('pool_check', '/pool/d', 'search', 'tablet', 2, 9);
        $this->send('pool_check', '/pool/e', 'social', 'mobile', 1, 9);
        // Released in full.
        $this->send('view', '/full', 'search', 'desktop', 6, 10);
        // 7 released hourly and 2 withheld: no row may reveal the 2.
        $this->send('view', '/guard', 'search', 'desktop', 7, 11);
        $this->send('view', '/guard', 'search', 'desktop', 2, 12);
        // A day below the threshold has no coverage rows; today is not reported.
        $this->send('view', '/tiny', 'search', 'desktop', 3, 9, 'tiny-site');
        $this->db->insert('events', ['website_token' => 'site', 'created_at' => gmdate('Y-m-d 00:00:00'), 'event_name' => 'view', 'url' => '/today', 'referrer' => 'search', 'device_class' => 'desktop', 'viewport_bucket' => 'large', 'privacy_mode' => 'anonymous']);
    }

    public function testWithheldCellsRollUpIntoCoarserRowsThatAddUp(): void
    {
        $rows = $this->db->fetchAllAssociative('SELECT event_name, page_group, referrer_channel, device_class, detail_level, event_count FROM bi_anonymous_daily_rollup_v1 WHERE website_token = ? ORDER BY event_name, detail_level, page_group, referrer_channel', ['site']);

        $rows = array_map(static function (array $row): array {
            $row['event_count'] = (int) $row['event_count'];

            return $row;
        }, $rows);

        self::assertSame([
            ['event_name' => 'pool_check', 'page_group' => '(other)', 'referrer_channel' => 'search', 'device_class' => '(other)', 'detail_level' => 'channel', 'event_count' => 6],
            ['event_name' => 'pool_check', 'page_group' => '/pool/a', 'referrer_channel' => '(other)', 'device_class' => '(other)', 'detail_level' => 'page', 'event_count' => 6],
            ['event_name' => 'view', 'page_group' => '/full', 'referrer_channel' => 'search', 'device_class' => 'desktop', 'detail_level' => 'page_channel_device', 'event_count' => 6],
        ], $rows);
        // The single /pool/e event stays below the threshold and is left out.
        self::assertSame(18, array_sum(array_column($rows, 'event_count')));
    }

    public function testFullDetailRowsAreExactlyTheDailyView(): void
    {
        $full = $this->db->fetchAllAssociative("SELECT website_token, event_day, event_name, page_group, referrer_channel, device_class, event_count FROM bi_anonymous_daily_rollup_v1 WHERE detail_level = 'page_channel_device' ORDER BY page_group");
        $daily = $this->db->fetchAllAssociative('SELECT website_token, event_day, event_name, page_group, referrer_channel, device_class, event_count FROM bi_anonymous_daily_v1 ORDER BY page_group');

        self::assertSame($daily, $full);
    }

    public function testNoRowRevealsAWithheldHourlyCount(): void
    {
        self::assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM bi_anonymous_events_v1 WHERE page_path = '/guard'"));
        self::assertSame(0, (int) $this->db->fetchOne("SELECT COUNT(*) FROM bi_anonymous_daily_rollup_v1 WHERE event_name = 'view' AND page_group <> '/full'"));
    }

    public function testCoverageBandsDescribeEachViewWithoutTotals(): void
    {
        $bands = $this->db->fetchAllKeyValue('SELECT view_name, coverage_band FROM bi_anonymous_coverage_v1 WHERE website_token = ? AND event_day = ?', ['site', $this->yesterday]);

        // 28 events: hourly releases 13, daily 6, roll-up 18.
        self::assertSame(['bi_anonymous_daily_rollup_v1' => '50-90', 'bi_anonymous_daily_v1' => '10-50', 'bi_anonymous_events_v1' => '10-50'], self::sorted($bands));
        self::assertSame(0, (int) $this->db->fetchOne("SELECT COUNT(*) FROM bi_anonymous_coverage_v1 WHERE website_token = 'tiny-site'"));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(DISTINCT event_day) FROM bi_anonymous_coverage_v1'));
        self::assertSame(['website_token', 'event_day', 'view_name', 'coverage_band'], array_keys($this->db->fetchAssociative('SELECT * FROM bi_anonymous_coverage_v1')));
    }

    #[DataProvider('platforms')]
    public function testRollupAndCoverageSqlOnEveryPlatform(AbstractPlatform $platform): void
    {
        $sql = new AnonymousBiViewSqlBuilder($platform);
        $rollup = $sql->rollupViewSql(8, [['pattern' => '/inventory/**', 'group' => 'inventory']]);
        $coverage = $sql->coverageViewSql(8);

        self::assertStringStartsWith('CREATE VIEW bi_anonymous_daily_rollup_v1 AS', $rollup);
        self::assertSame(4, substr_count($rollup, 'SUM(event_count) OVER (PARTITION BY'));
        self::assertSame(8, substr_count($rollup, 'SUM(withheld_hourly_count) OVER (PARTITION BY'));
        self::assertSame(5, substr_count($rollup, 'WHERE is_released = 1'));
        self::assertStringContainsString("THEN 'inventory'", $rollup);
        self::assertStringContainsString("'(other)' AS page_group", $rollup);
        self::assertStringStartsWith('CREATE VIEW bi_anonymous_coverage_v1 AS', $coverage);
        self::assertStringContainsString('FROM bi_anonymous_daily_rollup_v1', $coverage);
        self::assertStringContainsString('WHERE day_totals.total_count >= 8', $coverage);
        foreach ([$rollup, $coverage] as $view) {
            foreach (['visitor_id', 'session_id', 'custom_data', 'user_agent'] as $identifier) {
                self::assertStringNotContainsString($identifier, $view);
            }
        }
    }

    /** MySQL and MariaDB store view literals with a collation a client may not share. */
    public function testLiteralOnlyColumnsCarryAnExplicitCollationOnMysqlOnly(): void
    {
        $mysql = new AnonymousBiViewSqlBuilder(new MySQL80Platform());
        $postgres = new AnonymousBiViewSqlBuilder(new PostgreSQLPlatform());

        self::assertStringContainsString("'page' COLLATE utf8mb4_unicode_ci AS detail_level", $mysql->rollupViewSql(5));
        self::assertStringContainsString("'50-90' COLLATE utf8mb4_unicode_ci", $mysql->coverageViewSql(5));
        self::assertStringContainsString("'page' AS detail_level", $postgres->rollupViewSql(5));
        self::assertStringNotContainsString('COLLATE', $postgres->coverageViewSql(5));
        // The coverage view joins on numbers, never on two literals.
        self::assertStringContainsString('released.view_id = view_names.view_id', $mysql->coverageViewSql(5));
    }

    /** MariaDB mis-evaluates several window functions combined in one expression. */
    public function testWindowSumsAreComparedInASeparateStep(): void
    {
        $rollup = (new AnonymousBiViewSqlBuilder(new MySQL80Platform()))->rollupViewSql(5);

        self::assertSame(1, preg_match_all('/^level_page_sums AS \(/m', $rollup));
        self::assertSame(0, preg_match('/CASE\s+WHEN[^\n]*OVER/', $rollup));
    }

    public static function platforms(): iterable
    {
        yield 'SQLite' => [new SQLitePlatform()];
        yield 'MySQL' => [new MySQL80Platform()];
        yield 'PostgreSQL' => [new PostgreSQLPlatform()];
        yield 'SQL Server' => [new SQLServerPlatform()];
    }

    private function send(string $event, string $path, string $channel, string $device, int $count, int $hour, string $site = 'site'): void
    {
        for ($i = 0; $i < $count; ++$i) {
            $this->db->insert('events', [
                'website_token' => $site,
                'created_at' => sprintf('%s %02d:00:00', $this->yesterday, $hour),
                'event_name' => $event,
                'url' => $path,
                'referrer' => $channel,
                'device_class' => $device,
                'viewport_bucket' => 'large',
                'privacy_mode' => 'anonymous',
            ]);
        }
    }

    /** @param array<string, string> $bands */
    private static function sorted(array $bands): array
    {
        ksort($bands);

        return $bands;
    }
}
