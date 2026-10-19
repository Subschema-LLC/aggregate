<?php

declare(strict_types=1);

namespace App\Tests\Configuration;

use App\Controller\ReportingTimezoneController;
use App\Entity\User;
use App\Kernel;
use App\Service\ReportingTimezone;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

final class ReportingTimezoneRoutesTest extends TestCase
{
    private const ROUTES = [
        'app_reporting_timezone' => ['/dashboard/reporting/timezone', 'GET'],
        'app_reporting_timezone_save' => ['/dashboard/reporting/timezone/save', 'POST'],
    ];

    private string $directory;
    private array $environment;
    private ?ReportingTimezoneRoutesTestKernel $kernel = null;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        $this->directory = sys_get_temp_dir().'/aggregate-timezone-routes-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        [$_ENV, $_SERVER] = $this->environment;
        (new Filesystem())->remove($this->directory);
    }

    public function testThePageIsAServiceAndRequiresAuthentication(): void
    {
        $kernel = $this->boot(true);
        $container = $kernel->getContainer()->get('test.service_container');
        self::assertTrue($container->has(ReportingTimezoneController::class));
        $browser = new KernelBrowser($kernel);
        $browser->disableReboot();
        $routes = $container->get('router')->getRouteCollection();
        foreach (self::ROUTES as $name => [$path, $method]) {
            $route = $routes->get($name);
            self::assertNotNull($route);
            self::assertSame($path, $route->getPath());
            self::assertSame([$method], $route->getMethods());
            $browser->request($method, $path);
            self::assertSame(302, $browser->getResponse()->getStatusCode(), $path);
            self::assertSame('/login', parse_url((string) $browser->getResponse()->headers->get('Location'), PHP_URL_PATH));
        }
        // The setting is a section of General settings, which has the menu entry.
        $routes = [];
        array_walk_recursive($container->getParameter('app.main_navigation')['items'], static function (mixed $value, string|int $key) use (&$routes): void {
            if ($key === 'route') {
                $routes[] = $value;
            }
        });
        self::assertNotContains('app_reporting_timezone', $routes);
        self::assertContains('app_application_settings', $routes);
        $browser->loginUser(ReportingTimezoneRoutesUserProvider::user());
        $browser->request('GET', '/dashboard/reporting/timezone');
        self::assertSame(302, $browser->getResponse()->getStatusCode());
        self::assertSame('/dashboard/settings#reporting-timezone', $browser->getResponse()->headers->get('Location'));
        $browser->request('GET', '/dashboard/settings');
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        $page = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString('id="reporting-timezone"', $page);
        self::assertStringContainsString('America/Chicago', $page);
        self::assertStringContainsString('action="/dashboard/reporting/timezone/save"', $page);
        self::assertFalse($container->get('doctrine.dbal.default_connection')->isConnected());
    }

    public function testHeadlessBootOmitsThePageAndKeepsTheSettingService(): void
    {
        $kernel = $this->boot(false);
        $container = $kernel->getContainer()->get('test.service_container');
        self::assertFalse($container->has(ReportingTimezoneController::class));
        self::assertInstanceOf(ReportingTimezone::class, $container->get(ReportingTimezone::class));
        $browser = new KernelBrowser($kernel);
        $browser->disableReboot();
        $routes = $container->get('router')->getRouteCollection();
        foreach (self::ROUTES as $name => [$path, $method]) {
            self::assertNull($routes->get($name));
            $browser->request($method, $path);
            self::assertSame(404, $browser->getResponse()->getStatusCode(), $path);
        }
        self::assertFalse($container->get('doctrine.dbal.default_connection')->isConnected());
    }

    private function boot(bool $dashboard): ReportingTimezoneRoutesTestKernel
    {
        $_ENV['DASHBOARD_ENABLED'] = $_SERVER['DASHBOARD_ENABLED'] = $dashboard ? '1' : '0';
        $this->kernel = new ReportingTimezoneRoutesTestKernel($this->directory);
        $this->kernel->boot();

        return $this->kernel;
    }
}

final class ReportingTimezoneRoutesTestKernel extends Kernel implements CompilerPassInterface
{
    public function __construct(private readonly string $directory)
    {
        parent::__construct('test', true);
    }

    public function getCacheDir(): string
    {
        return $this->directory.'/cache';
    }

    public function getLogDir(): string
    {
        return $this->directory.'/log';
    }

    protected function getContainerClass(): string
    {
        return parent::getContainerClass().'_'.md5($this->directory);
    }

    public function process(ContainerBuilder $container): void
    {
        $container->setDefinition('security.user.provider.concrete.app_user_provider', new Definition(ReportingTimezoneRoutesUserProvider::class));
    }
}

/** @implements UserProviderInterface<User> */
final class ReportingTimezoneRoutesUserProvider implements UserProviderInterface
{
    public static function user(): User
    {
        $user = (new User())->setUsername('timezone-admin')->setRoles(['ROLE_ADMIN'])->setPassword('test-only');
        (new \ReflectionProperty($user, 'id'))->setValue($user, 19);

        return $user;
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        return self::user();
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException();
        }

        return self::user();
    }

    public function supportsClass(string $class): bool
    {
        return $class === User::class;
    }
}
