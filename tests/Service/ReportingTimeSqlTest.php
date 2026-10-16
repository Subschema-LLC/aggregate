<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AnonymousBiViewSqlBuilder;
use App\Service\ReportingTimeSql;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use PHPUnit\Framework\TestCase;

final class ReportingTimeSqlTest extends TestCase
{
    public function testUtcLeavesEveryEventViewStatementUnchanged(): void
    {
        foreach ([new PostgreSQLPlatform(), new MySQL80Platform(), new SQLitePlatform(), new SQLServerPlatform()] as $platform) {
            $plain = new AnonymousBiViewSqlBuilder($platform);
            $utc = new AnonymousBiViewSqlBuilder($platform, 'UTC');
            self::assertSame($plain->eventViewSql(5), $utc->eventViewSql(5));
            self::assertSame($plain->dailyViewSql(5), $utc->dailyViewSql(5));
            self::assertSame($plain->coverageViewSql(5), $utc->coverageViewSql(5));
            self::assertSame($plain->goalViewSql(5), $utc->goalViewSql(5));
            self::assertSame($plain->geoViewSql(25), $utc->geoViewSql(25));
        }
    }

    public function testAPermanentUtcZoneAlsoLeavesTheSqlUnchanged(): void
    {
        $plain = new AnonymousBiViewSqlBuilder(new PostgreSQLPlatform());
        $abidjan = new AnonymousBiViewSqlBuilder(new PostgreSQLPlatform(), 'Africa/Abidjan');

        self::assertSame($plain->eventViewSql(5), $abidjan->eventViewSql(5));
        self::assertSame($plain->dailyViewSql(5), $abidjan->dailyViewSql(5));
    }

    public function testTheHourlyViewKeepsOneCellPerStoredHourAndGoalsStayUtc(): void
    {
        $sql = new AnonymousBiViewSqlBuilder(new PostgreSQLPlatform(), 'America/Chicago');
        $events = $sql->eventViewSql(5);
        $goals = $sql->goalViewSql(5);
        $geo = $sql->geoViewSql(25);

        self::assertStringContainsString('archived_events.event_hour AS utc_hour', $events);
        self::assertStringContainsString('utc_hour,', $events);
        self::assertStringContainsString("AT TIME ZONE 'America/Chicago'", $events);
        self::assertStringContainsString("INTERVAL '30 minutes'", $events);
        self::assertStringContainsString("date_trunc('hour', CURRENT_TIMESTAMP AT TIME ZONE 'UTC')", $events);
        self::assertStringNotContainsString("AT TIME ZONE 'America/Chicago'", $goals);
        self::assertStringNotContainsString("AT TIME ZONE 'America/Chicago'", $geo);
        self::assertStringContainsString("CURRENT_TIMESTAMP AT TIME ZONE 'UTC'", $goals);
    }

    public function testDailyAndCoverageViewsUseTheSameShiftAndDoNotShiftAnAlreadyLocalHour(): void
    {
        $sql = new AnonymousBiViewSqlBuilder(new PostgreSQLPlatform(), 'Asia/Tokyo');
        $daily = $sql->dailyViewSql(5);
        $coverage = $sql->coverageViewSql(5);

        self::assertStringContainsString("AT TIME ZONE 'Asia/Tokyo'", $daily);
        self::assertStringContainsString("AT TIME ZONE 'Asia/Tokyo'", $coverage);
        self::assertStringContainsString("CAST(date_trunc('hour'", $daily);
        self::assertStringNotContainsString("AT TIME ZONE 'Asia/Tokyo'", $sql->goalViewSql(5));
        self::assertStringContainsString('CAST(bi_anonymous_events_v1.event_hour AS date)', $coverage);
        self::assertStringContainsString("bi_anonymous_events_v1.event_hour < date_trunc('day', CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Tokyo')", $coverage);
        self::assertStringNotContainsString("((bi_anonymous_events_v1.event_hour + INTERVAL '30 minutes')", $coverage);
    }

