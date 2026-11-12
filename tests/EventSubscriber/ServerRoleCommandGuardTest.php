<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\ServerRoleCommandGuard;
use App\Service\Cluster\ServerRole;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class ServerRoleCommandGuardTest extends TestCase
{
    private const SHARED_DATABASE = 'postgresql://aggregate:secret@10.0.0.5:5432/aggregate?serverVersion=16';

    public function testACollectionServerRefusesMigrationsAndNamesTheAdministrationServer(): void
    {
        [$status, $output, $ran] = $this->runAs('collection', 'doctrine:migrations:migrate', 'https://admin.example.com');

        self::assertSame(ConsoleCommandEvent::RETURN_CODE_DISABLED, $status);
        self::assertFalse($ran, 'The refused command must not run.');
        self::assertStringContainsString('This is a collection server: doctrine:migrations:migrate runs on the administration server (https://admin.example.com), not here.', $output);
    }

    public function testAWorkerServerRunsConsumersButNotTheScheduledTask(): void
    {
        self::assertTrue($this->runAs('worker', 'messenger:consume')[2]);
        [$status, $output, $ran] = $this->runAs('worker', 'app:scheduler:run');
        self::assertSame(ConsoleCommandEvent::RETURN_CODE_DISABLED, $status);
        self::assertFalse($ran);
        self::assertStringContainsString('app:scheduler:run runs on the administration server', $output);
    }

    public function testEveryRoleClearsItsCacheAndTheAdministeringRolesRunEverything(): void
    {
        foreach (ServerRole::ROLES as $role) {
            self::assertTrue($this->runAs($role, 'cache:clear')[2], $role.' should clear its cache');
        }
        foreach (['all', 'administration'] as $role) {
            self::assertTrue($this->runAs($role, 'app:install')[2], $role.' should run app:install');
        }
    }

    /** @return array{0: int, 1: string, 2: bool} exit status, output, whether the command ran */
    private function runAs(string $role, string $name, ?string $administrationUrl = null): array
    {
        $ran = false;
        $command = (new Command($name))->setCode(static function () use (&$ran): int {
            $ran = true;

            return Command::SUCCESS;
        });
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new ServerRoleCommandGuard(ServerRole::of($role, self::SHARED_DATABASE, $administrationUrl)));
        $application = new Application();
        $application->setAutoExit(false);
        $application->setDispatcher($dispatcher);
        $application->addCommand($command);
        $output = new BufferedOutput();
        $status = $application->run(new ArrayInput(['command' => $name]), $output);

        return [$status, $output->fetch(), $ran];
    }
}
