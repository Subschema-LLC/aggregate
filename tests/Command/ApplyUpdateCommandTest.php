<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ApplyUpdateCommand;
use App\Command\RollbackUpdateCommand;
use App\Command\UpdateMaintenanceCommand;
use App\Service\Update\ApplicationUpdater;
use App\Service\Update\MaintenanceMode;
use App\Service\Update\UpdateJournal;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ApplyUpdateCommandTest extends TestCase
{
    public function testNonInteractiveRunsNeedExplicitConsent(): void
    {
        $updater = $this->createMock(ApplicationUpdater::class);
        $updater->expects(self::never())->method('start');
        $tester = new CommandTester(new ApplyUpdateCommand($updater));

        self::assertSame(1, $tester->execute([], ['interactive' => false]));
        self::assertStringContainsString('Pass --yes', $tester->getDisplay());
    }

    public function testOptionsReachTheUpdaterAndTheReportShowsPreservedConfiguration(): void
    {
        $updater = $this->createMock(ApplicationUpdater::class);
        $updater->expects(self::once())->method('start')->with([
            'version' => '1.2.0', 'package' => null, 'manifest' => null, 'signature' => null,
            'database_backup_confirmed' => true,
        ])->willReturnCallback(static function (array $options, callable $output): array {
            $output('Maintenance mode is on.', 'info');

            return [
                'status' => 'completed', 'backup' => 'var/updates/backups/x',
                'report' => ['files' => [
                    'overrides_created' => ['config/navigation.local.yaml'],
                    'kept' => ['public/.htaccess' => 'Kept your modified file.'],
                    'replaced_modified_count' => 2,
                    'env_keys_added' => ['NEW_SETTING'],
                ]],
            ];
        });
        $tester = new CommandTester(new ApplyUpdateCommand($updater));

        self::assertSame(0, $tester->execute(['--release' => '1.2.0', '--database-backup-confirmed' => true, '--yes' => true]));
        $display = preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('Maintenance mode is on.', $display);
        self::assertStringContainsString('config/navigation.local.yaml', $display);
        self::assertStringContainsString('public/.htaccess: Kept your modified file.', $display);
        self::assertStringContainsString('2 locally edited application files were replaced', $display);
        self::assertStringContainsString('NEW_SETTING', $display);
        self::assertStringContainsString('The update is complete.', $display);
    }

    public function testFailuresExitNonZeroAndPreflightReportsProblems(): void
    {
        $updater = $this->createMock(ApplicationUpdater::class);
        $updater->method('start')->willReturn(['status' => 'needs_attention', 'error' => 'Migration failed.', 'backup' => 'b', 'report' => []]);
        $updater->method('preflight')->willReturn(['problems' => ['This user cannot write src/.'], 'warnings' => [], 'environment' => 'prod', 'database' => 'abc']);
        $tester = new CommandTester(new ApplyUpdateCommand($updater));

        self::assertSame(1, $tester->execute(['--yes' => true]));
        self::assertStringContainsString('Migration failed.', $tester->getDisplay());
        self::assertSame(1, $tester->execute(['--preflight' => true, '--json' => true]));
        self::assertSame(['This user cannot write src/.'], json_decode($tester->getDisplay(), true)['problems']);
    }

    public function testRollbackAsksBeforeRestoringFiles(): void
    {
        $updater = $this->createMock(ApplicationUpdater::class);
        $updater->method('status')->willReturn(['id' => 'u1', 'type' => 'release', 'status' => 'needs_attention']);
        $updater->expects(self::never())->method('rollback');
        $tester = new CommandTester(new RollbackUpdateCommand($updater));

        $tester->setInputs(['no']);
        self::assertSame(1, $tester->execute(['--restore-database' => true]));
        self::assertStringContainsString('discards all data recorded since', $tester->getDisplay());
    }

    public function testMaintenanceCommandTurnsThePageOnAndOff(): void
    {
        $project = sys_get_temp_dir().'/aggregate-maintenance-command-'.bin2hex(random_bytes(6));
        mkdir($project.'/var', 0775, true);
        $maintenance = new MaintenanceMode($project);
        $tester = new CommandTester(new UpdateMaintenanceCommand($maintenance, new UpdateJournal($project)));
        try {
            self::assertSame(0, $tester->execute(['action' => 'on']));
            self::assertNull($maintenance->status()['expires_at']);
            $tester->execute([]);
            self::assertStringContainsString('until turned off', $tester->getDisplay());
            self::assertSame(0, $tester->execute(['action' => 'off']));
            self::assertNull($maintenance->status());
            self::assertSame(2, $tester->execute(['action' => 'sideways']));
        } finally {
            @unlink($project.'/var/maintenance.json');
            @rmdir($project.'/var/updates');
            @rmdir($project.'/var');
            @rmdir($project);
        }
    }
}
