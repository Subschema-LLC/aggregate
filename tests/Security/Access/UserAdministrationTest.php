<?php

declare(strict_types=1);

namespace App\Tests\Security\Access;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\Access\PermissionGroupCatalog;
use App\Security\Access\UserAdministration;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Yaml\Yaml;

final class UserAdministrationTest extends TestCase
{
    private string $projectDir;
    /** @var list<User> */
    private array $users = [];
    private int $flushes = 0;
    /** @var list<User> */
    private array $removed = [];

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/aggregate-user-administration-'.bin2hex(random_bytes(8));
        mkdir($this->projectDir.'/config', 0700, true);
        file_put_contents($this->projectDir.'/config/permission-groups.yaml', Yaml::dump(['permission_groups' => ['editors' => ['name' => 'Editors', 'pages' => ['tag_manager' => 'edit']]]], 4));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectDir);
    }

    public function testCreatesStandardUsersAndAdministrators(): void
    {
        $administration = $this->administration();
        $user = $administration->create(' alex ', 'long-enough', false, ['permission_groups' => ['editors'], 'pages' => ['setup' => 'view']], 'Alex A.', 'alex@example.test');

        self::assertSame('alex', $user->getUsername());
        self::assertFalse($user->isAdmin());
        self::assertSame(['ROLE_USER'], $user->getRoles());
        self::assertSame('hashed:long-enough', $user->getPassword());
        self::assertSame(['editors'], $user->getAccess()['permission_groups']);
        self::assertSame(['setup' => 'view'], $user->getAccess()['pages']);
        self::assertSame('Alex A.', $user->getLabel());
        self::assertSame(1, $this->flushes);

        self::assertTrue($administration->create('root', 'long-enough', true)->isAdmin());
    }

    public function testRefusesDuplicatesShortPasswordsAndUnknownGroups(): void
    {
        $this->users[] = $this->user('alex', false);
        foreach ([
            ['alex', 'long-enough', [], 'already exists'],
            ['sam', 'short', [], 'at least 8'],
            ['sam', 'long-enough', ['permission_groups' => ['ghosts']], 'Permission group "ghosts" does not exist'],
            ['sam', 'long-enough', ['pages' => ['users' => 'edit']], 'Unknown page "users"'],
            ['', 'long-enough', [], 'Enter a username'],
        ] as [$username, $password, $access, $message]) {
            try {
                $this->administration()->create($username, $password, false, $access);
                self::fail($message);
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString($message, $error->getMessage());
            }
        }
        self::assertSame(0, $this->flushes);
    }

    public function testKeepsAtLeastOneAdministratorAndNobodyLocksThemselvesOut(): void
    {
        $root = $this->user('root', true, 1);
        $alex = $this->user('alex', false, 2);
        $this->users = [$root, $alex];
        $administration = $this->administration();

        foreach ([
            static fn () => $administration->update($root, 'root', null, null, false, [], $alex),
            static fn () => $administration->delete($root, $alex),
        ] as $change) {
            try {
                $change();
                self::fail('The last administrator must stay.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('at least one administrator', $error->getMessage());
            }
        }

        $administration->update($alex, 'alex', null, null, true, [], $root);
        self::assertTrue($alex->isAdmin());
        try {
            $administration->update($root, 'root', null, null, false, [], $root);
            self::fail('Administrators cannot demote themselves.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('your own administrator access', $error->getMessage());
        }
        try {
            $administration->delete($root, $root);
            self::fail('Administrators cannot delete themselves.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('your own account', $error->getMessage());
        }
        // With a second administrator, one can be made a standard user by the other.
        $administration->update($root, 'root', 'Root', 'root@example.test', false, ['pages' => ['branding' => 'view']], $alex);
        self::assertFalse($root->isAdmin());
        self::assertSame(['branding' => 'view'], $root->getAccess()['pages']);
        $administration->delete($root, $alex);
        self::assertSame([$root], $this->removed);
    }

    public function testRenamingToAnotherUsersNameIsRefused(): void
    {
        $alex = $this->user('alex', false, 1);
        $this->users = [$alex, $this->user('sam', false, 2)];
        $this->expectExceptionMessage('User "sam" already exists.');
        $this->administration()->update($alex, 'sam', null, null, false, []);
    }

    public function testPasswordsProfilesAndDeletedGroups(): void
    {
        $alex = $this->user('alex', false, 1);
        $alex->setAccess(['permission_groups' => ['editors']]);
        $sam = $this->user('sam', false, 2);
        $sam->setAccess(['permission_groups' => ['editors'], 'pages' => ['setup' => 'view']]);
        $this->users = [$alex, $sam, $this->user('root', true, 3)];
        $administration = $this->administration();

        self::assertTrue($administration->isCurrentPassword($alex, 'current-secret'));
        self::assertFalse($administration->isCurrentPassword($alex, 'wrong'));
        self::assertFalse($administration->isCurrentPassword($alex, ''));
        $administration->changePassword($alex, 'new-long-secret');
        self::assertSame('hashed:new-long-secret', $alex->getPassword());

        $administration->updateProfile($alex, '  Alex  ', '');
        self::assertSame('Alex', $alex->getDisplayName());
        self::assertNull($alex->getEmail());
        try {
            $administration->updateProfile($alex, 'Alex', 'not-an-email');
            self::fail('A malformed email must be refused.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('valid email', $error->getMessage());
        }

        self::assertSame(['editors' => 2], $administration->permissionGroupMembers());
        self::assertSame(2, $administration->forgetPermissionGroup('editors'));
        self::assertSame([], $alex->getAccess()['permission_groups']);
        self::assertSame(['setup' => 'view'], $sam->getAccess()['pages'], 'other grants stay');
    }

    private function user(string $username, bool $admin, int $id = 0): User
    {
        $user = (new User())->setUsername($username)->setRoles([])->setAdmin($admin)->setPassword('hashed:current-secret');
        if ($id > 0) {
            (new \ReflectionProperty($user, 'id'))->setValue($user, $id);
        }

        return $user;
    }

    private function administration(): UserAdministration
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('flush')->willReturnCallback(function (): void {
            ++$this->flushes;
        });
        $em->method('remove')->willReturnCallback(function (object $user): void {
            $this->removed[] = $user;
        });
        $repository = $this->createStub(UserRepository::class);
        $repository->method('findAll')->willReturnCallback(fn (): array => $this->users);
        $repository->method('findOneBy')->willReturnCallback(fn (array $criteria): ?User => array_values(array_filter(
            $this->users,
            static fn (User $user): bool => $user->getUsername() === $criteria['username'],
        ))[0] ?? null);
        $hasher = $this->createStub(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturnCallback(static fn (User $user, string $password): string => 'hashed:'.$password);
        $hasher->method('isPasswordValid')->willReturnCallback(static fn (User $user, string $password): bool => $user->getPassword() === 'hashed:'.$password);

        return new UserAdministration($em, $repository, $hasher, new PermissionGroupCatalog($this->projectDir, 'test'));
    }
}
