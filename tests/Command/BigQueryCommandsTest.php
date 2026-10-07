<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\BigQueryCheckCommand;
use App\Command\BigQuerySyncCommand;
use App\Service\AggregateConfigLoader;
use App\Service\BigQuery\BigQueryBackgroundSync;
use App\Service\BigQuery\BigQueryException;
use App\Service\BigQuery\BigQuerySettings;
use App\Service\BigQuery\BigQuerySyncRunner;
use App\Service\Operations\TaskTrigger;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Yaml\Yaml;

final class BigQueryCommandsTest extends TestCase
{
    private string $projectDir;
    private array $environment;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        foreach (array_keys(BigQuerySettings::defaults()) as $key) {
            unset($_ENV[strtoupper($key)], $_SERVER[strtoupper($key)]);
        }
        $this->projectDir = sys_get_temp_dir().'/aggregate-bigquery-command-'.bin2hex(random_bytes(6));
        mkdir($this->projectDir.'/config', 0700, true);
    }

    protected function tearDown(): void
    {
        [$_ENV, $_SERVER] = $this->environment;
        @unlink($this->projectDir.'/config/aggregate.yaml');
        rmdir($this->projectDir.'/config');
        rmdir($this->projectDir);
    }

    public function testSyncReportsEachViewAndFailsWhenOneFails(): void
    {
        $runner = $this->createMock(BigQuerySyncRunner::class);
        $runner->expects(self::once())->method('run')->with(true, ['bi_anonymous_events_v1'])->willReturn(['enabled' => true, 'results' => [
            ['view' => 'bi_anonymous_events_v1', 'status' => 'failed', 'rows' => null, 'message' => 'BigQuery refused the request.'],
        ]]);
        $tester = new CommandTester(new BigQuerySyncCommand($runner, $this->settings([]), new NullLogger()));

        self::assertSame(Command::FAILURE, $tester->execute(['--force' => true, '--view' => ['bi_anonymous_events_v1']]));
        self::assertStringContainsString('BigQuery refused the request.', $tester->getDisplay());
    }

    public function testTheDashboardsSyncNowRecordsTheAdministrator(): void
    {
        $runner = $this->createMock(BigQuerySyncRunner::class);
        $runner->expects(self::once())->method('run')->with(true, null, self::anything(), self::callback(
            static fn (?TaskTrigger $trigger): bool => $trigger?->source === TaskTrigger::DASHBOARD && $trigger->requestedBy === 'scott',
        ))->willReturn(['enabled' => true, 'results' => []]);
        $tester = new CommandTester(new BigQuerySyncCommand($runner, $this->settings([]), new NullLogger()));

        self::assertSame(Command::SUCCESS, $tester->execute(['--force' => true, '--requested-by' => 'scott']));
    }

    public function testSyncExplainsWhenItIsOffOrMisconfigured(): void
    {
        $runner = $this->createStub(BigQuerySyncRunner::class);
        $runner->method('run')->willReturnOnConsecutiveCalls(
            ['enabled' => false, 'results' => []],
            self::throwException(new \InvalidArgumentException('The BigQuery dataset name must use letters.')),
        );
        $tester = new CommandTester(new BigQuerySyncCommand($runner, $this->settings([]), new NullLogger()));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('BigQuery sync is turned off', $tester->getDisplay());
        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('nothing was synced', $tester->getDisplay());
        self::assertSame(Command::INVALID, $tester->execute(['--output' => '/tmp']));
    }

    public function testDryRunUsesTheSelectedViewsAndRejectsOthers(): void
    {
        $runner = $this->createMock(BigQuerySyncRunner::class);
        $runner->expects(self::once())->method('dryRun')->with(['bi_anonymous_events_v1', 'analytics_custom_events_v1'], null)->willReturn([
            ['view' => 'bi_anonymous_events_v1', 'rows' => 2, 'bytes' => 40, 'fields' => [['name' => 'event_count', 'type' => 'INT64']], 'file' => null],
            ['view' => 'analytics_custom_events_v1', 'rows' => 0, 'bytes' => 0, 'fields' => [['name' => 'id', 'type' => 'INT64']], 'file' => null],
        ]);
        $tester = new CommandTester(new BigQuerySyncCommand($runner, $this->settings(['bigquery_views' => ['bi_anonymous_events_v1'], 'bigquery_private_views' => ['analytics_custom_events_v1']]), new NullLogger()));

        self::assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true]));
        self::assertStringContainsString('event_count INT64', $tester->getDisplay());
        self::assertStringContainsString('nothing was uploaded', $tester->getDisplay());

        self::assertSame(Command::FAILURE, $tester->execute(['--dry-run' => true, '--view' => ['events']]));
        self::assertSame(Command::FAILURE, $tester->execute(['--dry-run' => true, '--view' => ['bi_anonymous_events_v1'], '--output' => $this->projectDir.'/missing']));
    }

    public function testCheckShowsSettingsStatusAndTheConnectionResult(): void
    {
        $runner = $this->createStub(BigQuerySyncRunner::class);
        $runner->method('status')->willReturn(['last_run' => null, 'views' => [[
            'view' => 'bi_anonymous_events_v1', 'private' => false, 'description' => '', 'status' => 'succeeded',
            'started_at' => null, 'finished_at' => null, 'succeeded_at' => new \DateTimeImmutable('2026-10-07 11:00:00'), 'row_count' => 42,
            'message' => 'Replaced it.', 'job_id' => 'j', 'next_due' => new \DateTimeImmutable('2026-10-07 12:00:00'),
        ]], 'scheduler_late' => true]);
        $runner->method('check')->willReturnOnConsecutiveCalls(
            ['identity' => 'sync@p.iam.gserviceaccount.com', 'project' => 'p-project', 'dataset' => 'aggregate', 'dataset_exists' => true, 'dataset_location' => 'US', 'location' => 'US'],
            self::throwException(new BigQueryException('No service account key is installed.')),
        );
        $background = $this->createStub(BigQueryBackgroundSync::class);
        $background->method('cronLine')->willReturn('*/5 * * * * cd /srv && php bin/console app:bigquery:sync --no-interaction');
        $tester = new CommandTester(new BigQueryCheckCommand($runner, $this->settings(['bigquery_enabled' => true]), $background));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $display = preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('on, every 60 minutes', $display);
        self::assertStringContainsString('2026-10-07 11:00 UTC', $display);
        self::assertStringContainsString('has not run recently', $display);
        self::assertStringContainsString('Signed in as sync@p.iam.gserviceaccount.com', $display);
        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('No service account key is installed.', $tester->getDisplay());
        self::assertSame(Command::SUCCESS, $tester->execute(['--no-connect' => true]));
    }

    private function settings(array $values): BigQuerySettings
    {
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump($values));

        return new BigQuerySettings(new AggregateConfigLoader($this->projectDir, 'test'), $this->projectDir);
    }
}
