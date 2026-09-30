<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ResetPasswordCommand;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ResetPasswordCommandTest extends TestCase
{
    public function testFailsWhenNoUsersExist(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');

        $repo = $this->createStub(UserRepository::class);
        $repo->method('count')->willReturn(0);

        $hasher = $this->createStub(UserPasswordHasherInterface::class);

        $tester = new CommandTester(new ResetPasswordCommand($em, $repo, $hasher));
        $status = $tester->execute(['username' => 'admin', '--password' => 'new-secure-password']);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('No users found in the database', $tester->getDisplay());
    }

    public function testFailsWhenUserNotFound(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');

        $repo = $this->createStub(UserRepository::class);
        $repo->method('count')->willReturn(1);
        $repo->method('findOneBy')->with(['username' => 'nonexistent'])->willReturn(null);

        $hasher = $this->createStub(UserPasswordHasherInterface::class);

        $tester = new CommandTester(new ResetPasswordCommand($em, $repo, $hasher));
        $status = $tester->execute(['username' => 'nonexistent', '--password' => 'new-secure-password']);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('User "nonexistent" not found.', $tester->getDisplay());
    }

    public function testFailsWhenPasswordIsTooShort(): void
    {
        $user = new User();
        $user->setUsername('admin');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');

        $repo = $this->createStub(UserRepository::class);
        $repo->method('count')->willReturn(1);
        $repo->method('findOneBy')->with(['username' => 'admin'])->willReturn($user);

        $hasher = $this->createStub(UserPasswordHasherInterface::class);

        $tester = new CommandTester(new ResetPasswordCommand($em, $repo, $hasher));
        $status = $tester->execute(['username' => 'admin', '--password' => 'short']);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('Password must be at least 8 characters.', $tester->getDisplay());
    }

    public function testFailsInNonInteractiveModeWithoutUsername(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $repo = $this->createStub(UserRepository::class);
        $repo->method('count')->willReturn(1);
        $hasher = $this->createStub(UserPasswordHasherInterface::class);

        $tester = new CommandTester(new ResetPasswordCommand($em, $repo, $hasher));
        $status = $tester->execute([], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('Username argument is required in non-interactive mode.', $tester->getDisplay());
    }

    public function testFailsInNonInteractiveModeWithoutPassword(): void
    {
        $user = new User();
        $user->setUsername('admin');

        $em = $this->createMock(EntityManagerInterface::class);
        $repo = $this->createStub(UserRepository::class);
        $repo->method('count')->willReturn(1);
        $repo->method('findOneBy')->with(['username' => 'admin'])->willReturn($user);
        $hasher = $this->createStub(UserPasswordHasherInterface::class);

        $tester = new CommandTester(new ResetPasswordCommand($em, $repo, $hasher));
        $status = $tester->execute(['username' => 'admin'], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('The --password option is required in non-interactive mode.', $tester->getDisplay());
    }

    public function testResetsPasswordSuccessfullyWithOptions(): void
    {
        $user = new User();
        $user->setUsername('admin');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist')->with($user);
        $em->expects(self::once())->method('flush');

        $repo = $this->createStub(UserRepository::class);
        $repo->method('count')->willReturn(1);
        $repo->method('findOneBy')->with(['username' => 'admin'])->willReturn($user);

        $hasher = $this->createStub(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->with($user, 'new-secure-password')->willReturn('hashed-pw-result');

        $tester = new CommandTester(new ResetPasswordCommand($em, $repo, $hasher));
        $status = $tester->execute(['username' => 'admin', '--password' => 'new-secure-password']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame('hashed-pw-result', $user->getPassword());
        self::assertStringContainsString('Password for user "admin" has been reset successfully.', $tester->getDisplay());
    }

    public function testResetsPasswordInteractively(): void
    {
        $user = new User();
        $user->setUsername('admin');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist')->with($user);
        $em->expects(self::once())->method('flush');

        $repo = $this->createStub(UserRepository::class);
        $repo->method('count')->willReturn(1);
        $repo->method('findOneBy')->with(['username' => 'admin'])->willReturn($user);

        $hasher = $this->createStub(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->with($user, 'interactive-secret-pass')->willReturn('hashed-interactive');

        $tester = new CommandTester(new ResetPasswordCommand($em, $repo, $hasher));
        $tester->setInputs(['admin', 'interactive-secret-pass']);
        $status = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame('hashed-interactive', $user->getPassword());
        self::assertStringContainsString('Password for user "admin" has been reset successfully.', $tester->getDisplay());
    }
}
