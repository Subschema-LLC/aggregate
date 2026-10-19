<?php

declare(strict_types=1);

namespace App\Tests\Service\Scheduler;

use App\Service\AggregateConfigLoader;
use App\Service\BigQuery\BigQueryBackgroundSync;
use App\Service\BigQuery\BigQuerySettings;
use App\Service\BigQuery\BigQuerySyncRunner;
use App\Service\Operations\BackgroundCommand;
use App\Service\Operations\TaskTrigger;
use App\Service\ReportingDatabase\ReportingDatabaseBackgroundSync;
use App\Service\ReportingDatabase\ReportingDatabaseSettings;
use App\Service\ReportingDatabase\ReportingDatabaseSyncRunner;
use App\Service\Scheduler\BigQuerySyncJob;
use App\Service\Scheduler\ReportingDatabaseSyncJob;
use App\Service\Scheduler\ScheduledJob;
use App\Service\Scheduler\SchedulerSetup;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Yaml\Yaml;

/**
 * The scheduler jobs and dashboard launchers of the two view syncs, BigQuery
 * and the reporting database, which differ only in their names and messages.
 */
final class ViewSyncJobTest extends TestCase
{
    private const SYNCS = [
        'BigQuery' => [
            'prefix' => 'bigquery',
            'name' => 'bigquery_sync',
            'label' => 'BigQuery sync',
            'off' => 'BigQuery sync is turned off.',
            'invalid' => 'The BigQuery settings are invalid',
            'log' => 'Scheduled BigQuery sync failed.',
            'failed' => 'BigQuery sync failed (RuntimeException); see the application log.',
            'command' => 'app:bigquery:sync',
            'log_file' => 'var/bigquery/last-run.log',
        ],
        'reporting database' => [
            'prefix' => 'reporting_database',
            'name' => 'reporting_database_sync',
            'label' => 'Reporting database sync',
            'off' => 'The reporting database sync is turned off.',
            'invalid' => 'The reporting database settings are invalid',
            'log' => 'Scheduled reporting database sync failed.',
            'failed' => 'The reporting database sync failed (RuntimeException); see the application log.',
            'command' => 'app:reporting-database:sync',
            'log_file' => 'var/reporting-database/last-run.log',
        ],
    ];

