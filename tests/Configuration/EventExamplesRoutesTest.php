<?php

declare(strict_types=1);

namespace App\Tests\Configuration;

use App\Command\ExportEventExamplesCommand;
use App\Controller\DataModelController;
use App\Controller\EventExamplesController;
use App\Entity\User;
use App\Kernel;
use App\Service\AggregateConfigLoader;
use App\Service\CustomDataSettings;
use App\Service\EventExampleGenerator;
use App\Service\InternalTrafficSettings;
use App\Service\ReportingViewManager;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Yaml\Yaml;

final class EventExamplesRoutesTest extends TestCase
{
    private const EXAMPLE_ROUTES = [
        'app_event_examples' => '/dashboard/data-model/examples',
        'app_event_examples_ecommerce' => '/dashboard/data-model/examples/ecommerce',
        'app_event_examples_download' => '/dashboard/data-model/examples/download',
    ];
    private const MODEL_ROUTES = [
        'app_data_model' => '/dashboard/data-model',
        'app_data_model_discovery' => '/dashboard/data-model/discovery',
        'app_data_model_reporting' => '/dashboard/data-model/reporting',
    ];

    private string $temporaryDirectory;
    private array $environment;
    private ?EventExamplesRoutesTestKernel $kernel = null;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        foreach ([...array_keys(InternalTrafficSettings::DEFAULTS), 'anonymous_tracking_enabled', 'anonymous_excluded_paths'] as $key) {
            unset($_ENV[strtoupper($key)], $_SERVER[strtoupper($key)]);
        }
        $this->temporaryDirectory = sys_get_temp_dir().'/aggregate-event-example-routes-'.bin2hex(random_bytes(8));
        (new Filesystem())->mkdir($this->temporaryDirectory.'/config', 0700);
        file_put_contents($this->temporaryDirectory.'/config/aggregate.yaml', Yaml::dump([
            'installed' => true,
            'admin_token' => 'private-route-test-secret',
            'internal_traffic_share_token' => str_repeat('p', 64),
            'custom_data_properties' => [
                'channel' => ['consent_required' => false],
                'plan' => ['consent_required' => true, 'description' => 'private-route-test-description'],
            ],
            'query_parameter_mappings' => [],
        ], 5));
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        [$_ENV, $_SERVER] = $this->environment;
        (new Filesystem())->remove($this->temporaryDirectory);
    }

    public function testExampleAndSeparatedModelPagesRegisterGetOnlyRoutes(): void
    {
        $browser = $this->browser('ROLE_ADMIN');
        $routes = $this->container()->get('router')->getRouteCollection();
        foreach (self::EXAMPLE_ROUTES + self::MODEL_ROUTES as $name => $path) {
            $route = $routes->get($name);
            self::assertNotNull($route);
            self::assertSame($path, $route->getPath());
            self::assertSame(['GET'], $route->getMethods());
            $browser->request('POST', $path);
            self::assertSame(405, $browser->getResponse()->getStatusCode(), $path);
        }
        $this->assertNoDatabaseConnection();
    }

    public function testUnauthenticatedRequestsCannotReadSavedDefinitionsOrQueryEvents(): void
    {
        $browser = $this->browser();
        $this->preventModelAndReportingReads();
        foreach (self::EXAMPLE_ROUTES + self::MODEL_ROUTES as $path) {
            $browser->request('GET', $path, ['discover' => '1']);
            self::assertSame(302, $browser->getResponse()->getStatusCode(), $path);
            self::assertSame('/login', parse_url((string) $browser->getResponse()->headers->get('Location'), PHP_URL_PATH));
        }
        $this->assertNoDatabaseConnection();
    }

    public function testOrdinaryUsersCannotReadAnyExampleOrModelPage(): void
    {
        $browser = $this->browser('ROLE_USER');
        $this->preventModelAndReportingReads();
        foreach (self::EXAMPLE_ROUTES + self::MODEL_ROUTES as $path) {
            $browser->request('GET', $path, ['discover' => '1']);
            self::assertSame(403, $browser->getResponse()->getStatusCode(), $path);
        }
        $this->assertNoDatabaseConnection();
    }

    public function testAdministratorPagesRenderCopyButtonsAndSafeDownloadsWithNoDatabaseAccess(): void
    {
        $browser = $this->browser('ROLE_ADMIN');
        $views = $this->createMock(ReportingViewManager::class);
        $views->expects(self::never())->method('discoverProperties');
        $views->expects(self::never())->method('previewSql');
        $views->expects(self::never())->method('regenerate');
        $this->container()->set(ReportingViewManager::class, $views);
        $before = file_get_contents($this->temporaryDirectory.'/config/aggregate.yaml');
        foreach ([self::EXAMPLE_ROUTES['app_event_examples'] => 2, self::EXAMPLE_ROUTES['app_event_examples_ecommerce'] => 3] as $path => $buttons) {
            $crawler = $browser->request('GET', $path);
            self::assertSame(200, $browser->getResponse()->getStatusCode(), $path);
            $this->assertPrivate($browser);
            self::assertCount($buttons, $crawler->filter('.container [data-copy-example]'));
            self::assertCount(1, $crawler->filter('.container #example-anonymous'));
            self::assertCount(1, $crawler->filter('.container #example-enhanced'));
            self::assertCount(0, $crawler->filter('.container form'));
            $this->assertNoSecrets((string) $browser->getResponse()->getContent());
        }
        foreach (['model', 'ecommerce'] as $example) {
            $browser->request('GET', self::EXAMPLE_ROUTES['app_event_examples_download'], ['mode' => 'enhanced', 'example' => $example]);
            self::assertSame(200, $browser->getResponse()->getStatusCode());
            $this->assertPrivate($browser);
            $bundle = json_decode((string) $browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(['enhanced'], array_keys($bundle['examples']));
            self::assertTrue($bundle['synthetic']);
            $this->assertNoSecrets((string) $browser->getResponse()->getContent());
        }
        $crawler = $browser->request('GET', '/dashboard/data-model');
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        self::assertCount(1, $crawler->filter('#data-model-form'));
        self::assertCount(0, $crawler->filter('form[action="/dashboard/data-model/regenerate"]'));
        self::assertSame($before, file_get_contents($this->temporaryDirectory.'/config/aggregate.yaml'));
        $this->assertNoDatabaseConnection();
    }

    public function testMalformedDownloadQueriesAreRejectedByTheHttpEndpoint(): void
    {
        $browser = $this->browser('ROLE_ADMIN');
        $this->preventModelAndReportingReads();
        foreach ([['mode' => ['anonymous']], ['mode' => 'private-input'], ['example' => ['ecommerce']], ['example' => '../private-input']] as $query) {
            $browser->request('GET', self::EXAMPLE_ROUTES['app_event_examples_download'], $query);
            self::assertSame(400, $browser->getResponse()->getStatusCode());
            $this->assertPrivate($browser);
            self::assertStringNotContainsString('private-input', (string) $browser->getResponse()->getContent());
            self::assertFalse($browser->getResponse()->headers->has('Content-Disposition'));
        }
        $this->assertNoDatabaseConnection();
    }

    public function testHeadlessBootOmitsUiRoutesButKeepsTheGeneratorAndExportCommand(): void
    {
        $browser = $this->browser(dashboardEnabled: false);
        self::assertFalse($this->container()->has(EventExamplesController::class));
        self::assertFalse($this->container()->has(DataModelController::class));
        self::assertInstanceOf(EventExampleGenerator::class, $this->container()->get(EventExampleGenerator::class));
        self::assertInstanceOf(ExportEventExamplesCommand::class, $this->container()->get(ExportEventExamplesCommand::class));
        $routes = $this->container()->get('router')->getRouteCollection();
        foreach (self::EXAMPLE_ROUTES + self::MODEL_ROUTES as $name => $path) {
            self::assertNull($routes->get($name));
            $browser->request('GET', $path);
            self::assertSame(404, $browser->getResponse()->getStatusCode(), $path);
        }
        $this->assertNoDatabaseConnection();
    }

    private function preventModelAndReportingReads(): void
    {
        $settings = $this->createMock(CustomDataSettings::class);
        $settings->expects(self::never())->method('toArray');
        $settings->expects(self::never())->method('validate');
        $this->container()->set(CustomDataSettings::class, $settings);
        $views = $this->createMock(ReportingViewManager::class);
        foreach (['discoverProperties', 'previewSql', 'regenerate'] as $method) {
            $views->expects(self::never())->method($method);
        }
        $this->container()->set(ReportingViewManager::class, $views);
    }

    private function browser(?string $role = null, bool $dashboardEnabled = true): KernelBrowser
    {
        $_ENV['DASHBOARD_ENABLED'] = $_SERVER['DASHBOARD_ENABLED'] = $dashboardEnabled ? '1' : '0';
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        $_ENV['TRUSTED_PROXIES'] = $_SERVER['TRUSTED_PROXIES'] = '';
        $this->kernel = new EventExamplesRoutesTestKernel($this->temporaryDirectory);
        $this->kernel->boot();
        $browser = new KernelBrowser($this->kernel);
        $browser->disableReboot();
        if ($role !== null) {
            $browser->loginUser(EventExamplesTestUserProvider::user($role));
        }

        return $browser;
    }

    private function container(): \Psr\Container\ContainerInterface
    {
        return $this->kernel->getContainer()->get('test.service_container');
    }

    private function assertPrivate(KernelBrowser $browser): void
    {
        self::assertTrue($browser->getResponse()->headers->hasCacheControlDirective('private'));
        self::assertTrue($browser->getResponse()->headers->hasCacheControlDirective('no-store'));
        self::assertSame('nosniff', $browser->getResponse()->headers->get('X-Content-Type-Options'));
    }

    private function assertNoDatabaseConnection(): void
    {
        self::assertFalse($this->container()->get('doctrine.dbal.default_connection')->isConnected());
    }

    private function assertNoSecrets(string $content): void
    {
        foreach (['private-route-test-secret', 'private-route-test-description', str_repeat('p', 64)] as $secret) {
            self::assertStringNotContainsString($secret, $content);
        }
    }
}

