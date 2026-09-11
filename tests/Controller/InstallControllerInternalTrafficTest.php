<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\InstallController;
use App\Entity\User;
use App\Service\AggregateConfigLoader;
use App\Service\InstallationChecker;
use App\Service\InternalTrafficSettings;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\CommandLoader\FactoryCommandLoader;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Yaml\Yaml;
use Twig\Environment;

final class InstallControllerInternalTrafficTest extends TestCase
{
    private string $projectDir;
    private array $savedEnvironment;
    private array $savedServer;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/aggregate-install-controller-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->projectDir.'/config', 0700, true));
        $this->savedEnvironment = $_ENV;
        $this->savedServer = $_SERVER;
        unset($_ENV['INTERNAL_TRAFFIC_SHARE_TOKEN'], $_SERVER['INTERNAL_TRAFFIC_SHARE_TOKEN']);
        $_ENV['DASHBOARD_ENABLED'] = 'true';
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
    public function testSuccessfulWebInstallPersistsDefaultTokenAndPreservesOverrides(
        string $configuredToken,
        ?string $environmentToken,
    ): void {
        $config = $this->writeConfig($configuredToken);
        if ($environmentToken !== null) {
            $_ENV['INTERNAL_TRAFFIC_SHARE_TOKEN'] = $environmentToken;
        }
        $controller = $this->controller($config);

        $response = $controller->executeInstall($this->installRequest());

        self::assertSame('/app_login', $response->headers->get('Location'));
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

    public function testOpeningInstallerDoesNotGenerateToken(): void
    {
        $config = $this->writeConfig('');
        $original = file_get_contents($this->projectDir.'/config/aggregate.yaml');
        $controller = $this->controller($config, runsMigrations: false, rendersInstaller: true);

        self::assertSame(200, $controller->install()->getStatusCode());
        self::assertSame($original, file_get_contents($this->projectDir.'/config/aggregate.yaml'));
    }

    public function testAlreadyInstalledRequestDoesNotRegenerateRevokedToken(): void
    {
        $config = $this->writeConfig('');
        $original = file_get_contents($this->projectDir.'/config/aggregate.yaml');
        $controller = $this->controller($config, alreadyInstalled: true, runsMigrations: false);

        self::assertSame('/app_home', $controller->executeInstall($this->installRequest())->headers->get('Location'));
        self::assertSame($original, file_get_contents($this->projectDir.'/config/aggregate.yaml'));
    }

    public function testFailedMigrationDoesNotGenerateTokenOrCreateAdmin(): void
    {
        $config = $this->writeConfig('');
        $controller = $this->controller($config, migrationResult: Command::FAILURE);

        self::assertSame('/app_install', $controller->executeInstall($this->installRequest())->headers->get('Location'));
        $persisted = Yaml::parseFile($this->projectDir.'/config/aggregate.yaml');
        self::assertFalse($persisted['installed']);
        self::assertSame('', $persisted[InternalTrafficSettings::TOKEN_KEY]);
    }

    private function writeConfig(string $token): AggregateConfigLoader
    {
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump([
            'installed' => false,
            InternalTrafficSettings::TOKEN_KEY => $token,
        ]));

        return new AggregateConfigLoader($this->projectDir, 'test');
    }

    private function installRequest(): Request
    {
        return Request::create('/install/execute', 'POST', [
            'admin_username' => 'admin',
            'admin_password' => 'correct-password',
            'js_namespace' => 'Aggregate',
        ]);
    }

    private function controller(
        AggregateConfigLoader $config,
        bool $alreadyInstalled = false,
        bool $runsMigrations = true,
        int $migrationResult = Command::SUCCESS,
        bool $rendersInstaller = false,
    ): InstallController {
        $checker = $this->createStub(InstallationChecker::class);
        $checker->method('isInstalled')->willReturn($alreadyInstalled);
        $checker->method('isConfigValid')->willReturn(true);
        $createsUser = !$alreadyInstalled && $runsMigrations && $migrationResult === Command::SUCCESS;
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($createsUser ? self::once() : self::never())
            ->method('persist')
            ->with(self::callback(static fn (User $user): bool =>
                $user->getUsername() === 'admin' && in_array('ROLE_ADMIN', $user->getRoles(), true)));
        $entityManager->expects($createsUser ? self::once() : self::never())->method('flush');
        $passwordHasher = $this->createStub(UserPasswordHasherInterface::class);
        $passwordHasher->method('hashPassword')->willReturn('hashed-password');

        $kernelContainer = new Container();
        $kernelContainer->set('event_dispatcher', new EventDispatcher());
        $kernelContainer->set('console.command_loader', new FactoryCommandLoader([
            'doctrine:migrations:migrate' => static fn (): Command =>
                (new Command('doctrine:migrations:migrate'))->setCode(static fn (): int => $migrationResult),
        ]));
        $kernel = $this->createMock(KernelInterface::class);
        $kernel->expects($runsMigrations ? self::once() : self::never())->method('boot');
        $kernel->method('getEnvironment')->willReturn('test');
        $kernel->method('getBundles')->willReturn([]);
        $kernel->method('getContainer')->willReturn($kernelContainer);

        $controller = new InstallController(
            $checker,
            $config,
            $entityManager,
            $passwordHasher,
            $kernel,
            new InternalTrafficSettings($config),
        );
        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(static fn (string $route): string => '/'.$route);
        $request = Request::create('/install');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $requestStack = new RequestStack();
        $requestStack->push($request);
        $container = new Container();
        $container->set('router', $router);
        $container->set('request_stack', $requestStack);
        if ($rendersInstaller) {
            $twig = $this->createMock(Environment::class);
            $twig->expects(self::once())->method('render')->with('install/index.html.twig', [])->willReturn('installer');
            $container->set('twig', $twig);
        }
        $controller->setContainer($container);

        return $controller;
    }
}
