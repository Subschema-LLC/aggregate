<?php

declare(strict_types=1);

namespace App\Tests\Configuration;

use App\Kernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Boots the application with each server role and checks the routes it
 * serves against the spec's table (docs/specs/JOIN-59-server-roles.md#roles),
 * and that the controllers behind the routes it leaves out are not built.
 */
final class ServerRoleRoutesTest extends TestCase
{
    private const COLLECTION = ['/api/receive', '/aggregate.js', '/lib.js', '/consent-manager.js', '/internal-traffic/{token}'];
    private const ADMINISTRATION = ['/', '/api/v1/views', '/mcp', '/oauth/token', '/.well-known/oauth-authorization-server', '/login'];
    private const BRANDING = ['/branding/logo', '/branding/theme.css'];

    private array $environment;
    private ?Kernel $kernel = null;
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        // Any role but all needs a database other servers can share.
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'mysql://aggregate:secret@127.0.0.1:1/aggregate?serverVersion=10.11.14-MariaDB';
        unset($_ENV['DASHBOARD_ENABLED'], $_SERVER['DASHBOARD_ENABLED']);
        $this->cacheDir = sys_get_temp_dir().'/aggregate-role-routes-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        [$_ENV, $_SERVER] = $this->environment;
        (new Filesystem())->remove($this->cacheDir);
    }

    /**
     * @param list<string> $served
     * @param list<string> $notServed
     * @param list<class-string> $removed
     */
    #[DataProvider('roles')]
    public function testEachRoleServesOnlyItsRoutes(string $role, array $served, array $notServed, array $removed): void
    {
        $_ENV['SERVER_ROLE'] = $_SERVER['SERVER_ROLE'] = $role;
        $this->kernel = new ServerRoleRoutesKernel($this->cacheDir.'/'.$role);
        $this->kernel->boot();
        $container = $this->kernel->getContainer();
        $paths = array_map(static fn ($route): string => $route->getPath(), iterator_to_array($container->get('router')->getRouteCollection()));

        foreach ($served as $path) {
            self::assertContains($path, $paths, $role.' should serve '.$path);
        }
        foreach ($notServed as $path) {
            self::assertNotContains($path, $paths, $role.' should not serve '.$path);
        }
        foreach ($removed as $controller) {
            self::assertFalse($container->has($controller), $role.' should not build '.$controller);
        }
        self::assertSame($role, $container->getParameter('aggregate.server_role.compiled'));
    }

    public static function roles(): iterable
    {
        yield 'all, as one server today' => ['all', ['/api/health', ...self::COLLECTION, ...self::ADMINISTRATION, ...self::BRANDING], [], []];
        yield 'administration' => ['administration', ['/api/health', ...self::ADMINISTRATION, ...self::BRANDING], self::COLLECTION,
            ['App\\Controller\\ReceiveController', 'App\\Controller\\ScriptController']];
        yield 'collection' => ['collection', ['/api/health', ...self::COLLECTION, ...self::BRANDING], self::ADMINISTRATION,
            ['App\\Controller\\HomeController', 'App\\Controller\\McpController', 'App\\Controller\\ReportingApiController', 'App\\Controller\\WebsitesController']];
        yield 'worker' => ['worker', ['/api/health'], [...self::COLLECTION, ...self::ADMINISTRATION, ...self::BRANDING],
            ['App\\Controller\\ReceiveController', 'App\\Controller\\HomeController', 'App\\Controller\\BrandingLogoController']];
        yield 'balancer' => ['balancer', ['/api/health'], [...self::COLLECTION, ...self::ADMINISTRATION, ...self::BRANDING], ['App\\Controller\\ReceiveController']];
    }

    public function testSqliteRunsEveryRoleAsAll(): void
    {
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///%kernel.project_dir%/var/role-routes.db';
        $_ENV['SERVER_ROLE'] = $_SERVER['SERVER_ROLE'] = 'collection';
        $this->kernel = new ServerRoleRoutesKernel($this->cacheDir.'/sqlite');
        $this->kernel->boot();
        $paths = array_map(static fn ($route): string => $route->getPath(), iterator_to_array($this->kernel->getContainer()->get('router')->getRouteCollection()));

        self::assertContains('/login', $paths);
        self::assertContains('/api/receive', $paths);
        self::assertSame('all', $this->kernel->getContainer()->getParameter('aggregate.server_role.compiled'));
    }
}

final class ServerRoleRoutesKernel extends Kernel
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
