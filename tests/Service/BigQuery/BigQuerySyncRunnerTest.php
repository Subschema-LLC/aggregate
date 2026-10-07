<?php

declare(strict_types=1);

namespace App\Tests\Service\BigQuery;

use App\Service\AggregateConfigLoader;
use App\Service\BigQuery\BigQueryClient;
use App\Service\BigQuery\BigQueryCredentialsFactory;
use App\Service\BigQuery\BigQueryException;
use App\Service\BigQuery\BigQuerySettings;
use App\Service\BigQuery\BigQuerySyncRunner;
use App\Service\BigQuery\BigQuerySyncStateStore;
use App\Service\BigQuery\BigQueryViewExporter;
use App\Service\BigQuery\GoogleCredentials;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261007000000;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Yaml\Yaml;

require_once dirname(__DIR__, 3).'/migrations/Version20261007000000.php';

final class BigQuerySyncRunnerTest extends TestCase
{
    private string $projectDir;
    private Connection $connection;
    private \DateTimeImmutable $now;
    /** @var list<array{0: string, 1: string}> */
    private array $loads = [];
    private array $environment;

    protected function setUp(): void
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
        $this->environment = [$_ENV, $_SERVER];
        foreach (array_keys(BigQuerySettings::defaults()) as $key) {
            unset($_ENV[strtoupper($key)], $_SERVER[strtoupper($key)]);
        }
        $this->projectDir = sys_get_temp_dir().'/aggregate-bigquery-runner-'.bin2hex(random_bytes(6));
        mkdir($this->projectDir.'/config', 0700, true);
        $this->connection = self::database();
        $this->now = new \DateTimeImmutable('2026-10-07 12:00:00', new \DateTimeZone('UTC'));
    }

    protected function tearDown(): void
    {
        [$_ENV, $_SERVER] = $this->environment;
        foreach (glob($this->projectDir.'/config/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->projectDir.'/config');
        @rmdir($this->projectDir.'/var/bigquery');
        @rmdir($this->projectDir.'/var');
        rmdir($this->projectDir);
    }

    public static function database(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $schema = new Schema();
        (new Version20261007000000($connection, new NullLogger()))->up($schema);
        foreach ($connection->getDatabasePlatform()->getCreateTablesSQL($schema->getTables()) as $sql) {
            $connection->executeStatement($sql);
        }

        return $connection;
    }

    public function testNothingRunsWhileSyncIsOff(): void
    {
        $result = $this->runner(['bigquery_enabled' => false])->run(true);

        self::assertSame(['enabled' => false, 'results' => []], $result);
        self::assertSame([], $this->loads);
        self::assertSame([], (new BigQuerySyncStateStore($this->connection))->all());
    }

    public function testEachSelectedViewReplacesItsTableAndRecordsTheResult(): void
    {
        $result = $this->runner()->run();

        self::assertSame([
            ['view' => 'bi_anonymous_events_v1', 'status' => 'synced', 'rows' => 3, 'message' => 'Replaced my-project.aggregate.bi_anonymous_events_v1 with 3 rows.'],
            ['view' => 'bi_dim_device_class_v1', 'status' => 'synced', 'rows' => 0, 'message' => 'Replaced my-project.aggregate.bi_dim_device_class_v1 with 0 rows.'],
            ['view' => 'analytics_custom_events_v1', 'status' => 'synced', 'rows' => 3, 'message' => 'Replaced my-project.aggregate.analytics_custom_events_v1 with 3 rows.'],
        ], $result['results']);
        self::assertSame([['load', 'bi_anonymous_events_v1'], ['empty', 'bi_dim_device_class_v1'], ['load', 'analytics_custom_events_v1']], $this->loads);
        $states = (new BigQuerySyncStateStore($this->connection))->all();
        self::assertSame('success', $states['bi_anonymous_events_v1']['status']);
        self::assertSame(3, $states['bi_anonymous_events_v1']['row_count']);
        self::assertEquals($this->now, $states['bi_anonymous_events_v1']['succeeded_at']);
        self::assertSame('job-bi_anonymous_events_v1', $states['bi_anonymous_events_v1']['job_id']);
        self::assertEquals($this->now, $states[BigQuerySyncStateStore::RUNNER]['started_at']);
        // Exports are deleted after each upload.
        self::assertSame([], glob($this->projectDir.'/var/bigquery/export-*') ?: []);
    }

    public function testViewsWaitForTheIntervalWithAFiveMinuteMargin(): void
    {
        $this->runner()->run();
        $this->loads = [];

        $this->now = $this->now->modify('+54 minutes');
        $early = $this->runner()->run();
        self::assertSame(['not_due', 'not_due', 'not_due'], array_column($early['results'], 'status'));
        self::assertSame([], $this->loads);

        $this->now = $this->now->modify('+1 minute');
        self::assertSame(['synced', 'synced', 'synced'], array_column($this->runner()->run()['results'], 'status'));

        $this->loads = [];
        $this->runner()->run(true, ['bi_dim_device_class_v1']);
        self::assertSame([['empty', 'bi_dim_device_class_v1']], $this->loads);
    }

    public function testAFailedViewIsRecordedAndRetriedAfterFifteenMinutesWhileOthersContinue(): void
    {
        $result = $this->runner([], failFor: 'bi_dim_device_class_v1')->run();

        self::assertSame(['synced', 'failed', 'synced'], array_column($result['results'], 'status'));
        self::assertSame('BigQuery refused the request: quota exceeded.', $result['results'][1]['message']);
        $state = (new BigQuerySyncStateStore($this->connection))->all()['bi_dim_device_class_v1'];
        self::assertSame('failed', $state['status']);
        self::assertNull($state['succeeded_at']);

        $this->now = $this->now->modify('+15 minutes');
        $retry = $this->runner()->run();
        self::assertSame(['not_due', 'synced', 'not_due'], array_column($retry['results'], 'status'));
    }

    public function testSetupErrorsAreRecordedOnEveryDueView(): void
    {
        $result = $this->runner([], setupError: new BigQueryException('No service account key is installed.'))->run();

        self::assertSame(['failed', 'failed', 'failed'], array_column($result['results'], 'status'));
        self::assertSame('No service account key is installed.', $result['results'][0]['message']);
        self::assertSame([], $this->loads);
    }

    public function testUnexpectedErrorsAreLoggedButNotShown(): void
    {
        $logger = new class extends AbstractLogger {
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = [$message, $context['view'] ?? null];
            }
        };
        $result = $this->runner([], failFor: 'bi_anonymous_events_v1', failure: new \RuntimeException('SQLSTATE: secret detail'), logger: $logger)->run();

        self::assertSame('The view could not be synced (RuntimeException); see the application log.', $result['results'][0]['message']);
        self::assertSame([['BigQuery sync of a view failed.', 'bi_anonymous_events_v1']], $logger->records);
    }

    public function testAViewAnotherRunHoldsIsSkippedUntilItsLeaseExpires(): void
    {
        $store = new BigQuerySyncStateStore($this->connection);
        self::assertNotNull($store->claim('bi_anonymous_events_v1', $this->now));
        self::assertNull($store->claim('bi_anonymous_events_v1', $this->now->modify('+1 minute')));

        $result = $this->runner()->run(true);
        self::assertSame('busy', $result['results'][0]['status']);
        self::assertSame(['empty', 'bi_dim_device_class_v1'], $this->loads[0]);

        // An abandoned claim (a crashed run) expires after the lease.
        self::assertNotNull($store->claim('bi_anonymous_events_v1', $this->now->modify('+2 hours +1 second')));
    }

    public function testOnlySelectedViewsCanBeRequestedAndInvalidSettingsStopEverything(): void
    {
        try {
            $this->runner()->run(true, ['analytics_archived_events_v1']);
            self::fail('An unselected view was synced.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('not selected', $e->getMessage());
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->runner(['bigquery_dataset' => 'bad-name'])->run(true);
    }

    public function testStatusReportsNextRunsAndAMissingSchedule(): void
    {
        $runner = $this->runner();
        $settings = $this->settings([])->toArray();
        $before = $runner->status($settings);
        self::assertTrue($before['scheduler_late']);
        self::assertSame('never', $before['views'][0]['status']);
        self::assertNull($before['views'][0]['next_due']);
        self::assertTrue($before['views'][2]['private']);

        $runner->run();
        $after = $runner->status($settings);
        self::assertFalse($after['scheduler_late']);
        self::assertSame('success', $after['views'][0]['status']);
        self::assertEquals($this->now->modify('+60 minutes'), $after['views'][0]['next_due']);

        $this->now = $this->now->modify('+3 hours');
        self::assertTrue($runner->status($settings)['scheduler_late']);
    }

    public function testDryRunExportsWithoutCredentialsAndCanKeepTheFiles(): void
    {
        mkdir($output = $this->projectDir.'/var/out', 0700, true);
        $results = $this->runner([], setupError: new BigQueryException('never used'))->dryRun(['bi_anonymous_events_v1'], $output);

        self::assertSame(3, $results[0]['rows']);
        self::assertFileExists($output.'/bi_anonymous_events_v1.ndjson');
        self::assertSame([['name' => 'website_token', 'type' => 'STRING']], json_decode((string) file_get_contents($output.'/bi_anonymous_events_v1.schema.json'), true));
        self::assertSame([], $this->loads);
        array_map('unlink', glob($output.'/*'));
        rmdir($output);
    }

    private function settings(array $values): BigQuerySettings
    {
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump([
            'bigquery_enabled' => true,
            'bigquery_project_id' => 'my-project',
            'bigquery_views' => ['bi_anonymous_events_v1', 'bi_dim_device_class_v1'],
            'bigquery_private_views' => ['analytics_custom_events_v1'],
            ...$values,
        ], 4, 2));

        return new BigQuerySettings(new AggregateConfigLoader($this->projectDir, 'test'), $this->projectDir);
    }

    private function runner(array $values = [], ?string $failFor = null, ?\Throwable $failure = null, ?\Throwable $setupError = null, ?AbstractLogger $logger = null): BigQuerySyncRunner
    {
        $settings = $this->settings($values);
        $credentials = $this->createStub(GoogleCredentials::class);
        $credentials->method('projectId')->willReturn('');
        $factory = $this->createStub(BigQueryCredentialsFactory::class);
        if ($setupError !== null) {
            $factory->method('create')->willThrowException($setupError);
        } else {
            $factory->method('create')->willReturn($credentials);
        }
        $directory = $this->projectDir.'/var/bigquery';
        $exporter = $this->createStub(BigQueryViewExporter::class);
        $exporter->method('export')->willReturnCallback(static function (string $view) use ($directory): array {
            @mkdir($directory, 0700, true);
            $file = $directory.'/export-'.$view.'.ndjson';
            $rows = $view === 'bi_dim_device_class_v1' ? 0 : 3;
            file_put_contents($file, str_repeat("{}\n", $rows));

            return ['file' => $file, 'rows' => $rows, 'bytes' => $rows * 3, 'fields' => [['name' => 'website_token', 'type' => 'STRING']]];
        });
        $client = $this->createStub(BigQueryClient::class);
        $client->method('project')->willReturn('my-project');
        $client->method('load')->willReturnCallback(function (string $dataset, string $table) use ($failFor, $failure): array {
            if ($table === $failFor) {
                throw $failure ?? new BigQueryException('BigQuery refused the request: quota exceeded.');
            }
            $this->loads[] = ['load', $table];

            return ['job_id' => 'job-'.$table, 'rows' => 3];
        });
        $client->method('replaceWithEmpty')->willReturnCallback(function (string $dataset, string $table) use ($failFor, $failure): array {
            if ($table === $failFor) {
                throw $failure ?? new BigQueryException('BigQuery refused the request: quota exceeded.');
            }
            $this->loads[] = ['empty', $table];

            return ['job_id' => 'job-'.$table, 'rows' => 0];
        });

        return new BigQuerySyncRunner(
            $settings,
            $factory,
            $exporter,
            new BigQuerySyncStateStore($this->connection),
            new MockHttpClient(),
            $logger ?? new NullLogger(),
            fn (): \DateTimeImmutable => $this->now,
            static fn (): BigQueryClient => $client,
        );
    }
}
