<?php

declare(strict_types=1);

namespace App\Tests\Service\BigQuery;

use App\Service\BigQuery\BigQueryException;
use App\Service\BigQuery\BigQueryViewCatalog;
use App\Service\BigQuery\BigQueryViewExporter;
use App\Service\CustomDataSettings;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BigQueryViewExporterTest extends TestCase
{
    private string $projectDir;
    private Connection $connection;

    protected function setUp(): void
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
        $this->projectDir = sys_get_temp_dir().'/aggregate-bigquery-export-'.bin2hex(random_bytes(6));
        mkdir($this->projectDir, 0700);
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach ([
            'CREATE TABLE events (id INTEGER PRIMARY KEY, website_token VARCHAR(191), event_name VARCHAR(191), url CLOB, created_at DATETIME, archived_at DATETIME, custom_data CLOB)',
            "CREATE VIEW bi_anonymous_events_v1 AS SELECT website_token, strftime('%Y-%m-%d %H:00:00', created_at) AS event_hour, event_name, url AS page_path, COUNT(*) AS event_count FROM events GROUP BY 1, 2, 3, 4",
            "CREATE VIEW bi_glossary_values_v1 AS SELECT 'device_class' AS dimension, 'mobile' AS code, 0 AS is_fallback, 10 AS sort_order, 1 AS is_default_locale",
            "CREATE VIEW analytics_custom_events_v1 AS SELECT id, website_token, event_name, url AS page_path, created_at, archived_at, json_extract(custom_data, '$.plan') AS plan_name, CAST(json_extract(custom_data, '$.quantity') AS INTEGER) AS quantity_value, CAST(json_extract(custom_data, '$.price') AS REAL) AS price_value FROM events",
            "CREATE VIEW bi_anonymous_goals_v1 AS SELECT 'tok' AS website_token, 'not-a-date' AS event_day, 'signup' AS goal_event, 3 AS event_count",
        ] as $sql) {
            $this->connection->executeStatement($sql);
        }
        $insert = 'INSERT INTO events (website_token, event_name, url, created_at, archived_at, custom_data) VALUES (?, ?, ?, ?, ?, ?)';
        $this->connection->executeStatement($insert, ['tok', 'view', '/a', '2026-10-01 10:12:00', null, '{"plan":"pro","quantity":2,"price":12.5}']);
        $this->connection->executeStatement($insert, ['tok', 'view', '/a', '2026-10-01 10:40:00', '2026-10-05 00:00:00', '{"plan":"Grüße ☃"}']);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->projectDir.'/var/bigquery/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->projectDir.'/var/bigquery');
        @rmdir($this->projectDir.'/var');
        @rmdir($this->projectDir);
    }

    public function testViewsAreWrittenAsTypedNewlineDelimitedJson(): void
    {
        $exporter = $this->exporter();

        $events = $exporter->export('bi_anonymous_events_v1');
        self::assertSame(1, $events['rows']);
        self::assertSame([
            ['name' => 'website_token', 'type' => 'STRING'],
            ['name' => 'event_hour', 'type' => 'TIMESTAMP'],
            ['name' => 'event_name', 'type' => 'STRING'],
            ['name' => 'page_path', 'type' => 'STRING'],
            ['name' => 'event_count', 'type' => 'INT64'],
        ], $events['fields']);
        self::assertSame('{"website_token":"tok","event_hour":"2026-10-01 10:00:00.000000","event_name":"view","page_path":"/a","event_count":"2"}'."\n", file_get_contents($events['file']));
        self::assertSame(0600, fileperms($events['file']) & 0777);
        self::assertStringStartsWith($this->projectDir.'/var/bigquery/export-bi_anonymous_events_v1-', $events['file']);
        self::assertSame(filesize($events['file']), $events['bytes']);

        $glossary = $exporter->export('bi_glossary_values_v1');
        self::assertSame(['dimension' => 'device_class', 'code' => 'mobile', 'is_fallback' => false, 'sort_order' => '10', 'is_default_locale' => true], json_decode((string) file_get_contents($glossary['file']), true));

        $custom = $exporter->export('analytics_custom_events_v1');
        self::assertSame(['INT64', 'STRING', 'STRING', 'STRING', 'TIMESTAMP', 'TIMESTAMP', 'STRING', 'INT64', 'FLOAT64'], array_column($custom['fields'], 'type'));
        $lines = array_map(static fn (string $line): array => json_decode($line, true), file($custom['file'], FILE_IGNORE_NEW_LINES));
        self::assertSame(['id' => '1', 'website_token' => 'tok', 'event_name' => 'view', 'page_path' => '/a', 'created_at' => '2026-10-01 10:12:00.000000', 'archived_at' => null, 'plan_name' => 'pro', 'quantity_value' => '2', 'price_value' => 12.5], $lines[0]);
        self::assertSame('Grüße ☃', $lines[1]['plan_name']);
        self::assertSame('2026-10-05 00:00:00.000000', $lines[1]['archived_at']);
        self::assertNull($lines[1]['quantity_value']);
    }

    public function testAValueThatDoesNotFitItsTypeStopsTheViewWithoutRevealingIt(): void
    {
        try {
            $this->exporter()->export('bi_anonymous_goals_v1');
            self::fail('An invalid DATE was exported.');
        } catch (BigQueryException $e) {
            self::assertSame('The view bi_anonymous_goals_v1 has a value in event_day that is not a valid DATE. Nothing was uploaded for this view.', $e->getMessage());
        }
        self::assertSame([], glob($this->projectDir.'/var/bigquery/export-*') ?: []);
    }

    public function testOnlyCatalogViewsThatExistCanBeExported(): void
    {
        try {
            $this->exporter()->export('events');
            self::fail('A raw table was exported.');
        } catch (BigQueryException $e) {
            self::assertStringContainsString('not a reporting view', $e->getMessage());
        }
        try {
            $this->exporter()->export('bi_anonymous_geo_events_v1');
            self::fail('A missing view was exported.');
        } catch (BigQueryException $e) {
            self::assertStringContainsString('needs optional geography', $e->getMessage());
        }
    }

    public function testExportsLeftByAnInterruptedRunAreRemovedAfterADay(): void
    {
        mkdir($this->projectDir.'/var/bigquery', 0700, true);
        file_put_contents($stale = $this->projectDir.'/var/bigquery/export-old.ndjson', 'x');
        touch($stale, time() - 90000);
        file_put_contents($recent = $this->projectDir.'/var/bigquery/export-recent.ndjson', 'x');

        $this->exporter()->export('bi_glossary_values_v1');

        self::assertFileDoesNotExist($stale);
        self::assertFileExists($recent);
    }

    #[DataProvider('conversions')]
    public function testDatabaseValuesFromEveryEngineConvertToBigQueryJson(mixed $value, string $type, mixed $expected): void
    {
        self::assertSame($expected, BigQueryViewExporter::convert($value, $type, 'v', 'c'));
    }

    public static function conversions(): iterable
    {
        yield 'null stays null' => [null, 'INT64', null];
        yield 'integer' => [7, 'INT64', '7'];
        yield 'numeric text' => ['00042', 'INT64', '42'];
        yield 'PostgreSQL SUM numeric' => ['12.000', 'INT64', '12'];
        yield 'negative zero' => ['-0', 'INT64', '0'];
        yield 'largest integer' => ['9223372036854775807', 'INT64', '9223372036854775807'];
        yield 'integral float' => [3.0, 'INT64', '3'];
        yield 'float' => [12.5, 'FLOAT64', 12.5];
        yield 'float text' => ['1.5e3', 'FLOAT64', 1500.0];
        yield 'integer as float' => [2, 'FLOAT64', 2.0];
        yield 'boolean' => [true, 'BOOL', true];
        yield 'PostgreSQL true' => ['t', 'BOOL', true];
        yield 'SQLite false' => [0, 'BOOL', false];
        yield 'text false' => ['0', 'BOOL', false];
        yield 'date' => ['2026-10-01', 'DATE', '2026-10-01'];
        yield 'SQL Server midnight' => ['2026-10-01 00:00:00.0000000', 'DATE', '2026-10-01'];
        yield 'timestamp' => ['2026-10-01 10:00:00', 'TIMESTAMP', '2026-10-01 10:00:00.000000'];
        yield 'SQL Server datetime2' => ['2026-10-01 10:00:00.1234567', 'TIMESTAMP', '2026-10-01 10:00:00.123456'];
        yield 'ISO with Z' => ['2026-10-01T10:00:00Z', 'TIMESTAMP', '2026-10-01 10:00:00.000000'];
        yield 'PostgreSQL offset' => ['2026-10-01 12:00:00+02', 'TIMESTAMP', '2026-10-01 10:00:00.000000'];
        yield 'minutes only' => ['2026-10-01 10:30', 'TIMESTAMP', '2026-10-01 10:30:00.000000'];
        yield 'date object' => [new \DateTimeImmutable('2026-10-01 05:00:00', new \DateTimeZone('America/Chicago')), 'TIMESTAMP', '2026-10-01 10:00:00.000000'];
        yield 'string' => ['café', 'STRING', 'café'];
        yield 'number as string' => [5, 'STRING', '5'];
        yield 'boolean as string' => [false, 'STRING', 'false'];
    }

    #[DataProvider('invalidConversions')]
    public function testValuesThatDoNotFitFailClosed(mixed $value, string $type): void
    {
        $this->expectException(BigQueryException::class);
        BigQueryViewExporter::convert($value, $type, 'v', 'c');
    }

    public static function invalidConversions(): iterable
    {
        yield 'fraction as integer' => ['1.5', 'INT64'];
        yield 'text as integer' => ['abc', 'INT64'];
        yield 'too many digits' => ['12345678901234567890', 'INT64'];
        yield 'infinite float' => [INF, 'FLOAT64'];
        yield 'text as float' => ['abc', 'FLOAT64'];
        yield 'yes as boolean' => ['yes', 'BOOL'];
        yield 'two as boolean' => [2, 'BOOL'];
        yield 'date with time' => ['2026-10-01 10:00:00', 'DATE'];
        yield 'bad timestamp' => ['yesterday', 'TIMESTAMP'];
        yield 'impossible date' => ['2026-02-30 99:00:00', 'TIMESTAMP'];
        yield 'array' => [[1], 'STRING'];
    }

    private function exporter(): BigQueryViewExporter
    {
        $customData = $this->createStub(CustomDataSettings::class);
        $customData->method('numericReportingColumns')->willReturn([
            'quantity_value' => ['property' => 'quantity', 'type' => 'integer'],
            'price_value' => ['property' => 'price', 'type' => 'double'],
        ]);

        return new BigQueryViewExporter($this->connection, new BigQueryViewCatalog($customData), $this->projectDir);
    }
}
