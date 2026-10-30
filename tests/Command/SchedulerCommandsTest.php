<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\SchedulerRunCommand;
use App\Command\SchedulerStatusCommand;
use App\Service\Operations\TaskTrigger;
use App\Service\Scheduler\ScheduledJob;
use App\Service\Scheduler\Scheduler;
use App\Service\Scheduler\SchedulerSetup;
use App\Service\Update\Toolchain;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

final class SchedulerCommandsTest extends TestCase
{
    private string $projectDir;
    private MockClock $clock;
    /** @var array<string, array{status: string, message: string}> */
    private array $results = [];
    /** @var list<string> */
    private array $triggers = [];

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/aggregate-scheduler-command-'.bin2hex(random_bytes(6));
        mkdir($this->projectDir.'/var', 0700, true);
        $this->clock = new MockClock('2026-10-15 22:00:00 UTC');
        $this->results = [
            'analytics_maintenance' => ['status' => 'not_due', 'message' => 'Not due yet.'],
            'bigquery_sync' => ['status' => 'off', 'message' => 'BigQuery sync is turned off.'],
            'reporting_database_sync' => ['status' => 'not_due', 'message' => 'Not due yet.'],
            'forensic_database_sync' => ['status' => 'off', 'message' => 'The forensic database sync is turned off.'],
        ];
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->projectDir.'/var/scheduler/*') ?: []);
        @rmdir($this->projectDir.'/var/scheduler');
        rmdir($this->projectDir.'/var');
        rmdir($this->projectDir);
    }

    public function testARunWithNothingDueIsQuietSoCronSendsNoMail(): void
    {
        $tester = new CommandTester(new SchedulerRunCommand($this->scheduler(), new NullLogger()));

        self::assertSame(Command::SUCCESS, $tester->execute([], ['interactive' => false]));
        self::assertSame('', $tester->getDisplay());
        self::assertSame(['schedule', 'schedule', 'schedule', 'schedule'], $this->triggers);

        $tester->execute([], ['interactive' => false, 'verbosity' => OutputInterface::VERBOSITY_VERBOSE]);
        self::assertStringContainsString('BigQuery sync is turned off.', $tester->getDisplay());
    }

    public function testJobsThatRanOrFailedAreShownAndAFailureFailsTheRun(): void
    {
        $this->results['analytics_maintenance'] = ['status' => 'ran', 'message' => 'Archived 0, deleted 4 raw events.'];
        $this->results['reporting_database_sync'] = ['status' => 'failed', 'message' => 'bi_dim_page_v1 failed: The reporting database could not be reached.'];
        $tester = new CommandTester(new SchedulerRunCommand($this->scheduler(), new NullLogger()));

        self::assertSame(Command::FAILURE, $tester->execute([], ['interactive' => false]));
        $display = $tester->getDisplay();
        self::assertStringContainsString('Archived 0, deleted 4 raw events.', $display);
        self::assertStringContainsString('could not be reached', $display);
        self::assertStringNotContainsString('BigQuery sync is turned off.', $display);
    }

    public function testTheDashboardsRunNowRecordsTheAdministratorAndPrintsJson(): void
    {
        $tester = new CommandTester(new SchedulerRunCommand($this->scheduler(), new NullLogger()));

        self::assertSame(Command::SUCCESS, $tester->execute(['--requested-by' => 'scott', '--json' => true]));
        $json = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($json['ran']);
        self::assertSame('dashboard', $json['trigger']);
        self::assertSame(['not_due', 'off', 'not_due', 'off'], array_column($json['results'], 'status'));
        self::assertSame(['dashboard', 'dashboard', 'dashboard', 'dashboard'], $this->triggers);
    }

    public function testStatusSaysWhetherTheScheduledTaskRunsAndHowToSetItUp(): void
    {
        $tester = new CommandTester(new SchedulerStatusCommand($this->scheduler(), $this->schedulerSetup('/var/www/vhosts/accept.optinmodel.com/httpdocs')));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        $display = preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('The scheduled task has not run yet.', $display);
        self::assertStringContainsString('accept.optinmodel.com → Scheduled Tasks', $display);
        self::assertStringContainsString('Run a PHP script', $display);
        self::assertStringContainsString('httpdocs/bin/console', $display);
        self::assertStringContainsString('Do not use "Run a command"', $display);

        // A run by hand does not count; a run without a terminal does.
        (new CommandTester(new SchedulerRunCommand($this->scheduler(), new NullLogger())))->execute(['--requested-by' => 'scott']);
        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('Last run by hand: 2026-10-15 22:00 UTC.', $tester->getDisplay());
        (new CommandTester(new SchedulerRunCommand($this->scheduler(), new NullLogger())))->execute([], ['interactive' => false]);
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('The scheduled task last ran 2026-10-15 22:00 UTC.', $tester->getDisplay());

        $this->clock->modify('+31 minutes');
        self::assertSame(Command::FAILURE, $tester->execute(['--json' => true]));
        $json = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('late', $json['state']);
        self::assertSame('2026-10-15T22:00:00+00:00', $json['last_scheduled_run']['started_at']);
        self::assertSame('httpdocs/bin/console', $json['setup']['plesk']['script_path']);
        self::assertSame(['analytics_maintenance', 'bigquery_sync', 'reporting_database_sync', 'forensic_database_sync'], array_column($json['jobs'], 'job'));
    }

    public function testOtherServersAreShownTheCrontabLine(): void
    {
        $tester = new CommandTester(new SchedulerStatusCommand($this->scheduler(), $this->schedulerSetup('/srv/aggregate')));
        $tester->execute([]);

        self::assertStringNotContainsString('Plesk', $tester->getDisplay());
        self::assertStringContainsString("*/5 * * * * cd '/srv/aggregate' && '/usr/bin/php' bin/console app:scheduler:run --no-interaction", $tester->getDisplay());
    }

    private function scheduler(): Scheduler
    {
        $jobs = [];
        foreach ($this->results as $name => $result) {
            $job = $this->createStub(ScheduledJob::class);
            $job->method('name')->willReturn($name);
            $job->method('label')->willReturn(ucfirst(str_replace('_', ' ', $name)));
            $job->method('status')->willReturn(['enabled' => $result['status'] !== 'off', 'schedule' => 'Daily', 'last_run' => null, 'last_status' => null, 'next_due' => null, 'problem' => null]);
            $job->method('runDue')->willReturnCallback(function (TaskTrigger $trigger) use ($name): array {
                $this->triggers[] = $trigger->source;

                return $this->results[$name];
            });
            $jobs[] = $job;
        }

        return new Scheduler($jobs[0], $jobs[1], $jobs[2], $jobs[3], $this->projectDir, $this->clock, new NullLogger());
    }

    private function schedulerSetup(string $projectDir): SchedulerSetup
    {
        $toolchain = $this->createStub(Toolchain::class);
        $toolchain->method('php')->willReturn(['/usr/bin/php']);

        return new SchedulerSetup($toolchain, $projectDir);
    }
}
