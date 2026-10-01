<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\DeployedUpdateCommand;
use App\Service\AggregateConfigLoader;
use App\Service\ApplicationUpdateService;
use App\Service\Update\ApplicationUpdater;
use App\Service\Update\DeploymentAction;
use App\Service\UpdateSettings;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class DeployedUpdateCommandTest extends TestCase
{
    public function testChoosesTheMethodWhenNoneIsChosenAndRecordsTheDeployment(): void
    {
        $updater = $this->createMock(ApplicationUpdater::class);
        $updater->method('methodChangeProblem')->willReturn(null);
        $updater->expects(self::once())->method('start')->with([
            'deployed' => true,
            'commit' => str_repeat('c', 40),
            'repository' => realpath(sys_get_temp_dir()),
            'force' => true,
            'database_backup_confirmed' => true,
        ])->willReturnCallback(static function (array $options, callable $output): array {
            $output('Database migrations are complete.', 'info');

            return ['status' => 'completed', 'type' => 'deployment'];
        });
        $tester = $this->tester(null, $updater, saved: ['updates_method' => 'deployment']);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--commit' => str_repeat('c', 40),
            '--git-dir' => sys_get_temp_dir(),
            '--force' => true,
            '--database-backup-confirmed' => true,
        ]));
        $display = preg_replace('/\s+/', ' ', str_replace(' ! ', ' ', $tester->getDisplay()));
        self::assertStringContainsString('now marked as deployed another way', $display);
        self::assertStringContainsString('Database migrations are complete.', $display);
        self::assertStringContainsString('post-deployment steps are complete', $display);
    }

    public function testRefusesWhenAnotherMethodIsChosen(): void
    {
        $updater = $this->createMock(ApplicationUpdater::class);
        $updater->expects(self::never())->method('start');
        $tester = $this->tester('release', $updater);

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('app:updates:method deployment', preg_replace('/\s+/', ' ', $tester->getDisplay()));
    }

    public function testAlreadyFinishedFilesSucceedWithoutRunningAgain(): void
    {
        $updater = $this->createStub(ApplicationUpdater::class);
        $updater->method('start')->willReturn(['status' => 'up_to_date', 'message' => 'The post-deployment steps already ran for these files.']);

        $tester = $this->tester('deployment', $updater);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('already ran', $tester->getDisplay());
    }

    public function testShowActionPrintsTheCommandsWithoutChangingAnything(): void
    {
        $updater = $this->createMock(ApplicationUpdater::class);
        $updater->expects(self::never())->method('start');
        $action = $this->createStub(DeploymentAction::class);
        $action->method('build')->willReturn([
            'commands' => [], 'script' => "rm -rf /srv/site/var/cache/prod\ncomposer install --working-dir=/srv/site\nphp /srv/site/bin/console app:updates:deployed --git-dir=/path/to/the/deployment/repository.git",
            'php' => 'php', 'composer' => 'composer', 'composer_found' => false, 'repository' => null, 'repository_source' => null,
            'candidates' => [], 'backup_flag' => true, 'environment' => 'prod', 'project_dir' => '/srv/site',
        ]);
        $tester = $this->tester(null, $updater, action: $action);

        self::assertSame(Command::SUCCESS, $tester->execute(['--show-action' => true]));
        $display = preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('rm -rf /srv/site/var/cache/prod', $tester->getDisplay());
        self::assertStringContainsString('Composer was not found here', $display);
        self::assertStringContainsString('Replace the --git-dir placeholder', $display);
        self::assertStringContainsString('--database-backup-confirmed states', $display);
    }

    /** @param array<string, string>|null $saved */
    private function tester(?string $method, ApplicationUpdater $updater, ?array $saved = null, ?DeploymentAction $action = null): CommandTester
    {
        $config = $this->createMock(AggregateConfigLoader::class);
        $config->method('all')->willReturn([]);
        if ($saved !== null) {
            $config->expects(self::once())->method('setMany')->with($saved);
        } else {
            $config->expects(self::never())->method('setMany');
        }
        $updates = $this->createStub(ApplicationUpdateService::class);
        $updates->method('source')->willReturn([
            'source' => $method === 'deployment' ? 'deployment' : 'release', 'reason' => 'Test.',
            'method' => $method, 'detected' => 'release', 'mismatch' => null,
        ]);

        return new CommandTester(new DeployedUpdateCommand($updater, $updates, new UpdateSettings($config), $action ?? $this->createStub(DeploymentAction::class)));
    }
}