    public function testEachEngineQuotesTheZoneAndSqlServerUsesTheWindowsName(): void
    {
        self::assertStringContainsString(
            "CONVERT_TZ(DATE_ADD(events.created_at, INTERVAL 30 MINUTE), 'UTC', 'America/Chicago')",
            (new AnonymousBiViewSqlBuilder(new MySQL80Platform(), 'America/Chicago'))->eventViewSql(5),
        );
        self::assertStringContainsString(
            "AT TIME ZONE 'Central Standard Time'",
            (new AnonymousBiViewSqlBuilder(new SQLServerPlatform(), 'America/Chicago'))->eventViewSql(5),
        );
        self::assertStringContainsString(
            '+30 minutes',
            (new AnonymousBiViewSqlBuilder(new SQLitePlatform(), 'America/Chicago'))->eventViewSql(5),
        );
    }

    public function testSqlServerRefusesAnUnmappedNameBeforeEmittingAGuess(): void
    {
        $this->expectExceptionMessage('no Windows time zone');
        (new AnonymousBiViewSqlBuilder(new SQLServerPlatform(), 'Antarctica/Troll'))->eventViewSql(5);
    }

    public function testSqliteLabelsAndCutoffsMatchPhp(): void
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not installed.');
        }

        $pdo = new \PDO('sqlite::memory:');
        $zones = ['America/Chicago', 'Asia/Kolkata', 'Asia/Kathmandu', 'America/St_Johns', 'Australia/Lord_Howe', 'Pacific/Auckland'];
        $hours = [
            '2024-01-15 06:00:00',
            '2024-03-10 07:00:00',
            '2024-03-10 08:00:00',
            '2024-07-15 05:00:00',
            '2024-11-03 06:00:00',
            '2024-11-03 07:00:00',
            '2026-10-16 12:00:00',
        ];
        foreach ($zones as $zone) {
            $time = new ReportingTimeSql(new SQLitePlatform(), $zone);
            // The label repeats the column once per transition, so bind the hour once.
            $label = $time->hourLabel('utc_hour');
            $statement = $pdo->prepare('SELECT '.$label.' FROM (SELECT ? AS utc_hour)');
            foreach ($hours as $hour) {
                $statement->execute([$hour]);
                self::assertSame(self::phpHourLabel($hour, $zone), $statement->fetchColumn(), $zone.' '.$hour);
            }

            $cutoff = $time->completedReportingDayPredicate('column');
            foreach (['2024-03-10 06:30:00', '2024-07-15 04:30:00', '2024-11-03 05:30:00', '2026-01-01 00:30:00', '2026-10-16 18:45:00'] as $now) {
                $sql = preg_replace('/^column < /', '', str_replace("'now'", $pdo->quote($now), $cutoff));
                $actual = $pdo->query('SELECT '.$sql)->fetchColumn();
                self::assertSame(self::phpCutoff($now, $zone), $actual, $zone.' cutoff '.$now);
            }
        }
    }

    private static function phpHourLabel(string $utcHour, string $zone): string
    {
        $local = (new \DateTimeImmutable($utcHour, new \DateTimeZone('UTC')))
            ->modify('+30 minutes')
            ->setTimezone(new \DateTimeZone($zone));

        return $local->setTime((int) $local->format('G'), 0, 0)->format('Y-m-d H:i:s');
    }

    private static function phpCutoff(string $nowUtc, string $zone): string
    {
        $localMidnight = (new \DateTimeImmutable($nowUtc, new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone($zone))
            ->setTime(0, 0, 0);
        $shifted = $localMidnight->setTimezone(new \DateTimeZone('UTC'))->modify('-30 minutes');
        if ($shifted->format('is') !== '0000') {
            $shifted = $shifted->modify('+1 hour');
            $shifted = $shifted->setTime((int) $shifted->format('G'), 0, 0);
        }

        return $shifted->format('Y-m-d H:i:s');
    }
}