final class EventExamplesRoutesTestKernel extends Kernel implements CompilerPassInterface
{
    public function __construct(private readonly string $temporaryDirectory)
    {
        parent::__construct('test', true);
    }

    public function getCacheDir(): string
    {
        return $this->temporaryDirectory.'/cache';
    }

    public function getLogDir(): string
    {
        return $this->temporaryDirectory.'/log';
    }

    protected function getContainerClass(): string
    {
        return parent::getContainerClass().'_'.md5($this->temporaryDirectory);
    }

    public function process(ContainerBuilder $container): void
    {
        $container->setDefinition('security.user.provider.concrete.app_user_provider', new Definition(EventExamplesTestUserProvider::class));
        $container->getDefinition(AggregateConfigLoader::class)->setArgument('$projectDir', $this->temporaryDirectory);
    }
}

/** @implements UserProviderInterface<User> */
final class EventExamplesTestUserProvider implements UserProviderInterface
{
    public static function user(string $role): User
    {
        $user = (new User())->setUsername($role === 'ROLE_ADMIN' ? 'examples-admin' : 'examples-user')->setRoles([$role])->setPassword('test-only');
        (new \ReflectionProperty($user, 'id'))->setValue($user, 17);

        return $user;
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        return self::user($identifier === 'examples-admin' ? 'ROLE_ADMIN' : 'ROLE_USER');
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException();
        }

        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return $class === User::class;
    }
}