    private string $projectDir;
    private array $environment;
    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        foreach (array_keys([...$_ENV, ...$_SERVER]) as $key) {
            if (is_string($key) && (str_starts_with($key, 'BIGQUERY_') || str_starts_with($key, 'REPORTING_DATABASE_'))) {
                unset($_ENV[$key], $_SERVER[$key]);
            }
        }
        $this->projectDir = sys_get_temp_dir().'/aggregate-view-sync-job-'.bin2hex(random_bytes(6));
        mkdir($this->projectDir.'/config', 0700, true);
    }

    protected function tearDown(): void
    {
        [$_ENV, $_SERVER] = $this->environment;
        @unlink($this->projectDir.'/config/aggregate.yaml');
        rmdir($this->projectDir.'/config');
        rmdir($this->projectDir);
    }

    public static function syncs(): iterable
    {
        foreach (array_keys(self::SYNCS) as $sync) {
            yield $sync => [$sync];
        }
    }

    #[DataProvider('syncs')]
    public function testNameAndLabel(string $sync): void
    {
        $job = $this->job($sync, ['enabled' => false]);

        self::assertSame(self::SYNCS[$sync]['name'], $job->name());
        self::assertSame(self::SYNCS[$sync]['label'], $job->label());
    }

    #[DataProvider('syncs')]
    public function testStatusOfAnEnabledSyncComesFromItsViews(string $sync): void
    {
        $lastRun = new \DateTimeImmutable('2026-10-15 02:10:00 UTC');
        $job = $this->job($sync, ['enabled' => true, 'interval_minutes' => 360], status: [
            'last_run' => $lastRun,
            'views' => [
                ['status' => 'succeeded', 'next_due' => new \DateTimeImmutable('2026-10-15 08:10:00 UTC')],
                ['status' => 'failed', 'next_due' => new \DateTimeImmutable('2026-10-15 02:25:00 UTC')],
            ],
            'scheduler_late' => false,
        ]);

        self::assertEquals([
            'enabled' => true,
            'schedule' => 'Every 6 hours',
            'last_run' => $lastRun,
            'last_status' => 'failed',
            'next_due' => new \DateTimeImmutable('2026-10-15 02:25:00 UTC'),
            'problem' => null,
        ], $job->status());
    }

    #[DataProvider('syncs')]
    public function testStatusOfATurnedOffSyncHasNoNextRun(string $sync): void
    {
        $job = $this->job($sync, ['enabled' => false], status: ['last_run' => null, 'views' => [['status' => 'never', 'next_due' => null]], 'scheduler_late' => false]);

        self::assertSame(['enabled' => false, 'schedule' => 'Off', 'last_run' => null, 'last_status' => null, 'next_due' => null, 'problem' => null], $job->status());
    }

    #[DataProvider('syncs')]
    public function testStatusNamesInvalidSettings(string $sync): void
    {
        $job = $this->job($sync, ['enabled' => 'sometimes']);

        self::assertSame([
            'enabled' => false,
            'schedule' => 'Invalid settings',
            'last_run' => null,
            'last_status' => null,
            'next_due' => null,
            'problem' => self::SYNCS[$sync]['invalid'].': '.self::SYNCS[$sync]['prefix'].'_enabled must be true or false.',
        ], $job->status());
    }

    #[DataProvider('syncs')]
    public function testRunDuePassesTheTriggerAndSummarizesTheViews(string $sync): void
    {
        $trigger = TaskTrigger::dashboard('scott');
        $job = $this->job($sync, ['enabled' => true], run: static function (bool $force, ?array $only, ?callable $progress, ?TaskTrigger $given) use ($trigger): array {
            self::assertSame([false, null, null, $trigger], [$force, $only, $progress, $given]);

            return ['enabled' => true, 'results' => [['view' => 'bi_anonymous_events_v1', 'status' => 'synced', 'rows' => 3, 'message' => 'Replaced.']]];
        });

        self::assertSame(['status' => 'ran', 'message' => 'Copied 1 view.'], $job->runDue($trigger));
    }

    #[DataProvider('syncs')]
    public function testRunDueWhenTurnedOff(string $sync): void
    {
        $job = $this->job($sync, ['enabled' => false], run: static fn (): array => ['enabled' => false, 'results' => []]);

        self::assertSame(['status' => 'off', 'message' => self::SYNCS[$sync]['off']], $job->runDue(TaskTrigger::schedule()));
    }

    #[DataProvider('syncs')]
    public function testRunDueReportsInvalidSettingsAndLogsOtherFailures(string $sync): void
    {
        $job = $this->job($sync, ['enabled' => true], run: static fn (): array => throw new \InvalidArgumentException('Choose a view.'));
        self::assertSame(['status' => 'failed', 'message' => self::SYNCS[$sync]['invalid'].': Choose a view.'], $job->runDue(TaskTrigger::schedule()));
        self::assertSame([], $this->logged);

        $job = $this->job($sync, ['enabled' => true], run: static fn (): array => throw new \RuntimeException('Disk full at /secret/path'));
        self::assertSame(['status' => 'failed', 'message' => self::SYNCS[$sync]['failed']], $job->runDue(TaskTrigger::schedule()));
        self::assertSame([self::SYNCS[$sync]['log']], $this->logged);
    }

    #[DataProvider('syncs')]
    public function testDashboardSyncStartsTheCommandInTheBackground(string $sync): void
    {
        $background = $this->createMock(BackgroundCommand::class);
        $background->expects(self::once())->method('problem')->with(self::SYNCS[$sync]['command'].' --force')->willReturn('PHP cannot start processes.');
        $background->expects(self::once())->method('start')->with(self::SYNCS[$sync]['command'], ['--force', '--requested-by=scott'], self::SYNCS[$sync]['log_file']);
        $scheduler = $this->createStub(SchedulerSetup::class);
        $scheduler->method('cronLine')->willReturn('*/5 * * * * run');
        $launcher = $sync === 'BigQuery' ? new BigQueryBackgroundSync($background, $scheduler) : new ReportingDatabaseBackgroundSync($background, $scheduler);

        self::assertSame(self::SYNCS[$sync]['command'], $launcher::COMMAND);
        self::assertSame('PHP cannot start processes.', $launcher->problem());
        self::assertSame('*/5 * * * * run', $launcher->cronLine());
        $launcher->start('scott');
    }

    /**
     * @param array<string, mixed>                  $settings keys without the sync's prefix
     * @param array<string, mixed>|null             $status   the runner's status
     * @param (\Closure(mixed ...): array)|null      $run      the runner's run
     */
    private function job(string $sync, array $settings, ?array $status = null, ?\Closure $run = null): ScheduledJob
    {
        $prefix = self::SYNCS[$sync]['prefix'];
        $values = [];
        foreach ($settings as $key => $value) {
            $values[$prefix.'_'.$key] = $value;
        }
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump($values));
        $config = new AggregateConfigLoader($this->projectDir, 'test');
        $runner = $this->createMock($sync === 'BigQuery' ? BigQuerySyncRunner::class : ReportingDatabaseSyncRunner::class);
        $runner->method('settings')->willReturn($sync === 'BigQuery' ? new BigQuerySettings($config, $this->projectDir) : new ReportingDatabaseSettings($config));
        $runner->method('status')->willReturn($status ?? ['last_run' => null, 'views' => [], 'scheduler_late' => false]);
        if ($run !== null) {
            $runner->method('run')->willReturnCallback($run);
        }
        $this->logged = [];
        $logged = &$this->logged;
        $logger = new class($logged) extends AbstractLogger {
            /** @param list<string> $messages */
            public function __construct(private array &$messages)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };
        $clock = new MockClock('2026-10-15 03:00:00 UTC');

        return $sync === 'BigQuery'
            ? new BigQuerySyncJob($runner, $clock, $logger)
            : new ReportingDatabaseSyncJob($runner, $clock, $logger);
    }
}
