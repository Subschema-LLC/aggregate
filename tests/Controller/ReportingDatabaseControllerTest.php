<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\ReportingDatabaseController;
use App\Service\AggregateConfigLoader;
use App\Service\BigQuery\BigQueryViewCatalog;
use App\Service\LocalEnvironmentFile;
use App\Service\ReportingDatabase\ReportingDatabaseBackgroundSync;
use App\Service\ReportingDatabase\ReportingDatabaseConfigurator;
use App\Service\ReportingDatabase\ReportingDatabaseConnection;
use App\Service\ReportingDatabase\ReportingDatabaseException;
use App\Service\ReportingDatabase\ReportingDatabaseSettings;
use App\Service\ReportingDatabase\ReportingDatabaseSyncRunner;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
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

final class ReportingDatabaseControllerTest extends TestCase
{
    private const URL = 'postgresql://acme_writer:s3cret-password@db.example.net:5433/aggregate_reporting?serverVersion=16';

    private string $projectDir;
    private array $environment;
    private ReportingDatabaseSyncRunner $runner;
    private ReportingDatabaseBackgroundSync $background;
    private Session $session;
    private ?string $url = self::URL;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        foreach ([...array_keys(ReportingDatabaseSettings::defaults()), 'dashboard_enabled', 'reporting_database_url'] as $key) {
            unset($_ENV[strtoupper($key)], $_SERVER[strtoupper($key)]);
        }
        $this->projectDir = sys_get_temp_dir().'/aggregate-reporting-controller-'.bin2hex(random_bytes(6));
        mkdir($this->projectDir.'/config', 0700, true);
        $this->writeYaml([]);
        $this->runner = $this->createMock(ReportingDatabaseSyncRunner::class);
        $this->runner->method('status')->willReturnCallback(static fn (array $settings): array => [
            'last_run' => null,
            'views' => array_map(static fn (string $view): array => [
                'view' => $view, 'private' => str_starts_with($view, 'analytics_'), 'description' => '', 'status' => 'never',
                'started_at' => null, 'finished_at' => null, 'succeeded_at' => null, 'row_count' => null, 'message' => null, 'next_due' => null,
            ], ReportingDatabaseSettings::selectedViews($settings)),
            'scheduler_late' => $settings['reporting_database_enabled'],
        ]);
        $this->background = $this->createMock(ReportingDatabaseBackgroundSync::class);
        $this->background->method('cronLine')->willReturn('*/5 * * * * cd /srv/aggregate && php bin/console app:scheduler:run --no-interaction');
        $this->session = new Session(new MockArraySessionStorage());
    }

    protected function tearDown(): void
    {
        [$_ENV, $_SERVER] = $this->environment;
        foreach ([...glob($this->projectDir.'/config/*') ?: [], ...glob($this->projectDir.'/var/*') ?: [], ...glob($this->projectDir.'/.env*') ?: []] as $file) {
            unlink($file);
        }
        rmdir($this->projectDir.'/config');
        @rmdir($this->projectDir.'/var');
        rmdir($this->projectDir);
    }

    public function testThePageDescribesTheDatabaseWithoutItsCredentials(): void
    {
        $content = (string) $this->page()->getContent();
        $crawler = new Crawler($content);

        self::assertSame('Reporting database', $crawler->filter('h1')->text());
        self::assertStringContainsString('PostgreSQL database aggregate_reporting on db.example.net:5433', $crawler->text());
        self::assertStringNotContainsString('s3cret-password', $content);
        self::assertCount(count(BigQueryViewCatalog::APPROVED), $crawler->filter('input[name="views[]"][checked]'));
        self::assertCount(count(BigQueryViewCatalog::PRIVATE), $crawler->filter('input[name="private_views[]"]'));
        self::assertCount(0, $crawler->filter('input[name="private_views[]"][checked]'));
        // Sync is off by default, so it cannot be started; the connection can be tested.
        self::assertSame('disabled', $crawler->filter('button[form="reporting-database-sync"]')->attr('disabled'));
        self::assertNull($crawler->filter('button[form="reporting-database-test"]')->attr('disabled'));
        // The schedule is the server's one scheduled task; its page has the steps.
        self::assertGreaterThan(0, $crawler->filter('a[href="/dashboard/scheduled-tasks"]')->count());
    }

    public function testAMissingUrlIsExplainedAndBlocksTheButtons(): void
    {
        $this->url = null;
        $this->writeYaml(['reporting_database_enabled' => true]);
        $crawler = new Crawler((string) $this->page()->getContent());

        self::assertStringContainsString('REPORTING_DATABASE_URL is not set.', $crawler->text());
        self::assertSame('disabled', $crawler->filter('button[form="reporting-database-test"]')->attr('disabled'));
        self::assertSame('disabled', $crawler->filter('button[form="reporting-database-sync"]')->attr('disabled'));

        $this->background->expects(self::never())->method('start');
        $request = $this->post();
        $this->controller($request)->syncNow($request);
        self::assertStringContainsString('Set REPORTING_DATABASE_URL', implode(' ', $this->session->getFlashBag()->get('error')));
    }

    public function testAccessNeedsAnAdministratorAndTheDashboard(): void
    {
        try {
            $request = Request::create('/dashboard/reporting-database');
            $this->controller($request, admin: false)->index($request);
            self::fail('A non-admin opened the page.');
        } catch (AccessDeniedException) {
            self::addToAssertionCount(1);
        }

        $_ENV['DASHBOARD_ENABLED'] = 'false';
        $before = file_get_contents($this->projectDir.'/config/aggregate.yaml');
        foreach (['index', 'status', 'saveViews', 'saveSchedule', 'test', 'syncNow', 'saveConnection', 'removeConnection'] as $action) {
            $request = $this->post(['enabled' => '1']);
            try {
                $action === 'status' ? $this->controller($request)->status() : $this->controller($request)->$action($request);
                self::fail($action.' was available without the dashboard.');
            } catch (NotFoundHttpException) {
                self::assertSame($before, file_get_contents($this->projectDir.'/config/aggregate.yaml'));
            }
        }
    }

    public function testPrivateViewsNeedAConfirmationWhenAdded(): void
    {
        $before = file_get_contents($this->projectDir.'/config/aggregate.yaml');
        $request = $this->post(['views' => ['bi_anonymous_events_v1'], 'private_views' => ['analytics_custom_events_v1']]);
        $this->controller($request)->saveViews($request);
        self::assertStringContainsString('Confirm that the private views', implode(' ', $this->session->getFlashBag()->get('error')));
        self::assertSame($before, file_get_contents($this->projectDir.'/config/aggregate.yaml'));

        $request = $this->post(['views' => ['bi_anonymous_events_v1'], 'private_views' => ['analytics_custom_events_v1'], 'private_views_confirmed' => '1']);
        $this->controller($request)->saveViews($request);
        $yaml = Yaml::parseFile($this->projectDir.'/config/aggregate.yaml');
        self::assertSame('kept', $yaml['admin_token']);
        self::assertSame(['bi_anonymous_events_v1'], $yaml['reporting_database_views']);
        self::assertSame(['analytics_custom_events_v1'], $yaml['reporting_database_private_views']);
        self::assertStringContainsString('stay in the reporting database', implode(' ', $this->session->getFlashBag()->get('success')));

        // Keeping a confirmed view needs no new confirmation.
        $request = $this->post(['views' => [], 'private_views' => ['analytics_custom_events_v1']]);
        $this->controller($request)->saveViews($request);
        self::assertSame([], $this->session->getFlashBag()->get('error'));
        self::assertSame([], Yaml::parseFile($this->projectDir.'/config/aggregate.yaml')['reporting_database_views']);
    }

    public function testFormsRejectForgedTokensAndUnexpectedFields(): void
    {
        $before = file_get_contents($this->projectDir.'/config/aggregate.yaml');
        foreach ([
            ['_csrf_token' => 'forged', 'enabled' => '1'],
            ['_csrf_token' => 'valid-token', 'enabled' => '1', 'reporting_database_url' => 'mysql://root@localhost/x'],
            ['_csrf_token' => 'valid-token', 'enabled' => '1', 'interval' => '7'],
        ] as $values) {
            $request = Request::create('/dashboard/reporting-database/schedule', 'POST', $values);
            $request->setSession($this->session);
            $this->controller($request)->saveSchedule($request);
            self::assertNotSame([], $this->session->getFlashBag()->get('error'));
            self::assertSame($before, file_get_contents($this->projectDir.'/config/aggregate.yaml'));
        }
    }

    public function testScheduleSavesAndEnvironmentControlledValuesStay(): void
    {
        $_ENV['REPORTING_DATABASE_INTERVAL_MINUTES'] = '360';
        $request = $this->post(['enabled' => '1', 'interval' => '15']);
        $this->controller($request)->saveSchedule($request);

        $yaml = Yaml::parseFile($this->projectDir.'/config/aggregate.yaml');
        self::assertTrue($yaml['reporting_database_enabled']);
        self::assertArrayNotHasKey('reporting_database_interval_minutes', $yaml);
        $crawler = new Crawler((string) $this->page()->getContent());
        self::assertSame('disabled', $crawler->filter('#reporting-database-interval')->attr('disabled'));
        self::assertStringContainsString('REPORTING_DATABASE_INTERVAL_MINUTES', $crawler->text());
        self::assertStringContainsString('every 6 hours', $crawler->text());
        self::assertStringContainsString('The scheduled sync is not running.', $crawler->text());
    }

    public function testSyncNowStartsTheBackgroundCommandOnlyWhenSyncIsOn(): void
    {
        $this->background->expects(self::once())->method('start')->with('scott');
        $request = $this->post();
        $this->controller($request)->syncNow($request);
        self::assertStringContainsString('Turn the reporting database sync on', implode(' ', $this->session->getFlashBag()->get('error')));

        $this->writeYaml(['reporting_database_enabled' => true]);
        $request = $this->post();
        $this->controller($request)->syncNow($request);
        self::assertStringContainsString('The sync has started', implode(' ', $this->session->getFlashBag()->get('success')));

        // The page waits for the background run, without offering a second one.
        $crawler = new Crawler((string) $this->page()->getContent());
        $page = $crawler->filter('[data-controller="pages--reporting-database--index"]');
        self::assertSame('true', $page->attr('data-pages--reporting-database--index-running-value'));
        self::assertGreaterThan(time() - 60, (int) $page->attr('data-pages--reporting-database--index-since-value'));
        self::assertSame('disabled', $crawler->filter('button[form="reporting-database-sync"]')->attr('disabled'));
    }

    public function testTestConnectionReportsTheDatabaseOrTheProblem(): void
    {
        $this->runner->method('check')->willReturnOnConsecutiveCalls(
            ['engine' => 'PostgreSQL', 'host' => 'db.example.net', 'port' => 5433, 'database' => 'aggregate_reporting', 'server_version' => '16.4', 'tables' => ['bi_anonymous_events_v1']],
            self::throwException(new ReportingDatabaseException('Connected, but this database user cannot create and drop tables, which every sync does (SQLSTATE 42501): permission denied for schema public')),
        );
        $request = $this->post();
        $this->controller($request)->test($request);
        self::assertSame(['Connected to PostgreSQL database aggregate_reporting on db.example.net:5433 (server version 16.4). This user can create and drop tables. 1 copied view found.'], $this->session->getFlashBag()->get('success'));

        $this->controller($request)->test($request);
        self::assertStringContainsString('permission denied for schema public', implode(' ', $this->session->getFlashBag()->get('error')));
    }

    public function testTheConnectionFormShowsTheSavedConnectionWithoutItsPassword(): void
    {
        $crawler = new Crawler((string) $this->page()->getContent());
        $form = $crawler->filter('form#reporting-database-connection');

        self::assertSame('/dashboard/reporting-database/connection', $form->attr('action'));
        self::assertSame('checked', $form->filter('input[name="type"][value="pgsql"]')->attr('checked'));
        self::assertSame('db.example.net', $form->filter('input[name="host"]')->attr('value'));
        self::assertSame('5433', $form->filter('input[name="port"]')->attr('value'));
        self::assertSame('acme_writer', $form->filter('input[name="user"]')->attr('value'));
        self::assertSame('', (string) $form->filter('input[name="password"]')->attr('value'));
        self::assertStringContainsString('leave empty to keep it', (string) $form->filter('input[name="password"]')->attr('placeholder'));
        self::assertStringNotContainsString('s3cret-password', (string) $this->page()->getContent());
        // The URL comes from somewhere other than .env.local, so there is nothing to remove here.
        self::assertCount(0, $crawler->filter('button[form="reporting-database-remove-connection"]'));
    }

    public function testATestedConnectionIsSavedToEnvLocalAndCanBeRemoved(): void
    {
        $this->url = null;
        mkdir($this->projectDir.'/var');
        $request = $this->post(['type' => 'sqlite', 'database' => 'var/reporting.db', 'host' => '', 'port' => '', 'user' => '', 'password' => '']);
        $this->controller($request)->saveConnection($request);

        self::assertStringContainsString('Connected to SQLite file var/reporting.db', implode(' ', $this->session->getFlashBag()->get('success')));
        self::assertStringContainsString("REPORTING_DATABASE_URL='sqlite:///%kernel.project_dir%/var/reporting.db'", (string) file_get_contents($this->projectDir.'/.env.local'));

        $this->url = 'sqlite:///%kernel.project_dir%/var/reporting.db';
        $crawler = new Crawler((string) $this->page()->getContent());
        self::assertCount(1, $crawler->filter('button[form="reporting-database-remove-connection"]'));

        $request = $this->post();
        $this->controller($request)->removeConnection($request);
        self::assertStringContainsString('removed from .env.local', implode(' ', $this->session->getFlashBag()->get('success')));
        self::assertStringNotContainsString('REPORTING_DATABASE_URL', (string) file_get_contents($this->projectDir.'/.env.local'));
    }

    public function testAFailedConnectionKeepsTheFieldsButNotThePassword(): void
    {
        $request = $this->post(['type' => 'mysql', 'host' => 'db example', 'port' => '', 'database' => 'reporting', 'user' => 'sync', 'password' => 'typed-secret']);
        $this->controller($request)->saveConnection($request);

        self::assertStringContainsString('The connection was not saved: Enter the database server', implode(' ', $this->session->getFlashBag()->get('error')));
        self::assertFileDoesNotExist($this->projectDir.'/.env.local');
        self::assertStringNotContainsString('typed-secret', serialize($this->session->all()));
        $form = (new Crawler((string) $this->page()->getContent()))->filter('form#reporting-database-connection');
        self::assertSame('checked', $form->filter('input[name="type"][value="mysql"]')->attr('checked'));
        self::assertSame('db example', $form->filter('input[name="host"]')->attr('value'));
        self::assertSame('sync', $form->filter('input[name="user"]')->attr('value'));
    }

    public function testTheFormIsReplacedByTheReasonWhenEnvLocalWouldBeIgnored(): void
    {
        file_put_contents($this->projectDir.'/.env.local.php', '<?php return [];');
        $crawler = new Crawler((string) $this->page()->getContent());

        self::assertCount(0, $crawler->filter('form#reporting-database-connection'));
        self::assertStringContainsString('.env.local.php', $crawler->text());

        $request = $this->post(['type' => 'sqlite', 'database' => 'var/reporting.db']);
        $this->controller($request)->saveConnection($request);
        self::assertStringContainsString('.env.local.php', implode(' ', $this->session->getFlashBag()->get('error')));
        self::assertFileDoesNotExist($this->projectDir.'/.env.local');
    }

    public function testStatusEndpointReportsWhetherASyncIsRunning(): void
    {
        $body = json_decode((string) $this->controller()->status()->getContent(), true);

        self::assertFalse($body['running']);
        self::assertNull($body['last_run']);
        self::assertCount(count(BigQueryViewCatalog::APPROVED), $body['views']);
    }

    private function page(): Response
    {
        $request = Request::create('/dashboard/reporting-database');

        return $this->controller($request)->index($request);
    }

    private function writeYaml(array $values): void
    {
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump(['admin_token' => 'kept', ...$values], 4, 2));
    }

    private function post(array $values = []): Request
    {
        $request = Request::create('/dashboard/reporting-database', 'POST', ['_csrf_token' => 'valid-token', ...$values]);
        $request->setSession($this->session);

        return $request;
    }

    private function controller(?Request $request = null, bool $admin = true): ReportingDatabaseController
    {
        $config = new AggregateConfigLoader($this->projectDir, 'test');
        $application = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection = new ReportingDatabaseConnection($this->url, $this->projectDir, $application);
        $controller = new ReportingDatabaseController(
            $config,
            new ReportingDatabaseSettings($config),
            $connection,
            $this->runner,
            $this->background,
            new ReportingDatabaseConfigurator(new LocalEnvironmentFile($this->projectDir, 'test'), $connection),
            new NullLogger(),
        );
        $request ??= Request::create('/dashboard/reporting-database');
        $request->setSession($this->session);
        $authorization = $this->createStub(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willReturnCallback(static fn (string $role): bool => $admin && $role === 'ROLE_ADMIN');
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturnCallback(static fn (CsrfToken $token): bool => $token->getId() === ReportingDatabaseController::CSRF_TOKEN_ID && $token->getValue() === 'valid-token');
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
        $twig->addFunction(new TwigFunction('csrf_token', static fn (string $id): string => $id === ReportingDatabaseController::CSRF_TOKEN_ID ? 'valid-token' : 'wrong-token'));
        \App\Tests\Support\TwigComponents::register($twig);

        return $twig;
    }

    private static function url(string $name, array $parameters = []): string
    {
        if ($name === 'app_scheduled_tasks') {
            return '/dashboard/scheduled-tasks';
        }

        return '/dashboard/reporting-database'.match ($name) {
            'app_reporting_database' => '',
            'app_reporting_database_status' => '/status',
            'app_reporting_database_views' => '/views',
            'app_reporting_database_schedule' => '/schedule',
            'app_reporting_database_test' => '/test',
            'app_reporting_database_sync' => '/sync',
            'app_reporting_database_connection' => '/connection',
            'app_reporting_database_connection_remove' => '/connection/remove',
        }.($parameters === [] ? '' : '?'.http_build_query($parameters));
    }
}
