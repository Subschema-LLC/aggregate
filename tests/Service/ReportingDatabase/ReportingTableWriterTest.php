<?php

declare(strict_types=1);

namespace App\Tests\Service\ReportingDatabase;

use App\Service\ReportingDatabase\ReportingDatabaseException;
use App\Service\ReportingDatabase\ReportingTableWriter;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

/**
 * The writer on SQLite. The integration test (tests/Integration/fresh-install.php)
 * runs the same sync on MySQL, PostgreSQL and SQL Server, where the swap uses
 * each engine's own statements.
 */
final class ReportingTableWriterTest extends TestCase
{
    private const FIELDS = [
        ['name' => 'website_token', 'type' => 'STRING'],
        ['name' => 'event_hour', 'type' => 'TIMESTAMP'],
        ['name' => 'event_day', 'type' => 'DATE'],
        ['name' => 'event_count', 'type' => 'INT64'],
        ['name' => 'price_value', 'type' => 'FLOAT64'],
        ['name' => 'is_fallback', 'type' => 'BOOL'],
        // A reserved word as a column name is quoted.
        ['name' => 'order', 'type' => 'STRING'],
    ];

    private Connection $reporting;

    protected function setUp(): void
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
        $this->reporting = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    }

    public function testATableIsCreatedWithTypedValues(): void
    {
        $rows = (new ReportingTableWriter($this->reporting))->replace('bi_anonymous_events_v1', self::FIELDS, [
            ['tok', '2026-10-01 10:00:00', '2026-10-01', '12', '12.5', 1, 'Grüße ☃'],
            ['tok', '2026-10-01T11:00:00Z', '2026-10-01 00:00:00', 3, 2 / 7, 'f', null],
            [null, null, null, null, null, null, null],
        ]);

        self::assertSame(3, $rows);
        self::assertSame([
            ['website_token' => 'tok', 'event_hour' => '2026-10-01 10:00:00', 'event_day' => '2026-10-01', 'event_count' => 12, 'price_value' => 12.5, 'is_fallback' => 1, 'order' => 'Grüße ☃'],
            ['website_token' => 'tok', 'event_hour' => '2026-10-01 11:00:00', 'event_day' => '2026-10-01', 'event_count' => 3, 'price_value' => 2 / 7, 'is_fallback' => 0, 'order' => null],
            ['website_token' => null, 'event_hour' => null, 'event_day' => null, 'event_count' => null, 'price_value' => null, 'is_fallback' => null, 'order' => null],
        ], $this->reporting->fetchAllAssociative('SELECT * FROM bi_anonymous_events_v1 ORDER BY event_count DESC'));
        self::assertSame(['bi_anonymous_events_v1'], $this->tables());
    }

    public function testASyncReplacesEveryRowAndAdoptsNewColumns(): void
    {
        $writer = new ReportingTableWriter($this->reporting);
        $writer->replace('analytics_custom_events_v1', [['name' => 'id', 'type' => 'INT64']], [[1], [2], [3]]);

        $rows = $writer->replace('analytics_custom_events_v1', [['name' => 'id', 'type' => 'INT64'], ['name' => 'plan_name', 'type' => 'STRING']], [[3, 'pro']]);

        self::assertSame(1, $rows);
        self::assertSame([['id' => 3, 'plan_name' => 'pro']], $this->reporting->fetchAllAssociative('SELECT * FROM analytics_custom_events_v1'));
        self::assertSame(['analytics_custom_events_v1'], $this->tables());
    }

    public function testAnEmptyViewLeavesAnEmptyTable(): void
    {
        $writer = new ReportingTableWriter($this->reporting);
        $writer->replace('bi_dim_device_class_v1', [['name' => 'code', 'type' => 'STRING']], [['mobile']]);

        self::assertSame(0, $writer->replace('bi_dim_device_class_v1', [['name' => 'code', 'type' => 'STRING']], []));
        self::assertSame(0, (int) $this->reporting->fetchOne('SELECT COUNT(*) FROM bi_dim_device_class_v1'));
    }

    public function testRowsAreWrittenInBatchesAcrossTransactions(): void
    {
        $count = 23456;
        $generate = static function () use ($count): \Generator {
            for ($i = 1; $i <= $count; ++$i) {
                yield ['tok', '2026-10-01 10:00:00', '2026-10-01', $i, $i / 4, $i % 2, 'row '.$i];
            }
        };

        self::assertSame($count, (new ReportingTableWriter($this->reporting))->replace('bi_anonymous_events_v1', self::FIELDS, $generate()));
        self::assertSame(['n' => $count, 'total' => $count * ($count + 1) / 2, 'last' => 'row '.$count], array_map(
            static fn (mixed $value): mixed => is_numeric($value) && !is_string($value) ? (int) $value : $value,
            $this->reporting->fetchAssociative('SELECT COUNT(*) AS n, SUM(event_count) AS total, MAX(CASE WHEN event_count = '.$count.' THEN "order" END) AS last FROM bi_anonymous_events_v1'),
        ));
        self::assertFalse($this->reporting->isTransactionActive());
    }

    public function testAnInvalidValueKeepsThePreviousCopyAndNamesOnlyTheColumn(): void
    {
        $writer = new ReportingTableWriter($this->reporting);
        $writer->replace('bi_anonymous_goals_v1', [['name' => 'event_day', 'type' => 'DATE'], ['name' => 'event_count', 'type' => 'INT64']], [['2026-10-01', 4]]);

        try {
            $writer->replace('bi_anonymous_goals_v1', [['name' => 'event_day', 'type' => 'DATE'], ['name' => 'event_count', 'type' => 'INT64']], [
                ['2026-10-02', 5],
                ['private-looking value', 6],
            ]);
            self::fail('An invalid date was copied.');
        } catch (ReportingDatabaseException $e) {
            self::assertSame('The view bi_anonymous_goals_v1 has a value in event_day that is not a valid DATE; the table was not replaced.', $e->getMessage());
            self::assertStringNotContainsString('private-looking', $e->getMessage());
        }

        self::assertSame([['event_day' => '2026-10-01', 'event_count' => 4]], $this->reporting->fetchAllAssociative('SELECT * FROM bi_anonymous_goals_v1'));
        self::assertSame(['bi_anonymous_goals_v1'], $this->tables(), 'The staging table was dropped.');
        self::assertFalse($this->reporting->isTransactionActive());
    }

    public function testAnIntegerBeyondSixtyFourBitsIsRefused(): void
    {
        $this->expectException(ReportingDatabaseException::class);
        $this->expectExceptionMessage('a value in event_count that is not a valid INT64');

        (new ReportingTableWriter($this->reporting))->replace('bi_anonymous_events_v1', [['name' => 'event_count', 'type' => 'INT64']], [['9999999999999999999']]);
    }

    public function testALeftoverStagingTableFromAnInterruptedSyncIsReplaced(): void
    {
        $this->reporting->executeStatement('CREATE TABLE bi_dim_event_name_v1_sync_new (stale INTEGER)');
        $this->reporting->executeStatement('INSERT INTO bi_dim_event_name_v1_sync_new VALUES (1)');

        (new ReportingTableWriter($this->reporting))->replace('bi_dim_event_name_v1', [['name' => 'code', 'type' => 'STRING']], [['view']]);

        self::assertSame([['code' => 'view']], $this->reporting->fetchAllAssociative('SELECT * FROM bi_dim_event_name_v1'));
        self::assertSame(['bi_dim_event_name_v1'], $this->tables());
    }

    /** @return list<string> */
    private function tables(): array
    {
        return array_map('strval', $this->reporting->fetchFirstColumn("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name"));
    }
}
