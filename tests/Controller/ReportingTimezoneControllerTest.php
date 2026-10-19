<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\ReportingTimezoneController;
use App\Service\AggregateConfigLoader;
use App\Service\AnalyticsPrivacySettings;
use App\Service\AnonymousBiViewManager;
use App\Service\MysqlTimeZoneTables;
use App\Service\ReportingTimezone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Yaml\Yaml;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class ReportingTimezoneControllerTest extends TestCase
{
    private string $directory;
    private array $environment;
    private AggregateConfigLoader $config;
    /** @var list<string> */
    private array $executed = [];

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        unset($_ENV['REPORTING_TIMEZONE'], $_SERVER['REPORTING_TIMEZONE'], $_ENV['DASHBOARD_ENABLED'], $_SERVER['DASHBOARD_ENABLED']);
        $this->directory = sys_get_temp_dir().'/aggregate-tz-ui-'.bin2hex(random_bytes(6));
        mkdir($this->directory.'/config', 0700, true);
        file_put_contents($this->directory.'/config/aggregate.yaml', Yaml::dump([
            'operator_setting' => 'preserve',
            ReportingTimezone::KEY => 'America/Chicago',
        ]));
        $this->config = new AggregateConfigLoader($this->directory, 'test');
        $this->executed = [];
    }

    protected function tearDown(): void
    {
        [$_ENV, $_SERVER] = $this->environment;
        foreach (glob($this->directory.'/config/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory.'/config');
        rmdir($this->directory);
    }

    public function testTheOldAddressOpensTheGeneralSettingsSection(): void
    {
        $response = $this->controller(Request::create('/dashboard/reporting/timezone'))->index();

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/dashboard/settings#reporting-timezone', $response->getTargetUrl());
        self::assertSame([], $this->executed);
    }

    public function testMysqlWithoutTimezoneTablesRefusesTheSaveAndKeepsTheLoadCommand(): void
    {
        $before = (string) file_get_contents($this->directory.'/config/aggregate.yaml');
        $request = $this->post(['reporting_timezone' => 'Europe/London']);
        $saved = $this->controller($request, platform: new MySQL80Platform())->save($request);

        self::assertInstanceOf(RedirectResponse::class, $saved);
        self::assertSame('/dashboard/settings#reporting-timezone', $saved->getTargetUrl());
        $refused = $request->getSession()->get(ReportingTimezoneController::SESSION_REFUSED_SAVE);
        self::assertSame('Europe/London', $refused['value']);
        self::assertNull($refused['error']);
        self::assertStringContainsString('mysql_tzinfo_to_sql /usr/share/zoneinfo', $refused['load_command']);
        self::assertSame($before, file_get_contents($this->directory.'/config/aggregate.yaml'));
        self::assertSame([], $this->executed);
    }

    public function testSavingWritesYamlAndRegeneratesTheEventViews(): void
    {
        $request = $this->post(['reporting_timezone' => 'Europe/London']);
        $response = $this->controller($request)->save($request);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/dashboard/settings#reporting-timezone', $response->getTargetUrl());
        $saved = Yaml::parseFile($this->directory.'/config/aggregate.yaml');
        self::assertSame('preserve', $saved['operator_setting']);
        self::assertSame('Europe/London', $saved[ReportingTimezone::KEY]);
        self::assertNotEmpty($this->executed);
        self::assertStringContainsString("AT TIME ZONE 'Europe/London'", $this->executed[0]);
        self::assertStringNotContainsString("AT TIME ZONE 'Europe/London'", $this->executed[1]);
        self::assertSame(['Saved the reporting timezone Europe/London and regenerated the BI views.'], $request->getSession()->getFlashBag()->peek('success'));
        self::assertNull($request->getSession()->get(ReportingTimezoneController::SESSION_REFUSED_SAVE));
    }

    public function testAnOffsetIsRefusedAndShownAgain(): void
    {
        $before = (string) file_get_contents($this->directory.'/config/aggregate.yaml');
        $request = $this->post(['reporting_timezone' => 'EST']);
        $response = $this->controller($request)->save($request);

        self::assertInstanceOf(RedirectResponse::class, $response);
        $refused = $request->getSession()->get(ReportingTimezoneController::SESSION_REFUSED_SAVE);
        self::assertSame('EST', $refused['value']);
        self::assertStringContainsString('IANA time zone name', $refused['error']);
        self::assertNull($refused['load_command']);
        self::assertSame($before, file_get_contents($this->directory.'/config/aggregate.yaml'));
        self::assertSame([], $this->executed);
    }

    public function testAForgedTokenSavesNothing(): void
    {
        $before = (string) file_get_contents($this->directory.'/config/aggregate.yaml');
        $request = Request::create('/dashboard/reporting/timezone/save', 'POST', ['_csrf_token' => 'forged', 'reporting_timezone' => 'Europe/London']);
        $request->setSession(new Session(new MockArraySessionStorage()));
        $response = $this->controller($request)->save($request);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(['Invalid security token. Please try again.'], $request->getSession()->getFlashBag()->peek('error'));
        self::assertSame($before, file_get_contents($this->directory.'/config/aggregate.yaml'));
    }

    public function testTheDisabledDashboardHidesThePage(): void
    {
        $this->config->set('dashboard_enabled', false);

        $this->expectException(NotFoundHttpException::class);
        $this->controller(Request::create('/dashboard/reporting/timezone'))->index();
    }

    public function testOnlyAdministratorsCanSave(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->controller($this->post(['reporting_timezone' => 'UTC']), admin: false)->save($this->post(['reporting_timezone' => 'UTC']));
    }

    private function post(array $fields): Request
    {
        $request = Request::create('/dashboard/reporting/timezone/save', 'POST', ['_csrf_token' => 'valid-token'] + $fields);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    private function controller(Request $request, bool $admin = true, ?AbstractPlatform $platform = null): ReportingTimezoneController
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform ?? new PostgreSQLPlatform());
        $connection->method('fetchOne')->willReturn(null);
        $connection->method('executeStatement')->willReturnCallback(function (string $sql): int {
            $this->executed[] = $sql;

            return 0;
        });
        $timezone = new ReportingTimezone($this->config);
        $controller = new ReportingTimezoneController(
            $this->config,
            $timezone,
            new AnalyticsPrivacySettings($this->config),
            new AnonymousBiViewManager($connection, null, $timezone, timeZones: new MysqlTimeZoneTables($connection, $this->directory.'/no-shadow')),
            new NullLogger(),
        );

        $authorization = $this->createStub(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willReturn($admin);
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturnCallback(static fn (CsrfToken $token): bool => $token->getValue() === 'valid-token');
        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(static fn (string $name): string => $name === 'app_application_settings' ? '/dashboard/settings' : '/dashboard/reporting/timezone');
        $stack = new RequestStack();
        $stack->push($request);
        $twig = new Environment(new ChainLoader([new ArrayLoader(['base.html.twig' => '{% block body %}{% endblock %}']), new FilesystemLoader(dirname(__DIR__, 2).'/templates')]), ['strict_variables' => true]);
        $twig->addGlobal('app_branding', ['name' => 'Example Analytics']);
        $twig->addFunction(new TwigFunction('path', static fn (string $name): string => $name === 'app_reporting_timezone_save' ? '/dashboard/reporting/timezone/save' : '/dashboard/reporting/timezone'));
        $twig->addFunction(new TwigFunction('csrf_token', static fn (): string => 'valid-token'));
        \App\Tests\Support\TwigComponents::register($twig);

        $container = new Container();
        $container->set('security.authorization_checker', $authorization);
        $container->set('security.csrf.token_manager', $csrf);
        $container->set('router', $router);
        $container->set('request_stack', $stack);
        $container->set('twig', $twig);
        $controller->setContainer($container);

        return $controller;
    }
}
