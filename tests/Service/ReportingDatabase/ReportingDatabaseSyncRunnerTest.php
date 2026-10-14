<?php

declare(strict_types=1);

namespace App\Tests\Service\ReportingDatabase;

use App\Service\AggregateConfigLoader;
use App\Service\BigQuery\BigQueryViewCatalog;
use App\Service\BigQuery\BigQueryViewExporter;
use App\Service\CustomDataSettings;
use App\Service\Operations\AuditTrail;
use App\Service\Operations\ProcessingTasks;
use App\Service\Operations\TaskTrigger;
use App\Service\ReportingDatabase\ReportingDatabaseConnection;
use App\Service\ReportingDatabase\ReportingDatabaseException;
use App\Service\ReportingDatabase\ReportingDatabaseSettings;
use App\Service\ReportingDatabase\ReportingDatabaseSyncRunner;
use App\Tests\Service\Operations\OperationsDatabase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Symfony\Component\Yaml\Yaml;

/** The whole sync with SQLite on both sides: views in, same-named tables out. */
final class ReportingDatabaseSyncRunnerTest extends TestCase
{
    private string $projectDir;
    private Connection $application;
    private \DateTimeImmutable $now;
    private array $environment;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        foreach (array_keys(ReportingDatabaseSettings::defaults()) as $key) {
            unset($_ENV[strtoupper($key)], $_SERVER[strtoupper($key)]);
        }
        $this->projectDir = sys_get_temp_dir().'/aggregate-reporting-runner-'.bin2hex(random_bytes(6));
        mkdir($this->projectDir.'/config', 0700, true);
        mkdir($this->projectDir.'/var', 0700, true);
        $this->application = OperationsDatabase::connection();
        foreach ([
            'CREATE TABLE events (id INTEGER PRIMARY KEY, website_token VARCHAR(191), event_name VARCHAR(191), url CLOB, created_at DATETIME, archived_at DATETIME, custom_data CLOB)',
            "CREATE VIEW bi_anonymous_events_v1 AS SELECT website_token, strftime('%Y-%m-%d %H:00:00', created_at) AS event_hour, event_name, url AS page_path, COUNT(*) AS event_count FROM events GROUP BY 1, 2, 3, 4",
            "CREATE VIEW bi_dim_device_class_v1 AS SELECT 'mobile' AS device_class, 'Mobile' AS label WHERE 1 = 0",
            "CREATE VIEW analytics_custom_events_v1 AS SELECT id, website_token, event_name, url AS page_path, created_at, archived_at, json_extract(custom_data, '$.plan') AS plan_name FROM events",
            "CREATE VIEW bi_anonymous_goals_v1 AS SELECT 'tok' AS website_token, 'not-a-date' AS event_day, 'signup' AS goal_event, 3 AS event_count",
        ] as $sql) {
            $this->application->executeStatement($sql);
        }
        $insert = 'INSERT INTO events (website_token, event_name, url, created_at, archived_at, custom_data) VALUES (?, ?, ?, ?, ?, ?)';
        $this->application->executeStatement($insert, ['tok', 'view', '/a', '2026-10-01 10:12:00', null, '{"plan":"pro"}']);
        $this->application->executeStatement($insert, ['tok', 'view', '/b', '2026-10-01 10:40:00', null, '{"plan":"Grüße ☃"}']);
        $this->application->executeStatement($insert, ['tok', 'click', '/b', '2026-10-01 11:05:00', null, null]);
        $this->now = new \DateTimeImmutable('2026-10-07 12:00:00', new \DateTimeZone('UTC'));
    }

    protected function tearDown(): void
    {
        [$_ENV, $_SERVER] = $this->environment;
        foreach ([...(glob($this->projectDir.'/config/*') ?: []), ...(glob($this->projectDir.'/var/*') ?: [])] as $file) {
            unlink($file);
        }
        rmdir($this->projectDir.'/config');
        rmdir($this->projectDir.'/var');
        rmdir($this->projectDir);
    }

    public function testEachSelectedViewBecomesATableAndTheResultIsRecorded(): void
    {
        $result = $this->runner()->run();

        self::assertSame([
            ['view' => 'bi_anonymous_events_v1', 'status' => 'synced', 'rows' => 3, 'message' => 'Replaced bi_anonymous_events_v1 with 3 rows.'],
            ['view' => 'bi_dim_device_class_v1', 'status' => 'synced', 'rows' => 0, 'message' => 'Replaced bi_dim_device_class_v1 with 0 rows.'],
            ['view' => 'analytics_custom_events_v1', 'status' => 'synced', 'rows' => 3, 'message' => 'Replaced analytics_custom_events_v1 with 3 rows.'],
        ], $result['results']);
        $reporting = $this->reporting();
        self::assertSame(['analytics_custom_events_v1', 'bi_anonymous_events_v1', 'bi_dim_device_class_v1'], $reporting->fetchFirstColumn("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name"));
        self::assertSame(
            [['website_token' => 'tok', 'event_hour' => '2026-10-01 10:00:00', 'event_name' => 'view', 'page_path' => '/a', 'event_count' => 1]],
            $reporting->fetchAllAssociative("SELECT * FROM bi_anonymous_events_v1 WHERE page_path = '/a'"),
        );
        self::assertSame('Grüße ☃', $reporting->fetchOne('SELECT plan_name FROM analytics_custom_events_v1 WHERE id = 2'));

        $task = $this->tasks()->latest(ReportingDatabaseSyncRunner::TASK_TYPE)['bi_anonymous_events_v1']['success'];
        self::assertSame(['succeeded', 'schedule', 3], [$task['status'], $task['triggered_by'], $task['row_count']]);
        self::assertSame(
            ['category' => 'task', 'operation' => 'reporting_database_sync', 'subject' => 'bi_anonymous_events_v1', 'outcome' => 'succeeded', 'details' => 'Replaced bi_anonymous_events_v1 with 3 rows.'],
            $this->application->fetchAssociative('SELECT category, operation, subject, outcome, details FROM audit_trail ORDER BY id LIMIT 1'),
        );
    }

    public function testViewsWaitForTheIntervalAndTheCopyFollowsDeletions(): void
    {
        $this->runner()->run();
        $this->application->executeStatement("DELETE FROM events WHERE url = '/a'");

        $this->now = $this->now->modify('+54 minutes');
        self::assertSame(['not_due', 'not_due', 'not_due'], array_column($this->runner()->run()['results'], 'status'));
        self::assertSame(3, (int) $this->reporting()->fetchOne('SELECT COUNT(*) FROM analytics_custom_events_v1'));

        $this->now = $this->now->modify('+1 minute');
        self::assertSame(['synced', 'synced', 'synced'], array_column($this->runner()->run()['results'], 'status'));
        self::assertSame(2, (int) $this->reporting()->fetchOne('SELECT COUNT(*) FROM analytics_custom_events_v1'));
        self::assertSame(0, (int) $this->reporting()->fetchOne("SELECT COUNT(*) FROM bi_anonymous_events_v1 WHERE page_path = '/a'"));
    }

    public function testAFailedViewKeepsItsPreviousCopyAndIsRetriedWhileOthersContinue(): void
    {
        $runner = $this->runner(['reporting_database_views' => ['bi_anonymous_events_v1', 'bi_anonymous_goals_v1'], 'reporting_database_private_views' => []]);

        $result = $runner->run();

        self::assertSame(['synced', 'failed'], array_column($result['results'], 'status'));
        self::assertSame('The view bi_anonymous_goals_v1 has a value in event_day that is not a valid DATE; the table was not replaced.', $result['results'][1]['message']);
        $latest = $this->tasks()->latest(ReportingDatabaseSyncRunner::TASK_TYPE)['bi_anonymous_goals_v1'];
        self::assertSame('failed', $latest['attempt']['status']);
        self::assertNull($latest['success']);

        $this->now = $this->now->modify('+15 minutes');
        self::assertSame(['not_due', 'failed'], array_column($runner->run()['results'], 'status'));
    }

    public function testAMissingConnectionIsRecordedOnEveryDueView(): void
    {
        $result = $this->runner([], url: null)->run();

        self::assertSame(['failed', 'failed', 'failed'], array_column($result['results'], 'status'));
        self::assertStringStartsWith('REPORTING_DATABASE_URL is not set.', $result['results'][0]['message']);
    }

    public function testDatabaseErrorsShowTheirFirstLineAndAreLogged(): void
    {
        $logger = new class extends AbstractLogger {
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = $message;
            }
        };
        // A folder where the database file should be: SQLite cannot open it.
        mkdir($this->projectDir.'/var/folder.db');
        try {
            $result = $this->runner([], url: 'sqlite:///'.$this->projectDir.'/var/folder.db', logger: $logger)->run();
        } finally {
            rmdir($this->projectDir.'/var/folder.db');
        }

        self::assertSame('failed', $result['results'][0]['status']);
        self::assertStringStartsWith('The reporting database could not be reached', $result['results'][0]['message']);
        self::assertStringNotContainsString("\n", $result['results'][0]['message']);
        self::assertSame(['The reporting database could not be reached.'], $logger->records);
    }

    public function testTheApplicationDatabaseIsRefusedAsTheReportingDatabase(): void
    {
        $application = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->projectDir.'/var/data.db']);
        $connection = new ReportingDatabaseConnection('sqlite:///%kernel.project_dir%/var/./data.db', $this->projectDir, $application);

        $this->expectException(ReportingDatabaseException::class);
        $this->expectExceptionMessage('names the application database');
        $connection->connection();
    }

    public function testCheckProvesTheUserCanReplaceTablesAndListsCopies(): void
    {
        $runner = $this->runner();
        $before = $runner->check();
        self::assertSame(['engine' => 'SQLite', 'host' => null, 'port' => null, 'database' => 'var/reporting.db'], array_intersect_key($before, array_flip(['engine', 'host', 'port', 'database'])));
        self::assertNotSame('', $before['server_version']);
        self::assertSame([], $before['tables']);

        $runner->run();
        self::assertSame(['bi_anonymous_events_v1', 'bi_dim_device_class_v1', 'analytics_custom_events_v1'], $runner->check()['tables']);
        self::assertSame(3, (int) $this->reporting()->fetchOne("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table'"), 'The check table was dropped.');
    }

    public function testStatusAndTheDashboardTrigger(): void
    {
        $runner = $this->runner();
        $settings = $this->settings([])->toArray();
        self::assertTrue($runner->status($settings)['scheduler_late']);

        $runner->run(true, ['bi_dim_device_class_v1'], null, TaskTrigger::dashboard('scott'));

        $status = $runner->status($settings);
        self::assertFalse($status['scheduler_late']);
        self::assertSame(['never', 'succeeded', 'never'], array_column($status['views'], 'status'));
        self::assertEquals($this->now->modify('+60 minutes'), $status['views'][1]['next_due']);
        self::assertTrue($status['views'][2]['private']);
        $task = $this->tasks()->latest(ReportingDatabaseSyncRunner::TASK_TYPE)['bi_dim_device_class_v1']['attempt'];
        self::assertSame(['dashboard', 'scott'], [$task['triggered_by'], $task['requested_by']]);
    }

    public function testNothingRunsWhileSyncIsOffAndOnlySelectedViewsCanBeRequested(): void
    {
        self::assertSame(['enabled' => false, 'results' => []], $this->runner(['reporting_database_enabled' => false])->run(true));
        self::assertFileDoesNotExist($this->projectDir.'/var/reporting.db');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not selected');
        $this->runner()->run(true, ['analytics_archived_events_v1']);
    }

    private function runner(array $values = [], ?string $url = 'sqlite:///%kernel.project_dir%/var/reporting.db', ?AbstractLogger $logger = null): ReportingDatabaseSyncRunner
    {
        $customData = $this->createStub(CustomDataSettings::class);
        $customData->method('numericReportingColumns')->willReturn([]);

        return new ReportingDatabaseSyncRunner(
            $this->settings($values),
            new ReportingDatabaseConnection($url, $this->projectDir, $this->application),
            new BigQueryViewExporter($this->application, new BigQueryViewCatalog($customData), $this->projectDir),
            $this->tasks(),
            $logger ?? new NullLogger(),
            fn (): \DateTimeImmutable => $this->now,
        );
    }

    private function settings(array $values): ReportingDatabaseSettings
    {
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump([
            'reporting_database_enabled' => true,
            'reporting_database_views' => ['bi_anonymous_events_v1', 'bi_dim_device_class_v1'],
            'reporting_database_private_views' => ['analytics_custom_events_v1'],
            ...$values,
        ], 4, 2));

        return new ReportingDatabaseSettings(new AggregateConfigLoader($this->projectDir, 'test'));
    }

    private function tasks(): ProcessingTasks
    {
        return new ProcessingTasks($this->application, new AuditTrail($this->application));
    }

    private function reporting(): Connection
    {
        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->projectDir.'/var/reporting.db']);
    }
}
