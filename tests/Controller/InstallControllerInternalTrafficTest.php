<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\InstallController;
use App\Entity\User;
use App\Service\AggregateConfigLoader;
use App\Service\InstallationChecker;
use App\Service\InternalTrafficSettings;
use App\Setup\SetupCode;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\CommandLoader\FactoryCommandLoader;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Yaml\Yaml;
use Twig\Environment;

final class InstallControllerInternalTrafficTest extends TestCase
{
    private string $projectDir;
    private array $savedEnvironment;
    private array $savedServer;
    private Session $session;
    private array $commandsRun = [];

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
        @unlink($this->projectDir.'/'.SetupCode::FILE);
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
        self::assertSame(['doctrine:migrations:migrate', 'app:analytics:glossary:sync'], $this->commandsRun);
        self::assertSame([], $this->session->getFlashBag()->get('warning'));
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

        self::assertSame(200, $controller->install(Request::create('https://analytics.example.test/install'))->getStatusCode());
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
        self::assertSame(['doctrine:migrations:migrate'], $this->commandsRun);
        self::assertSame('', $persisted[InternalTrafficSettings::TOKEN_KEY]);
        self::assertSame(
            ['Migration failed. Run php bin/console doctrine:migrations:migrate on the server for diagnostics.'],
            $this->session->getFlashBag()->get('error'),
        );
    }

    public function testGlossaryFailureWarnsWithoutPreventingInstallation(): void
    {
        $controller = $this->controller($this->writeConfig(''), glossaryResult: Command::INVALID);

        self::assertSame('/app_login', $controller->executeInstall($this->installRequest())->headers->get('Location'));
        self::assertSame(['doctrine:migrations:migrate', 'app:analytics:glossary:sync'], $this->commandsRun);
        self::assertTrue(Yaml::parseFile($this->projectDir.'/config/aggregate.yaml')['installed']);
        self::assertSame([
            'Database setup completed, but the BI glossary was not updated. Run php bin/console app:analytics:glossary:sync on the server for diagnostics.',
        ], $this->session->getFlashBag()->get('warning'));
    }

    public function testInstallerDoesNotExposeExceptionDetailsToTheBrowser(): void
    {
        $controller = $this->controller($this->writeConfig(''), hashFails: true);

        self::assertSame('/app_install', $controller->executeInstall($this->installRequest())->headers->get('Location'));
        self::assertSame(
            ['Installation failed. Run php bin/console app:install on the server for diagnostics.'],
            $this->session->getFlashBag()->get('error'),
        );
    }

    #[DataProvider('invalidCsrfTokens')]
    public function testInvalidCsrfDoesNotChangeConfigurationRunMigrationsOrCreateAdmin(mixed $token): void
    {
        $config = $this->writeConfig('');
        $original = file_get_contents($this->projectDir.'/config/aggregate.yaml');
        $controller = $this->controller($config, runsMigrations: false);
        $request = $this->installRequest();
        $request->request->set('_csrf_token', $token);

        try {
            $controller->executeInstall($request);
            self::fail('The installer must reject an invalid CSRF token.');
        } catch (AccessDeniedHttpException) {
            self::assertSame($original, file_get_contents($this->projectDir.'/config/aggregate.yaml'));
        }
    }

    public static function invalidCsrfTokens(): iterable
    {
        yield 'missing' => [null];
        yield 'empty' => [''];
        yield 'forged' => ['forged'];
        yield 'array' => [['valid-install-token']];
    }

    public function testBrowserSetupCodeIsRequiredBeforeAnythingChanges(): void
    {
        $config = $this->writeConfig('');
        $original = file_get_contents($this->projectDir.'/config/aggregate.yaml');
        (new SetupCode($this->projectDir))->ensure();
        $controller = $this->controller($config, runsMigrations: false);

        $request = $this->installRequest();
        $request->request->set('setup_code', 'AAAA-BBBB-CCCC');
        $request->cookies->set(SetupCode::COOKIE, 'AAAA-BBBB-CCCC');

        self::assertSame('/app_install', $controller->executeInstall($request)->headers->get('Location'));
        self::assertSame(['Enter the setup code from SETUP-CODE.txt in the application folder.'], $this->session->getFlashBag()->get('error'));
        self::assertSame($original, file_get_contents($this->projectDir.'/config/aggregate.yaml'));
        self::assertFileExists($this->projectDir.'/'.SetupCode::FILE);
    }

    #[DataProvider('setupCodeCarriers')]
    public function testVerifiedSetupCodeCompletesInstallationAndIsRemoved(string $carrier): void
    {
        $code = (new SetupCode($this->projectDir))->ensure();
        $controller = $this->controller($this->writeConfig(''));
        $request = $this->installRequest();
        if ($carrier === 'cookie') {
            $request->cookies->set(SetupCode::COOKIE, $code);
        } else {
            $request->request->set('setup_code', strtolower($code));
        }

        $response = $controller->executeInstall($request);

        self::assertSame('/app_login', $response->headers->get('Location'));
        self::assertFileDoesNotExist($this->projectDir.'/'.SetupCode::FILE);
        $cleared = array_filter($response->headers->getCookies(), static fn ($cookie): bool => $cookie->getName() === SetupCode::COOKIE);
        self::assertCount(1, $cleared);
        self::assertTrue(array_values($cleared)[0]->isCleared());
    }

    public static function setupCodeCarriers(): iterable
    {
        yield 'same browser (cookie from the setup page)' => ['cookie'];
        yield 'another browser (typed into the form)' => ['form'];
    }

    public function testPublicAddressIsSavedForTrackingSnippets(): void
    {
        $controller = $this->controller($this->writeConfig(''));
        $request = $this->installRequest();
        $request->request->set('app_host', 'https://analytics.example.com/');

        self::assertSame('/app_login', $controller->executeInstall($request)->headers->get('Location'));
        self::assertSame('https://analytics.example.com', Yaml::parseFile($this->projectDir.'/config/aggregate.yaml')['app_host']);
    }

    public function testInvalidPublicAddressIsRejectedBeforeMigrations(): void
    {
        $config = $this->writeConfig('');
        $original = file_get_contents($this->projectDir.'/config/aggregate.yaml');
        $controller = $this->controller($config, runsMigrations: false);
        $request = $this->installRequest();
        $request->request->set('app_host', 'analytics.example.com?x=1');

        self::assertSame('/app_install', $controller->executeInstall($request)->headers->get('Location'));
        self::assertSame($original, file_get_contents($this->projectDir.'/config/aggregate.yaml'));
    }

    public function testInstallerAsksOtherBrowsersForTheSetupCode(): void
    {
        $code = (new SetupCode($this->projectDir))->ensure();
        $config = $this->writeConfig('');
        $renders = [];
        $controller = $this->controller($config, runsMigrations: false, capturesRender: $renders);

        $controller->install(Request::create('https://analytics.example.test/install'));
        $request = Request::create('https://analytics.example.test/install');
        $request->cookies->set(SetupCode::COOKIE, $code);
        $controller->install($request);

        self::assertTrue($renders[0]['setup_code_required']);
        self::assertFalse($renders[1]['setup_code_required']);
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
            '_csrf_token' => 'valid-install-token',
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
        bool $hashFails = false,
        int $glossaryResult = Command::SUCCESS,
        ?array &$capturesRender = null,
    ): InstallController {
        $checker = $this->createStub(InstallationChecker::class);
        $checker->method('isInstalled')->willReturn($alreadyInstalled);
        $checker->method('isConfigValid')->willReturn(true);
        $createsUser = !$alreadyInstalled && $runsMigrations && $migrationResult === Command::SUCCESS && !$hashFails;
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($createsUser ? self::once() : self::never())
            ->method('persist')
            ->with(self::callback(static fn (User $user): bool =>
                $user->getUsername() === 'admin' && in_array('ROLE_ADMIN', $user->getRoles(), true)));
        $entityManager->expects($createsUser ? self::once() : self::never())->method('flush');
        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $connection->expects($runsMigrations && $migrationResult === Command::SUCCESS ? self::once() : self::never())->method('close');
        $entityManager->method('getConnection')->willReturn($connection);
        $passwordHasher = $this->createStub(UserPasswordHasherInterface::class);
        if ($hashFails) {
            $passwordHasher->method('hashPassword')->willThrowException(new \RuntimeException('Synthetic private connection detail'));
        } else {
            $passwordHasher->method('hashPassword')->willReturn('hashed-password');
        }

        $kernelContainer = new Container();
        $kernelContainer->set('event_dispatcher', new EventDispatcher());
        $kernelContainer->set('console.command_loader', new FactoryCommandLoader([
            'doctrine:migrations:migrate' => fn (): Command =>
                (new Command('doctrine:migrations:migrate'))->setCode(function (InputInterface $input, OutputInterface $output) use ($migrationResult): int {
                    $this->commandsRun[] = 'doctrine:migrations:migrate';
                    $output->writeln('Synthetic private migration diagnostic');
                    return $migrationResult;
                }),
            'app:analytics:glossary:sync' => fn (): Command =>
                (new Command('app:analytics:glossary:sync'))->setCode(function () use ($glossaryResult): int {
                    $this->commandsRun[] = 'app:analytics:glossary:sync';
                    return $glossaryResult;
                }),
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
            new SetupCode($this->projectDir),
        );
        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(static fn (string $route): string => '/'.$route);
        $request = Request::create('/install');
        $this->session = new Session(new MockArraySessionStorage());
        $request->setSession($this->session);
        $requestStack = new RequestStack();
        $requestStack->push($request);
        $container = new Container();
        $container->set('router', $router);
        $container->set('request_stack', $requestStack);
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturnCallback(static fn (CsrfToken $token): bool =>
            $token->getId() === 'install' && $token->getValue() === 'valid-install-token');
        $container->set('security.csrf.token_manager', $csrf);
        if ($capturesRender !== null) {
            $twig = $this->createStub(Environment::class);
            $twig->method('render')->willReturnCallback(static function (string $template, array $context) use (&$capturesRender): string {
                $capturesRender[] = $context;

                return 'installer';
            });
            $container->set('twig', $twig);
        }
        if ($rendersInstaller) {
            $twig = $this->createMock(Environment::class);
            $twig->expects(self::once())->method('render')->with('install/index.html.twig', [
                'setup_code_required' => false,
                'setup_code_file' => SetupCode::FILE,
                'app_host' => 'https://analytics.example.test',
            ])->willReturn('installer');
            $container->set('twig', $twig);
        }
        $controller->setContainer($container);

        return $controller;
    }
}
