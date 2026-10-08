<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\CreateUserCommand;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class CreateUserCommandTest extends TestCase
{
    public function testFailsInNonInteractiveModeWithoutUsername(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $repo = $this->createStub(UserRepository::class);
        $hasher = $this->createStub(UserPasswordHasherInterface::class);

        $tester = new CommandTester(new CreateUserCommand($em, $repo, $hasher));
        $status = $tester->execute([
            '--password' => 'secure-password',
            '--role' => ['ROLE_ANALYST'],
        ], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('Username argument is required in non-interactive mode.', $tester->getDisplay());
    }

    public function testFailsInNonInteractiveModeWithoutPassword(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $repo = $this->createStub(UserRepository::class);
        $hasher = $this->createStub(UserPasswordHasherInterface::class);

        $tester = new CommandTester(new CreateUserCommand($em, $repo, $hasher));
        $status = $tester->execute([
            'username' => 'analyst',
            '--role' => ['ROLE_ANALYST'],
        ], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('The --password option is required in non-interactive mode.', $tester->getDisplay());
    }

    public function testFailsInNonInteractiveModeWithoutRole(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $repo = $this->createStub(UserRepository::class);
        $hasher = $this->createStub(UserPasswordHasherInterface::class);

        $tester = new CommandTester(new CreateUserCommand($em, $repo, $hasher));
        $status = $tester->execute([
            'username' => 'analyst',
            '--password' => 'secure-password',
        ], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('At least one --role option is required in non-interactive mode.', $tester->getDisplay());
    }

    public function testFailsWhenPasswordIsTooShort(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $repo = $this->createStub(UserRepository::class);
        $hasher = $this->createStub(UserPasswordHasherInterface::class);

        $tester = new CommandTester(new CreateUserCommand($em, $repo, $hasher));
        $status = $tester->execute([
            'username' => 'analyst',
            '--password' => 'short',
            '--role' => ['ROLE_ANALYST'],
        ], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('Password must be at least 8 characters.', $tester->getDisplay());
    }

    public function testFailsWhenUsernameAlreadyExistsInNonInteractiveMode(): void
    {
        $existing = new User();
        $existing->setUsername('analyst');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');

        $repo = $this->createStub(UserRepository::class);
        $repo->method('findOneBy')->with(['username' => 'analyst'])->willReturn($existing);

        $hasher = $this->createStub(UserPasswordHasherInterface::class);

        $tester = new CommandTester(new CreateUserCommand($em, $repo, $hasher));
        $status = $tester->execute([
            'username' => 'analyst',
            '--password' => 'secure-password',
            '--role' => ['ROLE_ANALYST'],
        ], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('User "analyst" already exists.', $tester->getDisplay());
    }

    public function testCreatesUserSuccessfullyWithOptions(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist')->with(self::isInstanceOf(User::class));
        $em->expects(self::once())->method('flush');

        $repo = $this->createStub(UserRepository::class);
        $repo->method('findOneBy')->with(['username' => 'analyst'])->willReturn(null);

        $hasher = $this->createStub(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturnCallback(static function (User $user, string $password): string {
            TestCase::assertSame('analyst', $user->getUsername());
            TestCase::assertSame('secure-password', $password);
            TestCase::assertContains('ROLE_ANALYST', $user->getRoles());
            TestCase::assertContains('ROLE_USER', $user->getRoles());

            return 'hashed-password';
        });

        $tester = new CommandTester(new CreateUserCommand($em, $repo, $hasher));
        $status = $tester->execute([
            'username' => 'analyst',
            '--password' => 'secure-password',
            '--role' => ['ROLE_ANALYST'],
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('User "analyst" created successfully with roles: ROLE_ANALYST, ROLE_USER.', $tester->getDisplay());
    }

    public function testPromptsForDifferentUsernameWhenConflictExists(): void
    {
        $existing = new User();
        $existing->setUsername('existing');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist')->with(self::isInstanceOf(User::class));
        $em->expects(self::once())->method('flush');

        $repo = $this->createStub(UserRepository::class);
        $repo->method('findOneBy')->willReturnCallback(static fn (array $criteria): ?User => $criteria['username'] === 'existing' ? $existing : null);

        $hasher = $this->createStub(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturn('hashed-password');

        $application = new Application();
        $application->add(new CreateUserCommand($em, $repo, $hasher));
        $tester = new CommandTester($application->find('app:user:create'));
        $tester->setInputs(['new-user', 'secure-password', 'ROLE_MANAGER']);
        $status = $tester->execute(['username' => 'existing']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('already exists', $tester->getDisplay());
        self::assertStringContainsString('User "new-user" created successfully with roles: ROLE_MANAGER, ROLE_USER.', $tester->getDisplay());
    }
}
