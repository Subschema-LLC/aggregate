<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\BiPageGroupsController;
use App\Service\AggregateConfigLoader;
use App\Service\AnalyticsPrivacySettings;
use App\Service\AnonymousBiViewManager;
use App\Service\BiPageGroups;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
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

final class BiPageGroupsControllerTest extends TestCase
{
    private string $directory;
    private array $environment;
    private AggregateConfigLoader $config;
    /** @var list<string> */
    private array $executed = [];
    private bool $failRegeneration = false;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        foreach (['DASHBOARD_ENABLED', 'ANONYMOUS_MIN_CELL_COUNT', 'ANONYMOUS_GEO_MIN_CELL_COUNT'] as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
        }
        $this->directory = sys_get_temp_dir().'/aggregate-page-groups-ui-'.bin2hex(random_bytes(6));
        mkdir($this->directory.'/config', 0700, true);
        file_put_contents($this->directory.'/config/aggregate.yaml', Yaml::dump([
            'operator_setting' => 'preserve',
            'anonymous_min_cell_count' => 6,
            BiPageGroups::KEY => [['pattern' => '/inventory/**', 'group' => 'inventory']],
        ], 4, 2));
        $this->config = new AggregateConfigLoader($this->directory, 'test');
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

    public function testPageShowsTheConfiguredRules(): void
    {
        $response = $this->controller(Request::create('/dashboard/data-model/page-groups'))->index();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $crawler = new Crawler((string) $response->getContent());
        self::assertSame('/inventory/** inventory', $crawler->filter('textarea#page-groups')->text());
        self::assertSame('/dashboard/data-model/page-groups/save', $crawler->filter('form')->attr('action'));
    }

    public function testSavingWritesYamlAndRegeneratesEveryBiViewWithTheConfiguredThreshold(): void
    {
        $request = $this->post(['page_groups' => "/inventory/** inventory\n/locations/* location page"]);
        $response = $this->controller($request)->save($request);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/dashboard/data-model/page-groups', $response->getTargetUrl());
        $saved = Yaml::parseFile($this->directory.'/config/aggregate.yaml');
        self::assertSame('preserve', $saved['operator_setting']);
        self::assertSame([
            ['pattern' => '/inventory/**', 'group' => 'inventory'],
            ['pattern' => '/locations/*', 'group' => 'location page'],
        ], $saved[BiPageGroups::KEY]);
        self::assertCount(8, $this->executed);
        $daily = implode("\n", array_filter($this->executed, static fn (string $sql): bool => str_contains($sql, 'CREATE VIEW bi_anonymous_daily_v1')));
        self::assertStringContainsString("THEN 'location page'", $daily);
        self::assertStringContainsString('WHERE daily_cells.event_count >= 6', $daily);
        self::assertSame(['Saved 2 page group rules and regenerated the BI views.'], $request->getSession()->getFlashBag()->peek('success'));
    }

    public function testInvalidRulesAreShownAgainAndNothingIsSaved(): void
    {
        $before = (string) file_get_contents($this->directory.'/config/aggregate.yaml');
        $request = $this->post(['page_groups' => "/blog/*/comments comments"]);
        $response = $this->controller($request)->save($request);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $crawler = new Crawler((string) $response->getContent());
        self::assertStringContainsString('only allowed in the last path segment', $crawler->filter('[role="alert"]')->text());
        self::assertSame('/blog/*/comments comments', $crawler->filter('textarea#page-groups')->text());
        self::assertSame($before, file_get_contents($this->directory.'/config/aggregate.yaml'));
        self::assertSame([], $this->executed);
    }

    public function testAnInvalidTokenChangesNothing(): void
    {
        $request = $this->post(['page_groups' => '/a a'], 'wrong');
        $this->controller($request)->save($request);

        self::assertSame([['pattern' => '/inventory/**', 'group' => 'inventory']], Yaml::parseFile($this->directory.'/config/aggregate.yaml')[BiPageGroups::KEY]);
        self::assertSame([], $this->executed);
        self::assertSame(['Invalid security token. Please try again.'], $request->getSession()->getFlashBag()->peek('error'));
    }

    public function testAFailedRegenerationKeepsTheSavedRulesAndPointsToTheCommand(): void
    {
        $this->failRegeneration = true;
        $request = $this->post(['page_groups' => '/locations/* location']);
        $this->controller($request)->save($request);

        self::assertSame([['pattern' => '/locations/*', 'group' => 'location']], Yaml::parseFile($this->directory.'/config/aggregate.yaml')[BiPageGroups::KEY]);
        self::assertStringContainsString('app:analytics:bi-views:regenerate', implode(' ', $request->getSession()->getFlashBag()->peek('warning')));
    }

    public function testTheDisabledDashboardHidesThePage(): void
    {
        $this->config->set('dashboard_enabled', false);

        $this->expectException(NotFoundHttpException::class);
        $this->controller(Request::create('/dashboard/data-model/page-groups'))->index();
    }

    public function testOnlyAdministratorsCanSave(): void
    {
        $request = $this->post(['page_groups' => '/a a']);

        $this->expectException(AccessDeniedException::class);
        $this->controller($request, admin: false)->save($request);
    }

    private function post(array $fields, string $token = 'valid-token'): Request
    {
        $request = Request::create('/dashboard/data-model/page-groups/save', 'POST', ['_csrf_token' => $token] + $fields);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    private function controller(Request $request, bool $admin = true): BiPageGroupsController
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());
        $connection->method('executeStatement')->willReturnCallback(function (string $sql): int {
            if ($this->failRegeneration) {
                throw new \RuntimeException('database unavailable');
            }
            $this->executed[] = $sql;

            return 0;
        });
        $pageGroups = new BiPageGroups($this->config);
        $controller = new BiPageGroupsController(
            $this->config,
            $pageGroups,
            new AnalyticsPrivacySettings($this->config),
            new AnonymousBiViewManager($connection, $pageGroups),
            new NullLogger(),
        );

        $authorization = $this->createStub(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willReturn($admin);
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturnCallback(static fn (CsrfToken $token): bool => $token->getId() === BiPageGroupsController::CSRF_TOKEN_ID && $token->getValue() === 'valid-token');
        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(self::url(...));
        $stack = new RequestStack();
        $stack->push($request);
        $twig = new Environment(new ChainLoader([new ArrayLoader(['base.html.twig' => '{% block body %}{% endblock %}']), new FilesystemLoader(dirname(__DIR__, 2).'/templates')]), ['strict_variables' => true]);
        $twig->addGlobal('app_branding', ['name' => 'Example Analytics']);
        $twig->addFunction(new TwigFunction('path', self::url(...)));
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

    private static function url(string $name, array $parameters = []): string
    {
        return $name === 'app_bi_page_groups_save' ? '/dashboard/data-model/page-groups/save' : '/dashboard/data-model/page-groups';
    }
}
