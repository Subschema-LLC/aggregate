<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\ForensicDatabaseController;
use App\Security\Access\DashboardPages;
use App\Service\AggregateConfigLoader;
use App\Service\BigQuery\BigQuerySettings;
use App\Service\ForensicDatabase\ForensicCatalog;
use App\Service\ForensicDatabase\ForensicDatabaseBackgroundSync;
use App\Service\ForensicDatabase\ForensicDatabaseConfigurator;
use App\Service\ForensicDatabase\ForensicDatabaseConnection;
use App\Service\ForensicDatabase\ForensicDatabaseSettings;
use App\Service\ForensicDatabase\ForensicDatabaseSyncRunner;
use App\Service\LocalEnvironmentFile;
use App\Service\ReportingDatabase\ReportingDatabaseConnection;
use App\Service\ReportingDatabase\ReportingDatabaseSettings;
use App\Service\ViewSync\ViewCatalog;
use App\Service\ViewSync\ViewSyncDestinations;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Yaml\Yaml;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/** The forensic database page: settings and status for administrators, never forensic rows. */
final class ForensicDatabaseControllerTest extends TestCase
{
    private const URL = 'postgresql://forensic_writer:s3cret-password@db.example.net:5433/aggregate_forensic?serverVersion=16';

    private string $projectDir;
    private array $environment;
    private ForensicDatabaseSyncRunner $runner;
    private ?ForensicDatabaseSettings $settings = null;
    private Session $session;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        foreach ([...array_keys(ForensicDatabaseSettings::defaults()), 'dashboard_enabled', 'forensic_database_url', 'reporting_database_url'] as $key) {
            unset($_ENV[strtoupper($key)], $_SERVER[strtoupper($key)]);
        }
        $this->projectDir = sys_get_temp_dir().'/aggregate-forensic-controller-'.bin2hex(random_bytes(6));
        mkdir($this->projectDir.'/config', 0700, true);
        $this->writeYaml([]);
        $this->runner = $this->createMock(ForensicDatabaseSyncRunner::class);
        $this->runner->method('settings')->willReturnCallback(fn (): ForensicDatabaseSettings => $this->settings);
        $this->runner->method('status')->willReturnCallback(static fn (array $settings): array => [
            'last_run' => null,
            'views' => array_map(static fn (string $view): array => [
                'view' => $view, 'private' => false, 'description' => '', 'status' => 'succeeded',
                'started_at' => null, 'finished_at' => null, 'succeeded_at' => null, 'row_count' => 1234, 'message' => null, 'next_due' => null,
            ], ForensicDatabaseSettings::selectedViews($settings)),
            'scheduler_late' => false,
        ]);
        $this->session = new Session(new MockArraySessionStorage());
    }

    protected function tearDown(): void
    {
        [$_ENV, $_SERVER] = $this->environment;
        foreach ([...glob($this->projectDir.'/config/*') ?: [], ...glob($this->projectDir.'/.env*') ?: []] as $file) {
            unlink($file);
        }
        rmdir($this->projectDir.'/config');
        rmdir($this->projectDir);
    }

    public function testThePageHoldsSettingsAndStatusOnly(): void
    {
        $request = Request::create('/dashboard/forensic-database');
        $content = (string) $this->controller($request)->index($request)->getContent();
        $crawler = new Crawler($content);

        self::assertSame('/dashboard/forensic-database', $crawler->filter('nav[aria-label="Sync destinations"] a[aria-current="page"]')->attr('href'));
        self::assertStringContainsString('PostgreSQL database aggregate_forensic on db.example.net:5433', $crawler->text());
        self::assertStringNotContainsString('s3cret-password', $content);
        self::assertStringContainsString('aggregate-forensic-copy', $crawler->text());
        self::assertCount(count(ForensicCatalog::TABLES), $crawler->filter('input[name="tables[]"][checked]'));
        self::assertCount(count(ViewCatalog::PRIVATE), $crawler->filter('input[name="views[]"][checked]'));
        // The status table names each copy and counts its rows; it holds no row of data.
        self::assertSame(count(ForensicCatalog::TABLES) + count(ViewCatalog::PRIVATE), $crawler->filter('.reporting-database-status tbody tr')->count());
        self::assertStringContainsString('1,234', $crawler->filter('.reporting-database-status')->text());
        self::assertSame('disabled', $crawler->filter('button[form="forensic-database-sync"]')->attr('disabled'));
    }

    public function testThePageCannotReadForensicRows(): void
    {
        // Nothing the page is given can read a table: no reader, exporter or database connection.
        foreach ((new \ReflectionMethod(ForensicDatabaseController::class, '__construct'))->getParameters() as $parameter) {
            $type = (string) $parameter->getType();
            self::assertNotContains($type, [\App\Service\ForensicDatabase\ForensicReader::class, \App\Service\ViewSync\ViewReader::class, \App\Service\ViewSync\ViewExporter::class, \Doctrine\DBAL\Connection::class], $parameter->getName());
        }
        // And no route returns anything but the page, its JSON status and redirects.
        foreach ((new \ReflectionClass(ForensicDatabaseController::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getAttributes(\Symfony\Component\Routing\Annotation\Route::class) !== []) {
                self::assertContains((string) $method->getReturnType(), [\Symfony\Component\HttpFoundation\Response::class, \Symfony\Component\HttpFoundation\JsonResponse::class], $method->getName());
            }
        }
        self::assertSame(['running', 'last_run', 'views'], array_keys(json_decode((string) $this->controller()->status()->getContent(), true)));
    }

    public function testOnlyAdministratorsReachAnyForensicRoute(): void
    {
        $routes = array_filter(DashboardPages::ROUTES, static fn (string $route): bool => str_starts_with($route, 'app_forensic_database'), ARRAY_FILTER_USE_KEY);
        self::assertCount(9, $routes);
        foreach ($routes as $route => $requirement) {
            self::assertSame([DashboardPages::ADMIN], $requirement, $route);
        }

        $this->expectException(AccessDeniedException::class);
        $request = Request::create('/dashboard/forensic-database');
        $this->controller($request, admin: false)->index($request);
    }

    public function testConfirmingRestrictedAccessNeedsTheCheckbox(): void
    {
        $request = $this->post(['access_restricted' => '1']);
        $this->controller($request)->saveAccess($request);
        self::assertStringContainsString('Confirm that only the people who investigate problems', implode(' ', $this->session->getFlashBag()->get('error')));
        self::assertArrayNotHasKey('forensic_database_access_restricted', Yaml::parseFile($this->projectDir.'/config/aggregate.yaml'));

        $request = $this->post(['access_restricted' => '1', 'access_confirmed' => '1']);
        $this->controller($request)->saveAccess($request);
        self::assertTrue(Yaml::parseFile($this->projectDir.'/config/aggregate.yaml')['forensic_database_access_restricted']);

        // Withdrawing needs no confirmation.
        $request = $this->post(['access_restricted' => '0']);
        $this->controller($request)->saveAccess($request);
        self::assertFalse(Yaml::parseFile($this->projectDir.'/config/aggregate.yaml')['forensic_database_access_restricted']);
    }

    public function testAddingACopyNeedsTheConfirmationAndRemovingOneDoesNot(): void
    {
        $request = $this->post(['tables' => ['forensic_events_v1'], 'views' => []]);
        $this->controller($request)->saveViews($request);
        self::assertSame([], $this->session->getFlashBag()->get('error'));
        self::assertSame(['forensic_events_v1'], Yaml::parseFile($this->projectDir.'/config/aggregate.yaml')['forensic_database_tables']);

        $request = $this->post(['tables' => ['forensic_events_v1', 'forensic_archive_goals_v1'], 'views' => []]);
        $this->controller($request)->saveViews($request);
        self::assertStringContainsString('Confirm that what you added', implode(' ', $this->session->getFlashBag()->get('error')));
        self::assertSame(['forensic_events_v1'], Yaml::parseFile($this->projectDir.'/config/aggregate.yaml')['forensic_database_tables']);

        $request = $this->post(['tables' => ['forensic_events_v1', 'forensic_archive_goals_v1'], 'views' => [], 'confirmed' => '1']);
        $this->controller($request)->saveViews($request);
        self::assertSame(['forensic_events_v1', 'forensic_archive_goals_v1'], Yaml::parseFile($this->projectDir.'/config/aggregate.yaml')['forensic_database_tables']);
    }

    private function writeYaml(array $values): void
    {
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump(['admin_token' => 'kept', ...$values], 4, 2));
    }

    private function post(array $values = []): Request
    {
        $request = Request::create('/dashboard/forensic-database', 'POST', ['_csrf_token' => 'valid-token', ...$values]);
        $request->setSession($this->session);

        return $request;
    }

    private function controller(?Request $request = null, bool $admin = true): ForensicDatabaseController
    {
        $config = new AggregateConfigLoader($this->projectDir, 'test');
        $application = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->settings = new ForensicDatabaseSettings($config);
        $connection = new ForensicDatabaseConnection(self::URL, $this->projectDir, $application, new ReportingDatabaseConnection(null, $this->projectDir, $application), $this->settings);
        $background = $this->createStub(ForensicDatabaseBackgroundSync::class);
        $background->method('cronLine')->willReturn('*/5 * * * * cd /srv/aggregate && php bin/console app:scheduler:run --no-interaction');
        $controller = new ForensicDatabaseController(
            $config,
            $this->settings,
            $connection,
            $this->runner,
            $background,
            new ForensicDatabaseConfigurator(new LocalEnvironmentFile($this->projectDir, 'test'), $connection),
            new NullLogger(),
            new ViewSyncDestinations(new BigQuerySettings($config, $this->projectDir), new ReportingDatabaseSettings($config), $this->settings),
        );
        $request ??= Request::create('/dashboard/forensic-database');
        $request->setSession($this->session);
        $authorization = $this->createStub(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willReturnCallback(static fn (string $role): bool => $admin && ($role === 'ROLE_ADMIN' || str_starts_with($role, 'DASHBOARD_')));
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturnCallback(static fn (CsrfToken $token): bool => $token->getId() === ForensicDatabaseController::CSRF_TOKEN_ID && $token->getValue() === 'valid-token');
        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(self::url(...));
        $stack = new RequestStack();
        $stack->push($request);
        $container = new Container();
        $container->set('security.authorization_checker', $authorization);
        $container->set('security.csrf.token_manager', $csrf);
        $tokens = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken(new InMemoryUser('scott', null, ['ROLE_ADMIN']), 'main', ['ROLE_ADMIN']));
        $container->set('security.token_storage', $tokens);
        $container->set('router', $router);
        $container->set('request_stack', $stack);
        $container->set('twig', $this->twig());
        $controller->setContainer($container);

        return $controller;
    }

    private function twig(): Environment
    {
        $twig = new Environment(new ChainLoader([
            new ArrayLoader(['base.html.twig' => '{% block body %}{% endblock %}']),
            new FilesystemLoader(dirname(__DIR__, 2).'/templates'),
        ]), ['strict_variables' => true]);
        $twig->addGlobal('app_branding', ['name' => 'Aggregate']);
        $twig->addFunction(new TwigFunction('path', self::url(...)));
        $twig->addFunction(new TwigFunction('csrf_token', static fn (string $id): string => $id === ForensicDatabaseController::CSRF_TOKEN_ID ? 'valid-token' : 'wrong-token'));
        \App\Tests\Support\TwigComponents::register($twig);

        return $twig;
    }

    private static function url(string $name, array $parameters = []): string
    {
        return match ($name) {
            'app_scheduled_tasks' => '/dashboard/scheduled-tasks',
            'app_bigquery' => '/dashboard/bigquery',
            'app_reporting_database' => '/dashboard/reporting-database',
            default => '/dashboard/forensic-database'.match ($name) {
                'app_forensic_database' => '',
                'app_forensic_database_status' => '/status',
                'app_forensic_database_access' => '/access',
                'app_forensic_database_views' => '/views',
                'app_forensic_database_schedule' => '/schedule',
                'app_forensic_database_test' => '/test',
                'app_forensic_database_sync' => '/sync',
                'app_forensic_database_connection' => '/connection',
                'app_forensic_database_connection_remove' => '/connection/remove',
            },
        }.($parameters === [] ? '' : '?'.http_build_query($parameters));
    }
}
