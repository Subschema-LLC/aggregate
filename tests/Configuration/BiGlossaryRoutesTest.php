<?php

declare(strict_types=1);

namespace App\Tests\Configuration;

use App\Controller\BiGlossaryController;
use App\Kernel;
use App\Service\Glossary\BiGlossarySettings;
use App\Service\Glossary\GlossarySync;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Filesystem\Filesystem;

final class BiGlossaryRoutesTest extends TestCase
{
    private const ROUTES = [
        'app_bi_glossary' => ['', 'GET'],
        'app_bi_glossary_save' => ['/save', 'POST'],
        'app_bi_glossary_sync' => ['/sync', 'POST'],
        'app_bi_glossary_suggestions' => ['/suggestions', 'POST'],
        'app_bi_glossary_download' => ['/download', 'GET'],
        'app_bi_glossary_missing' => ['/missing', 'GET'],
    ];

    private string $directory;
    private array $environment;
    private ?BiGlossaryRoutesTestKernel $kernel = null;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        $this->directory = sys_get_temp_dir().'/aggregate-glossary-routes-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        [$_ENV, $_SERVER] = $this->environment;
        (new Filesystem())->remove($this->directory);
    }

    public function testRoutesAndNavigationRequireAuthenticationBeforeAnyGlossaryRead(): void
    {
        $kernel = $this->boot(true);
        $container = $kernel->getContainer()->get('test.service_container');
        self::assertTrue($container->has(BiGlossaryController::class));
        $settings = $this->createMock(BiGlossarySettings::class);
        $settings->expects(self::never())->method('get');
        $settings->expects(self::never())->method('save');
        $container->set(BiGlossarySettings::class, $settings);
        $browser = new KernelBrowser($kernel);
        $browser->disableReboot();
        $routes = $container->get('router')->getRouteCollection();
        foreach (self::ROUTES as $name => [$suffix, $method]) {
            $route = $routes->get($name);
            self::assertNotNull($route);
            self::assertSame('/dashboard/data-model/glossary'.$suffix, $route->getPath());
            self::assertSame([$method], $route->getMethods());
            $browser->request($method, $route->getPath());
            self::assertSame(302, $browser->getResponse()->getStatusCode());
            self::assertSame('/login', parse_url((string) $browser->getResponse()->headers->get('Location'), PHP_URL_PATH));
        }
        $reporting = array_values(array_filter($container->getParameter('app.main_navigation')['items'], static fn (array $item): bool => $item['label'] === 'Reporting'))[0]['children'];
        self::assertSame('app_data_model_reporting', $reporting[0]['route']);
        self::assertSame('app_bi_glossary', $reporting[1]['route']);
        self::assertSame('ROLE_ADMIN', $reporting[1]['role']);
        self::assertFalse($container->get('doctrine.dbal.default_connection')->isConnected());
    }

    public function testHeadlessBootOmitsAllGlossaryUiRoutesAndKeepsHeadlessServices(): void
    {
        $kernel = $this->boot(false);
        $container = $kernel->getContainer()->get('test.service_container');
        self::assertFalse($container->has(BiGlossaryController::class));
        self::assertInstanceOf(BiGlossarySettings::class, $container->get(BiGlossarySettings::class));
        self::assertInstanceOf(GlossarySync::class, $container->get(GlossarySync::class));
        $browser = new KernelBrowser($kernel);
        $browser->disableReboot();
        $routes = $container->get('router')->getRouteCollection();
        foreach (self::ROUTES as $name => [$suffix, $method]) {
            self::assertNull($routes->get($name));
            $browser->request($method, '/dashboard/data-model/glossary'.$suffix);
            self::assertSame(404, $browser->getResponse()->getStatusCode());
        }
        self::assertFalse($container->get('doctrine.dbal.default_connection')->isConnected());
    }

    private function boot(bool $dashboard): BiGlossaryRoutesTestKernel
    {
        $_ENV['DASHBOARD_ENABLED'] = $_SERVER['DASHBOARD_ENABLED'] = $dashboard ? '1' : '0';
        $this->kernel = new BiGlossaryRoutesTestKernel($this->directory);
        $this->kernel->boot();

        return $this->kernel;
    }
}

final class BiGlossaryRoutesTestKernel extends Kernel
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
}
