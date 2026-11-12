<?php

declare(strict_types=1);

namespace App\Tests\Service\Cluster;

use App\Service\Cluster\ServerRole;
use App\Service\Cluster\ServerRoleRefusal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

final class ServerRoleTest extends TestCase
{
    private const SHARED_DATABASE = 'mysql://aggregate:secret@10.0.0.5:3306/aggregate?serverVersion=10.11.14-MariaDB';

    private string $directory;
    private array $environment;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        foreach (['SERVER_ROLE', 'ADMINISTRATION_URL'] as $name) {
            unset($_ENV[$name], $_SERVER[$name]);
        }
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = self::SHARED_DATABASE;
        $this->directory = sys_get_temp_dir().'/aggregate-server-role-'.bin2hex(random_bytes(6));
        (new Filesystem())->mkdir($this->directory.'/config');
    }

    protected function tearDown(): void
    {
        [$_ENV, $_SERVER] = $this->environment;
        (new Filesystem())->remove($this->directory);
    }

    public function testAnInstallationWithoutARoleRunsAsAllAsBefore(): void
    {
        $role = ServerRole::resolve($this->directory, 'prod');

        self::assertSame('all', $role->role);
        self::assertNull($role->configured);
        self::assertSame('default', $role->source);
        self::assertNull($role->problem);
        self::assertTrue($role->administers() && $role->collects() && $role->runsConsumers() && $role->runsScheduledTask());
    }

    public function testTheRoleIsReadFromTheConfigurationAndTheEnvironmentWins(): void
    {
        $this->configure(['server_role' => 'Collection', 'administration_url' => 'https://admin.example.com']);

        $fromFile = ServerRole::resolve($this->directory, 'prod');
        self::assertSame(['collection', 'configuration', 'https://admin.example.com'], [$fromFile->role, $fromFile->source, $fromFile->administrationUrl]);

        $_SERVER['SERVER_ROLE'] = 'worker';
        $_SERVER['ADMINISTRATION_URL'] = 'https://other-admin.example.com';
        $fromEnvironment = ServerRole::resolve($this->directory, 'prod');
        self::assertSame(['worker', 'environment', 'https://other-admin.example.com'], [$fromEnvironment->role, $fromEnvironment->source, $fromEnvironment->administrationUrl]);
    }

    public function testTheRoleFollowsTheConfigurationsEnvironmentsAsSettingsDo(): void
    {
        $this->configure(['server_role' => 'collection', 'environments' => ['prod' => ['server_role' => 'administration']]]);
        self::assertSame('administration', ServerRole::resolve($this->directory, 'prod')->role);
        self::assertSame('collection', ServerRole::resolve($this->directory, 'test')->role);

        file_put_contents($this->directory.'/config/aggregate_prod.yaml', Yaml::dump(['server_role' => 'worker']));
        self::assertSame('worker', ServerRole::resolve($this->directory, 'prod')->role, 'aggregate_<env>.yaml replaces aggregate.yaml, as for every setting.');
    }

    public function testAnUnknownRoleRunsAsAllAndSaysWhy(): void
    {
        $this->configure(['server_role' => 'reporting']);
        $role = ServerRole::resolve($this->directory, 'prod');

        self::assertSame('all', $role->role);
        self::assertSame('reporting', $role->configured);
        self::assertStringContainsString('"reporting" is not one of all, administration, collection, worker', (string) $role->problem);
    }

    public function testABalancerHasNoRoleAndIsToldWhereItsConfigurationComesFrom(): void
    {
        $this->configure(['server_role' => 'balancer']);
        $role = ServerRole::resolve($this->directory, 'prod');

        self::assertSame(['all', 'balancer'], [$role->role, $role->configured]);
        self::assertStringContainsString('app:server:balancer-config', (string) $role->problem);
    }

    #[DataProvider('sqliteUrls')]
    public function testSqliteRefusesEveryRoleButAll(string $url): void
    {
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = $url;
        $this->configure(['server_role' => 'collection']);
        $role = ServerRole::resolve($this->directory, 'prod');

        self::assertSame('all', $role->role);
        self::assertSame('collection', $role->configured);
        self::assertStringContainsString('SQLite', (string) $role->problem);

        $this->configure(['server_role' => 'all']);
        self::assertNull(ServerRole::resolve($this->directory, 'prod')->problem, 'SQLite with the role all is fine.');
    }

    public static function sqliteUrls(): iterable
    {
        yield 'file' => ['sqlite:///%kernel.project_dir%/var/data.db'];
        yield 'pdo' => ['pdo-sqlite:///var/data.db'];
    }

    public function testAnUnreadableConfigurationRunsAsAll(): void
    {
        file_put_contents($this->directory.'/config/aggregate.yaml', "server_role: [collection\n");

        self::assertSame('all', ServerRole::resolve($this->directory, 'prod')->role);
    }

    /** @param list<string> $files */
    #[DataProvider('routeFiles')]
    public function testEachRoleLoadsItsRouteFiles(string $role, array $files): void
    {
        $serverRole = ServerRole::of($role, self::SHARED_DATABASE);

        self::assertSame($files, $serverRole->routeFiles());
        self::assertSame(array_values(array_diff(ServerRole::ROUTE_FILES['all'], $files)), $serverRole->skippedRouteFiles());
    }

    public static function routeFiles(): iterable
    {
        yield 'all' => ['all', ['routes_health.yaml', 'routes_branding.yaml', 'routes_collection.yaml', 'routes_admin_api.yaml']];
        yield 'administration' => ['administration', ['routes_health.yaml', 'routes_branding.yaml', 'routes_admin_api.yaml']];
        yield 'collection' => ['collection', ['routes_health.yaml', 'routes_branding.yaml', 'routes_collection.yaml']];
        yield 'worker' => ['worker', ['routes_health.yaml']];
    }

    public function testControllersAreReadFromTheRouteFilesTheyAreRemovedBy(): void
    {
        $config = dirname(__DIR__, 3).'/config/';
        $controllers = [];
        foreach (ServerRole::ROUTE_FILES['all'] as $file) {
            $controllers[$file] = ServerRole::controllersInRouteFile($config.$file);
            foreach ($controllers[$file] as $class) {
                self::assertTrue(class_exists($class), $class.' in '.$file.' is not a class.');
            }
        }

        self::assertSame(['App\\Controller\\HealthController'], $controllers['routes_health.yaml']);
        self::assertContains('App\\Controller\\ReceiveController', $controllers['routes_collection.yaml']);
        self::assertSame(['App\\Controller\\HomeController', 'App\\Controller\\ReportingApiController', 'App\\Controller\\McpController', 'App\\Controller\\ClusterConfigController'], $controllers['routes_admin_api.yaml']);
        self::assertSame([], ServerRole::controllersInRouteFile($config.'missing.yaml'));
        self::assertNotContains('App\\Controller\\McpAuthorizeController', array_merge(...array_values($controllers)),
            'MCP consent needs a dashboard session, so it stays in routes_dashboard.yaml.');
    }

    /** @param list<string> $allowed @param list<string> $refused */
    #[DataProvider('commands')]
    public function testEachRoleRunsOnlyItsCommands(string $role, array $allowed, array $refused): void
    {
        $serverRole = ServerRole::of($role, self::SHARED_DATABASE);
        foreach ($allowed as $command) {
            self::assertTrue($serverRole->allowsCommand($command), $role.' should run '.$command);
        }
        foreach ($refused as $command) {
            self::assertFalse($serverRole->allowsCommand($command), $role.' should refuse '.$command);
        }
    }

    public static function commands(): iterable
    {
        $everywhere = ['list', 'cache:clear', 'cache:pool:prune', 'app:server:role', 'app:updates:check', 'doctrine:migrations:status', 'lint:container'];
        $administration = ['doctrine:migrations:migrate', 'app:install', 'app:updates:apply', 'app:create-website', 'app:scheduler:run', 'app:reporting-database:configure', 'messenger:setup-transports'];
        yield 'all' => ['all', [...$everywhere, ...$administration, 'messenger:consume'], []];
        yield 'administration' => ['administration', [...$everywhere, ...$administration], []];
        yield 'collection' => ['collection', [...$everywhere, 'app:assets:build-js'], [...$administration, 'messenger:consume', 'app:cache-like']];
        yield 'worker' => ['worker', [...$everywhere, 'messenger:consume', 'app:tracking:retry-failed'], [...$administration, 'app:assets:build-js']];
    }

    public function testRefusalsNameTheServerThatDoesTheWork(): void
    {
        self::assertSame(
            'This is a collection server: app:install runs on the administration server (https://admin.example.com), not here.',
            ServerRole::of('collection', self::SHARED_DATABASE, 'https://admin.example.com')->refusal('app:install'),
        );
        self::assertSame(
            'This is a collection server: messenger:consume runs on a worker server, not here.',
            ServerRole::of('collection', self::SHARED_DATABASE)->refusal('messenger:consume'),
        );

        $this->expectException(ServerRoleRefusal::class);
        $this->expectExceptionMessage('This is a worker server: its configuration is changed on the administration server and comes from there');
        ServerRole::of('worker', self::SHARED_DATABASE)->assertWritesConfiguration();
    }

    private function configure(array $settings): void
    {
        file_put_contents($this->directory.'/config/aggregate.yaml', Yaml::dump($settings, 4));
    }
}
