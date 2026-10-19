<?php

declare(strict_types=1);

namespace App\Tests\Service\Operations;

use App\Kernel;
use App\Service\Operations\AdminActions;
use App\Service\Operations\AuditTrailSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Routing\RouteCollection;

final class AdminActionsTest extends TestCase
{
    private static ?AdminActionsTestKernel $kernel = null;
    private static string $temporaryDirectory = '';
    /** @var array{0: array<mixed>, 1: array<mixed>} */
    private static array $environment = [[], []];

    public static function setUpBeforeClass(): void
    {
        self::$environment = [$_ENV, $_SERVER];
        $_ENV['DASHBOARD_ENABLED'] = $_SERVER['DASHBOARD_ENABLED'] = '1';
        self::$temporaryDirectory = sys_get_temp_dir().'/aggregate-admin-actions-'.bin2hex(random_bytes(8));
        self::$kernel = new AdminActionsTestKernel(self::$temporaryDirectory);
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
     * A new form that changes something must be listed, or its reason not to
     * be given in NOT_AUDITED: otherwise the audit trail would silently miss it.
     */
    public function testEveryRouteThatAcceptsAPostIsAuditedOrExplained(): void
    {
        $missing = [];
        foreach ($this->routes()->all() as $name => $route) {
            $methods = $route->getMethods();
            $changes = $methods === [] || array_intersect($methods, ['POST', 'PUT', 'PATCH', 'DELETE']) !== [];
            if ($changes && !isset(AdminActions::ROUTES[$name]) && !isset(AdminActions::NOT_AUDITED[$name])) {
                $missing[] = $name.' '.$route->getPath();
            }
        }

        self::assertSame([], $missing, "These routes accept POST but are neither in AdminActions::ROUTES nor in AdminActions::NOT_AUDITED:\n".implode("\n", $missing));
    }

    public function testListedRoutesExistAcceptTheirMethodAndUseKnownAreas(): void
    {
        $routes = $this->routes();
        foreach (AdminActions::ROUTES as $name => $entry) {
            $route = $routes->get($name);
            self::assertNotNull($route, $name.' is listed but is not a route.');
            foreach ($entry[2] ?? ['POST'] as $method) {
                self::assertTrue($route->getMethods() === [] || in_array($method, $route->getMethods(), true), $name.' does not accept '.$method);
            }
            self::assertArrayHasKey($entry[0], AuditTrailSettings::AREAS, $name);
            self::assertMatchesRegularExpression('/^[a-z][a-z0-9_]{0,63}$/D', $entry[1], $name);
            self::assertNotSame(AuditTrailSettings::TASKS, $entry[0], 'Job runs are recorded by their processing tasks.');
        }
        foreach (array_keys(AdminActions::NOT_AUDITED) as $name) {
            self::assertNotNull($routes->get($name), $name.' is explained but is not a route.');
            self::assertArrayNotHasKey($name, AdminActions::ROUTES);
        }
        foreach (AdminActions::ALWAYS as $action) {
            self::assertContains($action, array_column(AdminActions::ROUTES, 1));
        }
    }

    public function testOnlyTheMethodsThatChangeSomethingAreRecorded(): void
    {
        self::assertSame(['area' => 'settings', 'action' => 'branding_saved'], AdminActions::forRoute('app_branding_settings_save', 'POST'));
        self::assertNull(AdminActions::forRoute('app_standalone_consent', 'GET'), 'showing the form changes nothing');
        self::assertSame(['area' => 'settings', 'action' => 'standalone_consent_saved'], AdminActions::forRoute('app_standalone_consent', 'POST'));
        self::assertSame(['area' => 'integrations', 'action' => 'bigquery_signed_in'], AdminActions::forRoute('app_bigquery_google_callback', 'GET'));
        self::assertSame(['area' => 'operations', 'action' => 'audit_trail_exported'], AdminActions::forRoute('app_audit_trail_export', 'GET'));
        self::assertNull(AdminActions::forRoute('app_audit_trail', 'GET'));
        self::assertNull(AdminActions::forRoute('api_receive', 'POST'));
    }

    /**
     * @param array<string, mixed>                                $arguments
     * @param array{area: string, action: string, at: string, subject: ?string}|null $expected
     */
    #[DataProvider('commands')]
    public function testCommandsThatChangeSomethingAreRecordedWithTheirRealOptions(string $command, array $arguments, ?array $expected): void
    {
        $definition = (new Application(self::$kernel))->find($command)->getDefinition();
        $input = new ArrayInput($arguments, $definition);

        self::assertSame($expected, AdminActions::forCommand($command, $input));
    }

    public static function commands(): iterable
    {
        $end = static fn (string $area, string $action, ?string $subject = null): array => ['area' => $area, 'action' => $action, 'at' => 'end', 'subject' => $subject];
        $start = static fn (string $action): array => ['area' => 'updates', 'action' => $action, 'at' => 'start', 'subject' => null];

        yield 'install' => ['app:install', ['--username' => 'admin', '--password' => 'not-recorded'], $end('users', 'installed', 'admin')];
        yield 'reset a password' => ['app:user:reset-password', ['username' => 'ana', '--password' => 'not-recorded'], $end('users', 'password_changed', 'ana')];
        yield 'create a group' => ['app:website-scopes', ['action' => 'create-group', '--name' => 'Brand A'], $end('websites', 'group_created', 'Brand A')];
        yield 'show a user\'s access' => ['app:user:access', ['username' => 'ana'], null];
        yield 'grant a user pages' => ['app:user:access', ['username' => 'ana', '--page' => ['setup:view']], $end('users', 'user_updated', 'ana')];
        yield 'make a user an administrator' => ['app:user:access', ['username' => 'ana', '--admin' => true], $end('users', 'user_updated', 'ana')];
        yield 'delete a user' => ['app:user:delete', ['username' => 'ana'], $end('users', 'user_deleted', 'ana')];
        yield 'list permission groups' => ['app:permission-groups', ['action' => 'list'], null];
        yield 'create a permission group' => ['app:permission-groups', ['action' => 'create', '--name' => 'Editors'], $end('users', 'permission_group_created', 'Editors')];
        yield 'delete a permission group' => ['app:permission-groups', ['action' => 'delete', '--id' => 'editors'], $end('users', 'permission_group_deleted', 'editors')];
        yield 'list groups' => ['app:website-scopes', ['action' => 'list'], null];
        yield 'remove the reporting database' => ['app:reporting-database:configure', ['--remove' => true], $end('integrations', 'reporting_database_connection_removed')];
        yield 'show the reporting timezone' => ['app:analytics:reporting-timezone', [], null];
        yield 'set the reporting timezone' => ['app:analytics:reporting-timezone', ['timezone' => 'America/Chicago'], $end('settings', 'reporting_timezone_saved')];
        yield 'regenerate views' => ['app:analytics:views:regenerate', [], $end('operations', 'reporting_views_regenerated')];
        yield 'preview views' => ['app:analytics:views:regenerate', ['--dry-run' => true], null];
        yield 'check views' => ['app:analytics:views:regenerate', ['--check' => true], null];
        yield 'discover properties' => ['app:analytics:views:regenerate', ['--discover' => true], null];
        yield 'export the model' => ['app:analytics:views:regenerate', ['--export-model' => true], null];
        yield 'export the glossary' => ['app:analytics:glossary:sync', ['--export' => true], null];
        yield 'maintenance status' => ['app:updates:maintenance', [], null];
        yield 'maintenance on' => ['app:updates:maintenance', ['action' => 'on'], $end('updates', 'maintenance_turned_on')];
        yield 'show the update method' => ['app:updates:method', [], null];
        yield 'update status' => ['app:updates:apply', ['--status' => true], null];
        yield 'update preflight' => ['app:updates:apply', ['--preflight' => true], null];
        yield 'update' => ['app:updates:apply', ['--yes' => true], $start('update_started')];
        yield 'resume an update' => ['app:updates:apply', ['--resume' => true], $start('update_resumed')];
        yield 'roll back' => ['app:updates:rollback', [], $start('update_rolled_back')];
        yield 'show the deployment action' => ['app:updates:deployed', ['--show-action' => true], null];
        yield 'record a deployment' => ['app:updates:deployed', [], $start('deployment_recorded')];
        yield 'not an administrator action' => ['app:scheduler:status', [], null];
    }

    public function testActionCodesReadAsLabels(): void
    {
        self::assertSame('BigQuery sync started', AdminActions::label('bigquery_sync_started'));
        self::assertSame('BI disclosure saved', AdminActions::label('bi_disclosure_saved'));
        self::assertSame('MySQL time zones loaded', AdminActions::label('mysql_time_zones_loaded'));
        self::assertSame('User created', AdminActions::label('user_created'));
    }

    private function routes(): RouteCollection
    {
        return self::$kernel->getContainer()->get('test.service_container')->get('router')->getRouteCollection();
    }
}

/** Boots with the dashboard, independent of the checkout's compiled container. */
final class AdminActionsTestKernel extends Kernel
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
