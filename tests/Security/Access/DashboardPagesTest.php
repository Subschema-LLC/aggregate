<?php

declare(strict_types=1);

namespace App\Tests\Security\Access;

use App\Kernel;
use App\Security\Access\DashboardPages;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Yaml\Yaml;

final class DashboardPagesTest extends TestCase
{
    private static ?DashboardPagesTestKernel $kernel = null;
    private static string $temporaryDirectory = '';
    /** @var array{0: array<mixed>, 1: array<mixed>} */
    private static array $environment = [[], []];

    public static function setUpBeforeClass(): void
    {
        self::$environment = [$_ENV, $_SERVER];
        $_ENV['DASHBOARD_ENABLED'] = $_SERVER['DASHBOARD_ENABLED'] = '1';
        self::$temporaryDirectory = sys_get_temp_dir().'/aggregate-dashboard-pages-'.bin2hex(random_bytes(8));
        self::$kernel = new DashboardPagesTestKernel(self::$temporaryDirectory);
        self::$kernel->boot();
    }

    public static function tearDownAfterClass(): void
    {
        self::$kernel?->shutdown();
        self::$kernel = null;
        [$_ENV, $_SERVER] = self::$environment;
        (new Filesystem())->remove(self::$temporaryDirectory);
    }

    /**
     * A new dashboard route must say which page and level it needs. An
     * unlisted one is administrator-only, but listing it is how a contributor
     * decides who else may use it.
     */
    public function testEveryDashboardRouteIsListed(): void
    {
        $missing = [];
        foreach ($this->routes()->all() as $name => $route) {
            if (preg_match('#^/dashboard(?:/|$)#', $route->getPath()) === 1 && !isset(DashboardPages::ROUTES[$name])) {
                $missing[] = $name.' '.$route->getPath();
            }
        }

        self::assertSame([], $missing, "These dashboard routes are not in DashboardPages::ROUTES:\n".implode("\n", $missing));
    }

    public function testListedRoutesExistAndNameKnownPagesAndLevels(): void
    {
        $routes = $this->routes();
        foreach (DashboardPages::ROUTES as $name => $entry) {
            $route = $routes->get($name);
            self::assertNotNull($route, $name.' is listed but is not a route.');
            self::assertStringStartsWith(DashboardPages::PATH_PREFIX, $route->getPath(), $name);
            if (in_array($entry[0], [DashboardPages::ADMIN, DashboardPages::SIGNED_IN], true)) {
                self::assertCount(1, $entry, $name);
                continue;
            }
            self::assertArrayHasKey($entry[0], DashboardPages::PAGES, $name);
            self::assertContains($entry[1] ?? null, [DashboardPages::VIEW, DashboardPages::EDIT, DashboardPages::BY_METHOD], $name);
            if (($entry[1] ?? null) === DashboardPages::EDIT) {
                self::assertContains(DashboardPages::EDIT, DashboardPages::levels($entry[0]), $name.' needs edit on a page that cannot be edited.');
            }
        }
    }

    public function testRoutesThatChangeSomethingNeedEdit(): void
    {
        // Returns suggested labels and changes nothing.
        $readOnlyPosts = ['app_bi_glossary_suggestions'];
        foreach ($this->routes()->all() as $name => $route) {
            $entry = DashboardPages::ROUTES[$name] ?? null;
            if ($entry === null || !isset(DashboardPages::PAGES[$entry[0]]) || in_array($name, $readOnlyPosts, true)) {
                continue;
            }
            if (array_intersect($route->getMethods(), ['POST', 'PUT', 'PATCH', 'DELETE']) !== []) {
                self::assertSame(DashboardPages::EDIT, DashboardPages::requirement($name, 'POST')['level'], $name.' accepts POST but needs only view.');
            }
        }
    }

    public function testUsersAndPermissionGroupsStayWithAdministratorsAndProfilesWithEveryone(): void
    {
        foreach ($this->routes()->all() as $name => $route) {
            if (str_starts_with($route->getPath(), '/dashboard/users')) {
                self::assertSame(DashboardPages::ADMIN, DashboardPages::requirement($name)['kind'], $name);
            }
            if (str_starts_with($route->getPath(), '/dashboard/profile')) {
                self::assertSame(DashboardPages::SIGNED_IN, DashboardPages::requirement($name)['kind'], $name);
            }
        }
        self::assertArrayNotHasKey('users', DashboardPages::PAGES);
    }

    public function testEveryPageOpensFromAMenuLinkOrItsTab(): void
    {
        $navigation = Yaml::parseFile(dirname(__DIR__, 3).'/config/navigation.yaml')['parameters']['app.main_navigation']['items'];
        $linked = [];
        foreach ($navigation as $item) {
            foreach ($item['children'] ?? [$item] as $link) {
                if (isset($link['route'])) {
                    $linked[] = $link['route'];
                }
            }
        }
        foreach (array_keys(DashboardPages::PAGES) as $page) {
            $main = DashboardPages::mainRoute($page);
            self::assertNotNull($main, $page.' has no route to open it.');
            self::assertSame(['page' => $page, 'level' => DashboardPages::VIEW], array_intersect_key(DashboardPages::requirement($main), ['page' => 1, 'level' => 1]));
            self::assertContains($main, $linked, $page.' is not in the shipped menu.');
        }
    }

    public function testRequirementsFollowTheMethodAndFailClosed(): void
    {
        self::assertSame(['kind' => 'page', 'page' => 'setup', 'level' => 'view', 'every_website' => false], DashboardPages::requirement('app_standalone_consent', 'GET'));
        self::assertSame('edit', DashboardPages::requirement('app_standalone_consent', 'POST')['level']);
        self::assertTrue(DashboardPages::requirement('app_website_create', 'POST')['every_website']);
        self::assertSame(DashboardPages::ADMIN, DashboardPages::requirement('app_not_listed')['kind']);
        self::assertSame('edit', DashboardPages::higher('view', 'edit'));
        self::assertSame('view', DashboardPages::higher(null, 'view'));
        self::assertNull(DashboardPages::higher(null, null));
        self::assertFalse(DashboardPages::satisfies('view', 'edit'));
        self::assertTrue(DashboardPages::satisfies('edit', 'view'));
        self::assertFalse(DashboardPages::satisfies(null, 'view'));
    }

    private function routes(): RouteCollection
    {
        return self::$kernel->getContainer()->get('test.service_container')->get('router')->getRouteCollection();
    }
}

/** Boots with the dashboard, independent of the checkout's compiled container. */
final class DashboardPagesTestKernel extends Kernel
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
