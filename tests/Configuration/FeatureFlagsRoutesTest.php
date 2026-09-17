<?php

declare(strict_types=1);

namespace App\Tests\Configuration;

use App\Controller\FeatureFlagsController;
use App\Kernel;
use App\Service\FeatureFlags;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Filesystem\Filesystem;

final class FeatureFlagsRoutesTest extends TestCase
{
    private string $temporaryDirectory;
    private array $environment;
    private ?FeatureFlagsRoutesTestKernel $kernel = null;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        $this->temporaryDirectory = sys_get_temp_dir().'/aggregate-feature-flags-routes-'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        [$_ENV, $_SERVER] = $this->environment;
        (new Filesystem())->remove($this->temporaryDirectory);
    }

    public function testDashboardRegistersTheAdministratorControllerAndRestrictsRouteMethods(): void
    {
        $kernel = $this->boot(true);
        $container = $kernel->getContainer()->get('test.service_container');
        self::assertTrue($container->has(FeatureFlagsController::class));
        $routes = $container->get('router')->getRouteCollection();
        foreach ([
            'app_feature_flags' => ['/dashboard/feature-flags', 'GET'],
            'app_feature_flags_save' => ['/dashboard/feature-flags/save', 'POST'],
        ] as $name => [$path, $method]) {
            $route = $routes->get($name);
            self::assertNotNull($route);
            self::assertSame($path, $route->getPath());
            self::assertSame([$method], $route->getMethods());
        }
    }

    public function testFeatureFlagRoutesRequireAuthenticationBeforeReadingOrSaving(): void
    {
        $kernel = $this->boot(true);
        $flags = $this->createMock(FeatureFlags::class);
        $flags->expects(self::never())->method('all');
        $flags->expects(self::never())->method('save');
        $kernel->getContainer()->get('test.service_container')->set(FeatureFlags::class, $flags);
        $browser = new KernelBrowser($kernel);
        $browser->disableReboot();
        foreach (['/dashboard/feature-flags' => 'GET', '/dashboard/feature-flags/save' => 'POST'] as $path => $method) {
            $browser->request($method, $path, [
                '_csrf_token' => 'forged',
                'feature_flags' => ['updates' => ['enabled' => '0', 'hide_from_navigation' => '1']],
            ]);

            self::assertSame(302, $browser->getResponse()->getStatusCode());
            self::assertSame('/login', parse_url((string) $browser->getResponse()->headers->get('Location'), PHP_URL_PATH));
        }
    }

    public function testHeadlessBootOmitsTheUiRoutesAndControllerButKeepsTheSharedService(): void
    {
        $kernel = $this->boot(false);
        $container = $kernel->getContainer()->get('test.service_container');
        self::assertFalse($container->has(FeatureFlagsController::class));
        self::assertInstanceOf(FeatureFlags::class, $container->get(FeatureFlags::class));
        $routes = $container->get('router')->getRouteCollection();
        self::assertNull($routes->get('app_feature_flags'));
        self::assertNull($routes->get('app_feature_flags_save'));

        $browser = new KernelBrowser($kernel);
        $browser->disableReboot();
        foreach (['/dashboard/feature-flags' => 'GET', '/dashboard/feature-flags/save' => 'POST'] as $path => $method) {
            $browser->request($method, $path);

            self::assertSame(404, $browser->getResponse()->getStatusCode());
        }
    }

    private function boot(bool $dashboardEnabled): FeatureFlagsRoutesTestKernel
    {
        $_ENV['DASHBOARD_ENABLED'] = $_SERVER['DASHBOARD_ENABLED'] = $dashboardEnabled ? '1' : '0';
        $this->kernel = new FeatureFlagsRoutesTestKernel($this->temporaryDirectory);
        $this->kernel->boot();

        return $this->kernel;
    }
}

/** Keep boot-mode tests independent of the checkout's compiled container and operator configuration. */
final class FeatureFlagsRoutesTestKernel extends Kernel
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
}
