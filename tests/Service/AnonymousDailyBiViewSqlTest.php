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
}
