<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ReportingDatabaseCheckCommand;
use App\Command\ReportingDatabaseSyncCommand;
use App\Service\AggregateConfigLoader;
use App\Service\Operations\TaskTrigger;
use App\Service\ReportingDatabase\ReportingDatabaseBackgroundSync;
use App\Service\ReportingDatabase\ReportingDatabaseConnection;
use App\Service\ReportingDatabase\ReportingDatabaseException;
use App\Service\ReportingDatabase\ReportingDatabaseSettings;
use App\Service\ReportingDatabase\ReportingDatabaseSyncRunner;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Yaml\Yaml;

final class ReportingDatabaseCommandsTest extends TestCase
{
    private string $projectDir;
    private array $environment;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        foreach (array_keys(ReportingDatabaseSettings::defaults()) as $key) {
            unset($_ENV[strtoupper($key)], $_SERVER[strtoupper($key)]);
        }
        $this->projectDir = sys_get_temp_dir().'/aggregate-reporting-command-'.bin2hex(random_bytes(6));
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
        $runner = $this->createMock(ReportingDatabaseSyncRunner::class);
        $runner->expects(self::once())->method('run')->with(true, ['bi_anonymous_events_v1'])->willReturn(['enabled' => true, 'results' => [
            ['view' => 'bi_anonymous_events_v1', 'status' => 'failed', 'rows' => null, 'message' => 'The reporting database could not be reached.'],
        ]]);
        $tester = new CommandTester(new ReportingDatabaseSyncCommand($runner, new NullLogger()));

        self::assertSame(Command::FAILURE, $tester->execute(['--force' => true, '--view' => ['bi_anonymous_events_v1']]));
        self::assertStringContainsString('The reporting database could not be reached.', $tester->getDisplay());
    }

    public function testTheDashboardsSyncNowRecordsTheAdministrator(): void
    {
        $runner = $this->createMock(ReportingDatabaseSyncRunner::class);
        $runner->expects(self::once())->method('run')->with(true, null, self::anything(), self::callback(
            static fn (?TaskTrigger $trigger): bool => $trigger?->source === TaskTrigger::DASHBOARD && $trigger->requestedBy === 'scott',
        ))->willReturn(['enabled' => true, 'results' => [['view' => 'bi_dim_event_name_v1', 'status' => 'synced', 'rows' => 4, 'message' => 'Replaced bi_dim_event_name_v1 with 4 rows.']]]);
        $tester = new CommandTester(new ReportingDatabaseSyncCommand($runner, new NullLogger()));

        self::assertSame(Command::SUCCESS, $tester->execute(['--force' => true, '--requested-by' => 'scott', '--json' => true]));
        self::assertSame('synced', json_decode($tester->getDisplay(), true)['results'][0]['status']);
    }

    public function testSyncExplainsWhenItIsOffOrMisconfigured(): void
    {
        $runner = $this->createStub(ReportingDatabaseSyncRunner::class);
        $runner->method('run')->willReturnOnConsecutiveCalls(
            ['enabled' => false, 'results' => []],
            self::throwException(new \InvalidArgumentException('The sync interval must be one of 15, 30 minutes.')),
        );
        $tester = new CommandTester(new ReportingDatabaseSyncCommand($runner, new NullLogger()));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Reporting database sync is turned off', $tester->getDisplay());
        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('nothing was copied', $tester->getDisplay());
    }

    public function testCheckShowsTheDatabaseAndStatusAndTestsTheConnection(): void
    {
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump(['reporting_database_enabled' => true, 'reporting_database_views' => ['bi_anonymous_events_v1']]));
        $runner = $this->createMock(ReportingDatabaseSyncRunner::class);
        $runner->method('status')->willReturn(['last_run' => null, 'scheduler_late' => true, 'views' => [[
            'view' => 'bi_anonymous_events_v1', 'private' => false, 'description' => '', 'status' => 'never', 'started_at' => null, 'finished_at' => null,
            'succeeded_at' => null, 'row_count' => null, 'message' => null, 'next_due' => null,
        ]]]);
        $runner->method('check')->willReturnOnConsecutiveCalls(
            ['engine' => 'MySQL or MariaDB', 'host' => 'localhost', 'port' => 3306, 'database' => 'aggregate_reporting', 'server_version' => '8.0.39', 'tables' => []],
            self::throwException(new ReportingDatabaseException('The reporting database could not be reached (SQLSTATE HY000): Access denied.')),
        );
        $runner->expects(self::exactly(2))->method('check');
        $background = $this->createStub(ReportingDatabaseBackgroundSync::class);
        $background->method('cronLine')->willReturn('*/5 * * * * cd /srv && php bin/console app:reporting-database:sync --no-interaction');
        $connection = new ReportingDatabaseConnection('mysql://writer:secret-pw@localhost:3306/aggregate_reporting', $this->projectDir, DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]));
        $tester = new CommandTester(new ReportingDatabaseCheckCommand($runner, new ReportingDatabaseSettings(new AggregateConfigLoader($this->projectDir, 'test')), $connection, $background));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('on, every 60 minutes', $display);
        self::assertStringContainsString('MySQL or MariaDB database aggregate_reporting on localhost:3306', $display);
        self::assertStringContainsString('has not run recently', $display);
        self::assertStringContainsString('No views have been copied yet.', $display);
        self::assertStringNotContainsString('secret-pw', $display);

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('Access denied.', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
        // --no-connect only shows settings and status (check is not called a third time).
        self::assertSame(Command::SUCCESS, $tester->execute(['--no-connect' => true]));
    }
}
