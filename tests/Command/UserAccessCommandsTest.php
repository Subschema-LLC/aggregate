<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\PermissionGroupsCommand;
use App\Command\UserAccessCommand;
use App\Command\UserDeleteCommand;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\Access\PermissionGroupCatalog;
use App\Security\Access\UserAccessResolver;
use App\Security\Access\UserAdministration;
use App\Service\WebsiteScopeCatalog;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Yaml\Yaml;

final class UserAccessCommandsTest extends TestCase
{
    private string $projectDir;
    /** @var list<User> */
    private array $users = [];
    /** @var list<User> */
    private array $removed = [];

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/aggregate-user-access-commands-'.bin2hex(random_bytes(8));
        mkdir($this->projectDir.'/config', 0700, true);
        $alex = (new User())->setUsername('alex')->setRoles([])->setPassword('unused');
        (new \ReflectionProperty($alex, 'id'))->setValue($alex, 1);
        $root = (new User())->setUsername('root')->setRoles([])->setAdmin(true)->setPassword('unused');
        (new \ReflectionProperty($root, 'id'))->setValue($root, 2);
        $this->users = [$alex, $root];
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectDir);
    }

    public function testPermissionGroupsAreManagedFromTheCommandLine(): void
    {
        $tester = new CommandTester($this->permissionGroupsCommand());
        self::assertSame(Command::SUCCESS, $tester->execute(['action' => 'create', '--name' => 'Client editors', '--website-group' => ['brand-a'], '--page' => ['websites:view', 'tag_manager:edit']]));
        self::assertStringContainsString('Created permission group "client-editors"', $tester->getDisplay());

        self::assertSame(Command::SUCCESS, $tester->execute(['action' => 'update', '--id' => 'client-editors', '--page' => ['consent_manager:edit']]));
        $group = Yaml::parseFile($this->projectDir.'/config/permission-groups.yaml')['permission_groups']['client-editors'];
        self::assertSame(['name' => 'Client editors', 'websites' => ['groups' => ['brand-a']], 'pages' => ['consent_manager' => 'edit']], $group, '--page replaces the pages; the websites stay');

        self::assertSame(Command::SUCCESS, $tester->execute(['action' => 'list']));
        self::assertStringContainsString('consent_manager:edit', $tester->getDisplay());
        self::assertSame(Command::SUCCESS, $tester->execute(['action' => 'pages']));
        self::assertStringContainsString('general_settings', $tester->getDisplay());

        self::assertSame(Command::INVALID, $tester->execute(['action' => 'create', '--name' => 'Bad', '--page' => ['users:edit']]));
        self::assertStringContainsString('Unknown page "users"', $tester->getDisplay());
        self::assertSame(Command::INVALID, $tester->execute(['action' => 'create', '--name' => 'Bad', '--page' => ['tag_manager']]));
        self::assertSame(Command::INVALID, $tester->execute(['action' => 'rename']));
    }

    public function testUserAccessIsShownChangedAndCleared(): void
    {
        $this->permissionGroupsCommand()->run(new \Symfony\Component\Console\Input\ArrayInput(['action' => 'create', '--name' => 'Editors', '--page' => ['tag_manager:edit']]), new \Symfony\Component\Console\Output\NullOutput());
        $tester = new CommandTester($this->userAccessCommand());

        self::assertSame(Command::SUCCESS, $tester->execute(['username' => 'alex']));
        self::assertStringContainsString('Pages: none', $tester->getDisplay());

        self::assertSame(Command::SUCCESS, $tester->execute(['username' => 'alex', '--group' => ['editors'], '--website' => ['token-a'], '--page' => ['setup:view']]));
        $alex = $this->users[0];
        self::assertSame(['permission_groups' => ['editors'], 'websites' => ['all' => false, 'groups' => [], 'sites' => ['token-a']], 'pages' => ['setup' => 'view']], $alex->getAccess());
        self::assertStringContainsString('Permission group: Editors', $tester->getDisplay());

        self::assertSame(Command::SUCCESS, $tester->execute(['username' => 'alex', '--page' => ['setup:none'], '--all-websites' => true, '--remove-group' => ['editors']]));
        self::assertSame(['permission_groups' => [], 'websites' => ['all' => true, 'groups' => [], 'sites' => ['token-a']], 'pages' => []], $alex->getAccess());

        self::assertSame(Command::SUCCESS, $tester->execute(['username' => 'alex', '--clear' => true, '--page' => ['branding:view']]));
        self::assertSame(['permission_groups' => [], 'websites' => ['all' => false, 'groups' => [], 'sites' => []], 'pages' => ['branding' => 'view']], $alex->getAccess());

        self::assertSame(Command::INVALID, $tester->execute(['username' => 'alex', '--group' => ['ghosts']]));
        self::assertStringContainsString('Permission group "ghosts" does not exist', $tester->getDisplay());
        self::assertSame(Command::FAILURE, $tester->execute(['username' => 'nobody']));
    }

    public function testTheLastAdministratorStays(): void
    {
        $tester = new CommandTester($this->userAccessCommand());
        self::assertSame(Command::INVALID, $tester->execute(['username' => 'root', '--standard' => true]));
        self::assertStringContainsString('at least one administrator', $tester->getDisplay());

        self::assertSame(Command::SUCCESS, $tester->execute(['username' => 'alex', '--admin' => true]));
        self::assertTrue($this->users[0]->isAdmin());
        self::assertSame(Command::SUCCESS, $tester->execute(['username' => 'root', '--standard' => true]));
        self::assertFalse($this->users[1]->isAdmin());

        $delete = new CommandTester(new UserDeleteCommand($this->repository(), $this->administration()));
        self::assertSame(Command::INVALID, $delete->execute(['username' => 'alex'], ['interactive' => false]), 'alex is now the only administrator');
        self::assertSame(Command::SUCCESS, $delete->execute(['username' => 'root'], ['interactive' => false]));
        self::assertSame([$this->users[1]], $this->removed);

        $delete->setInputs(['no']);
        self::assertSame(Command::FAILURE, $delete->execute(['username' => 'root']));
        self::assertStringContainsString('Nothing was deleted', $delete->getDisplay());
    }

    private function permissionGroupsCommand(): PermissionGroupsCommand
    {
        return new PermissionGroupsCommand($this->catalog(), $this->administration());
    }

    private function userAccessCommand(): UserAccessCommand
    {
        return new UserAccessCommand($this->repository(), $this->administration(), new UserAccessResolver($this->catalog(), new WebsiteScopeCatalog($this->projectDir, 'test')));
    }

    private function catalog(): PermissionGroupCatalog
    {
        return new PermissionGroupCatalog($this->projectDir, 'test');
    }

    private function repository(): UserRepository
    {
        $repository = $this->createStub(UserRepository::class);
        $repository->method('findAll')->willReturnCallback(fn (): array => $this->users);
        $repository->method('findOneBy')->willReturnCallback(fn (array $criteria): ?User => array_values(array_filter(
            $this->users,
            static fn (User $user): bool => $user->getUsername() === $criteria['username'],
        ))[0] ?? null);

        return $repository;
    }

    private function administration(): UserAdministration
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('remove')->willReturnCallback(function (object $user): void {
            $this->removed[] = $user;
        });

        return new UserAdministration($em, $this->repository(), $this->createStub(UserPasswordHasherInterface::class), $this->catalog());
    }
}
