<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\ScheduledTasksController;
use App\Service\AggregateConfigLoader;
use App\Service\Operations\BackgroundCommand;
use App\Service\Operations\TaskTrigger;
use App\Service\Scheduler\ScheduledJob;
use App\Service\Scheduler\Scheduler;
use App\Service\Scheduler\SchedulerSetup;
use App\Service\Update\Toolchain;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class ScheduledTasksControllerTest extends TestCase
{
    private string $projectDir;
    private array $environment;
    private MockClock $clock;
    private BackgroundCommand&MockObject $background;
    private Session $session;
    private string $installDir = '/var/www/vhosts/accept.optinmodel.com/httpdocs';

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        unset($_ENV['DASHBOARD_ENABLED'], $_SERVER['DASHBOARD_ENABLED']);
        $this->projectDir = sys_get_temp_dir().'/aggregate-scheduled-tasks-'.bin2hex(random_bytes(6));
        mkdir($this->projectDir.'/config', 0700, true);
        mkdir($this->projectDir.'/var', 0700);
        file_put_contents($this->projectDir.'/config/aggregate.yaml', "admin_token: kept\n");
        $this->clock = new MockClock('2026-10-15 22:00:00 UTC');
        $this->background = $this->createMock(BackgroundCommand::class);
        $this->session = new Session(new MockArraySessionStorage());
    }

    protected function tearDown(): void
    {
        [$_ENV, $_SERVER] = $this->environment;
        array_map(unlink(...), [...glob($this->projectDir.'/config/*') ?: [], ...glob($this->projectDir.'/var/scheduler/*') ?: []]);
        @rmdir($this->projectDir.'/var/scheduler');
        rmdir($this->projectDir.'/var');
        rmdir($this->projectDir.'/config');
        rmdir($this->projectDir);
    }

    public function testPleskServersGetTheExactTaskFields(): void
    {
        $crawler = $this->page();

        self::assertSame('Scheduled tasks', $crawler->filter('h1')->text());
        self::assertStringContainsString('Not set up', $crawler->filter('#scheduler-status-title')->text());
        self::assertStringContainsString('accept.optinmodel.com → Scheduled Tasks → Add Task', $crawler->text());
        self::assertSame('Run a PHP script', $crawler->filter('#scheduler-plesk-type')->attr('value'));
        self::assertSame('httpdocs/bin/console', $crawler->filter('#scheduler-plesk-script')->attr('value'));
        self::assertSame('app:scheduler:run', $crawler->filter('#scheduler-plesk-arguments')->attr('value'));
        self::assertSame('*/5 * * * *', $crawler->filter('#scheduler-plesk-run')->attr('value'));
        self::assertSame(PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION, $crawler->filter('#scheduler-plesk-php')->attr('value'));
        self::assertSame(['Data lifecycle', 'BigQuery sync', 'Reporting database', 'Forensic database'], $crawler->filter('.scheduled-tasks-jobs a')->each(static fn (Crawler $link): string => $link->text()));
    }

    public function testOtherServersGetACrontabLineAndTheStateFollowsScheduledRuns(): void
    {
        $this->installDir = '/srv/aggregate';
        $crawler = $this->page();
        self::assertCount(0, $crawler->filter('#scheduler-plesk-type'));
        self::assertSame("*/5 * * * * cd '/srv/aggregate' && '/usr/bin/php' bin/console app:scheduler:run --no-interaction", $crawler->filter('#scheduler-cron')->attr('value'));

        // A run by hand is shown, but only the server's run turns the box green.
        $this->scheduler()->run(TaskTrigger::dashboard('scott'));
        $crawler = $this->page();
        self::assertStringContainsString('Not set up', $crawler->filter('#scheduler-status-title')->text());
        self::assertStringContainsString('Last run by hand: 2026-10-15 22:00 UTC (from this page)', $crawler->text());

        $this->scheduler()->run(TaskTrigger::schedule());
        $crawler = $this->page();
        self::assertStringContainsString('Working', $crawler->filter('#scheduler-status-title')->text());
        self::assertStringContainsString('Reporting database sync: Copied 3 views.', $crawler->filter('.scheduled-tasks-results')->text());

        $this->clock->modify('+31 minutes');
        self::assertStringContainsString('Late', $this->page()->filter('#scheduler-status-title')->text());
    }

    public function testRunNowStartsTheSchedulerInTheBackgroundForTheAdministrator(): void
    {
        $this->background->expects(self::once())->method('start')->with('app:scheduler:run', ['--requested-by=scott'], 'var/scheduler/last-output.log');

        $request = $this->post('forged');
        $this->controller($request)->run($request);
        self::assertSame(['Invalid security token. Please try again.'], $this->session->getFlashBag()->get('error'));

        $request = $this->post();
        $this->controller($request)->run($request);
        self::assertStringContainsString('The due jobs are running.', implode(' ', $this->session->getFlashBag()->get('success')));
        $crawler = $this->page();
        self::assertSame('disabled', $crawler->filter('form[action="/dashboard/scheduled-tasks/run"] button')->attr('disabled'));
        self::assertSame('true', $crawler->filter('[data-controller="pages--scheduled-tasks--index"]')->attr('data-pages--scheduled-tasks--index-running-value'));
    }

    public function testAccessNeedsAnAdministratorAndTheDashboard(): void
    {
        try {
            $request = Request::create('/dashboard/scheduled-tasks');
            $this->controller($request, admin: false)->index($request);
            self::fail('A non-admin opened the page.');
        } catch (AccessDeniedException) {
            self::addToAssertionCount(1);
        }

        $_ENV['DASHBOARD_ENABLED'] = 'false';
        $this->background->expects(self::never())->method('start');
        foreach (['index', 'status', 'run'] as $action) {
            $request = $this->post();
            try {
                $action === 'status' ? $this->controller($request)->status() : $this->controller($request)->$action($request);
                self::fail($action.' was available without the dashboard.');
            } catch (NotFoundHttpException) {
                self::addToAssertionCount(1);
            }
        }
    }

    private function page(): Crawler
    {
        $request = Request::create('/dashboard/scheduled-tasks');
        $response = $this->controller($request)->index($request);
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        return new Crawler((string) $response->getContent());
    }

    private function post(string $token = 'valid-token'): Request
    {
        $request = Request::create('/dashboard/scheduled-tasks/run', 'POST', ['_csrf_token' => $token]);
        $request->setSession($this->session);

        return $request;
    }

    private function scheduler(): Scheduler
    {
        $jobs = [];
        foreach (['analytics_maintenance' => 'Analytics maintenance', 'bigquery_sync' => 'BigQuery sync', 'reporting_database_sync' => 'Reporting database sync', 'forensic_database_sync' => 'Forensic database sync'] as $name => $label) {
            $job = $this->createStub(ScheduledJob::class);
            $job->method('name')->willReturn($name);
            $job->method('label')->willReturn($label);
            $job->method('status')->willReturn(['enabled' => $name !== 'bigquery_sync', 'schedule' => 'Daily', 'last_run' => null, 'last_status' => null, 'next_due' => null, 'problem' => null]);
            $job->method('runDue')->willReturn($name === 'reporting_database_sync' ? ['status' => 'ran', 'message' => 'Copied 3 views.'] : ['status' => 'not_due', 'message' => 'Not due yet.']);
            $jobs[] = $job;
        }

        return new Scheduler($jobs[0], $jobs[1], $jobs[2], $jobs[3], $this->projectDir, $this->clock, new NullLogger());
    }

    private function controller(Request $request, bool $admin = true): ScheduledTasksController
    {
        $toolchain = $this->createStub(Toolchain::class);
        $toolchain->method('php')->willReturn(['/usr/bin/php']);
        $controller = new ScheduledTasksController(
            new AggregateConfigLoader($this->projectDir, 'test'),
            $this->scheduler(),
            new SchedulerSetup($toolchain, $this->installDir),
            $this->background,
            new NullLogger(),
        );
        $request->setSession($this->session);
        $authorization = $this->createStub(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willReturnCallback(static fn (string $role): bool => $admin && ($role === 'ROLE_ADMIN' || str_starts_with($role, 'DASHBOARD_')));
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturnCallback(static fn (CsrfToken $token): bool => $token->getId() === ScheduledTasksController::CSRF_TOKEN_ID && $token->getValue() === 'valid-token');
        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(self::url(...));
        $stack = new RequestStack();
        $stack->push($request);
        $tokens = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken(new InMemoryUser('scott', null, ['ROLE_ADMIN']), 'main', ['ROLE_ADMIN']));
        $container = new Container();
        $container->set('security.authorization_checker', $authorization);
        $container->set('security.csrf.token_manager', $csrf);
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
        $twig->addFunction(new TwigFunction('csrf_token', static fn (string $id): string => $id === ScheduledTasksController::CSRF_TOKEN_ID ? 'valid-token' : 'wrong-token'));
        \App\Tests\Support\TwigComponents::register($twig);

        return $twig;
    }

    private static function url(string $name, array $parameters = []): string
    {
        return match ($name) {
            'app_scheduled_tasks' => '/dashboard/scheduled-tasks',
            'app_scheduled_tasks_status' => '/dashboard/scheduled-tasks/status',
            'app_scheduled_tasks_run' => '/dashboard/scheduled-tasks/run',
            'app_data_lifecycle' => '/dashboard/data-lifecycle',
            'app_bigquery' => '/dashboard/bigquery',
            'app_reporting_database' => '/dashboard/reporting-database',
            'app_forensic_database' => '/dashboard/forensic-database',
        };
    }
}
