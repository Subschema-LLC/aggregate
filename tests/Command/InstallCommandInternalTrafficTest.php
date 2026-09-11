<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\InstallCommand;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\AggregateConfigLoader;
use App\Service\AppBranding;
use App\Service\InternalTrafficSettings;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Yaml\Yaml;

final class InstallCommandInternalTrafficTest extends TestCase
{
    private string $projectDir;
    private array $savedEnvironment;
    private array $savedServer;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/aggregate-install-command-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->projectDir.'/config', 0700, true));
        $this->savedEnvironment = $_ENV;
        $this->savedServer = $_SERVER;
        unset($_ENV['INTERNAL_TRAFFIC_SHARE_TOKEN'], $_SERVER['INTERNAL_TRAFFIC_SHARE_TOKEN']);
    }

    protected function tearDown(): void
    {
        $_ENV = $this->savedEnvironment;
        $_SERVER = $this->savedServer;
        @unlink($this->projectDir.'/config/aggregate.yaml');
        @rmdir($this->projectDir.'/config');
        @rmdir($this->projectDir);
    }

    #[DataProvider('installationTokens')]
    public function testInstallInitializesYamlTokenWithoutReplacingSuppliedValues(
        string $configuredToken,
        ?string $environmentToken,
    ): void {
        $config = $this->writeConfig($configuredToken);
        if ($environmentToken !== null) {
            $_ENV['INTERNAL_TRAFFIC_SHARE_TOKEN'] = $environmentToken;
        }
        $tester = $this->tester($config);

        $status = $tester->execute(['--username' => 'admin', '--password' => 'correct-password']);

        self::assertSame(Command::SUCCESS, $status);
        $persisted = Yaml::parseFile($this->projectDir.'/config/aggregate.yaml');
        self::assertTrue($persisted['installed']);
        $token = $persisted[InternalTrafficSettings::TOKEN_KEY];
        if ($configuredToken === '' && $environmentToken === null) {
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $token);
        } else {
            self::assertSame($configuredToken, $token);
        }
        self::assertSame($environmentToken ?? $token, (new InternalTrafficSettings($config))->getShareToken());
    }

    public static function installationTokens(): iterable
    {
        yield 'fresh example with empty token' => ['', null];
        yield 'preconfigured team token' => [str_repeat('a', 64), null];
        yield 'token supplied by environment' => ['', str_repeat('b', 64)];
        yield 'environment explicitly disables links' => ['', ''];
    }

    public function testAlreadyInstalledCommandLeavesRevokedTokenUnchanged(): void
    {
        $config = $this->writeConfig('');
        $original = file_get_contents($this->projectDir.'/config/aggregate.yaml');
        $tester = $this->tester($config, alreadyInstalled: true);

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertSame($original, file_get_contents($this->projectDir.'/config/aggregate.yaml'));
    }

    public function testRejectedPasswordDoesNotGenerateToken(): void
    {
        $config = $this->writeConfig('');
        $original = file_get_contents($this->projectDir.'/config/aggregate.yaml');
        $tester = $this->tester($config, createsUser: false);

        self::assertSame(Command::FAILURE, $tester->execute(['--username' => 'admin', '--password' => 'short']));
        self::assertSame($original, file_get_contents($this->projectDir.'/config/aggregate.yaml'));
    }

    private function writeConfig(string $token): AggregateConfigLoader
    {
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump([
            'installed' => false,
            InternalTrafficSettings::TOKEN_KEY => $token,
        ]));

        return new AggregateConfigLoader($this->projectDir, 'test');
    }

    private function tester(
        AggregateConfigLoader $config,
        bool $alreadyInstalled = false,
        bool $createsUser = true,
    ): CommandTester {
        $createsUser = $createsUser && !$alreadyInstalled;
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($createsUser ? self::once() : self::never())
            ->method('persist')
            ->with(self::callback(static fn (User $user): bool =>
                $user->getUsername() === 'admin' && in_array('ROLE_ADMIN', $user->getRoles(), true)));
        $entityManager->expects($createsUser ? self::once() : self::never())->method('flush');
        $repository = $this->createStub(UserRepository::class);
        $repository->method('count')->willReturn($alreadyInstalled ? 1 : 0);
        $passwordHasher = $this->createStub(UserPasswordHasherInterface::class);
        $passwordHasher->method('hashPassword')->willReturn('hashed-password');

        return new CommandTester(new InstallCommand(
            $entityManager,
            $repository,
            $passwordHasher,
            $config,
            new AppBranding($config, $this->projectDir),
            new InternalTrafficSettings($config),
        ));
    }
}
