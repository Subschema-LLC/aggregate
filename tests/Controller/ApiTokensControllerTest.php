<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\ApiTokensController;
use App\Service\AggregateConfigLoader;
use App\Service\FeatureFlags;
use App\Service\Mcp\OAuth\OAuthStore;
use App\Service\ReportingApi\ApiTokenStore;
use App\Service\ReportingApi\ReportingApiSettings;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Filesystem;
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
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Yaml\Yaml;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class ApiTokensControllerTest extends TestCase
{
    private string $projectDir;
    private array $environment;
    private ?Session $session = null;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        foreach (['DASHBOARD_ENABLED', 'REPORTING_API_PRIVATE_VIEWS', 'REPORTING_API_MAX_PAGE_SIZE'] as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
        }
        $this->projectDir = sys_get_temp_dir().'/aggregate-api-tokens-page-'.bin2hex(random_bytes(8));
        mkdir($this->projectDir.'/config', 0700, true);
        $this->configure();
    }

    protected function tearDown(): void
    {
        [$_ENV, $_SERVER] = $this->environment;
        (new Filesystem())->remove($this->projectDir);
    }

    public function testThePageListsTokensAndOffersTheForms(): void
    {
        $this->store()->create('Notebook', ['bi_anonymous_daily_v1'], [], null, 'scott');

        $crawler = $this->crawl($this->call('index', $this->request('GET', '/dashboard/api-tokens')));

        self::assertSame('API tokens - Example Analytics', $crawler->filter('title')->text());
        self::assertCount(1, $crawler->filter('form[action="/dashboard/api-tokens/create"][data-turbo="false"]'), 'The create form is a plain post, so the new token is never kept in the session');
        self::assertCount(1, $crawler->filter('form[action="/dashboard/api-tokens/settings"]'));
        self::assertStringContainsString('Notebook', $crawler->filter('.api-tokens-table')->text());
        self::assertStringContainsString('Never', $crawler->filter('.api-tokens-table')->text());
        self::assertCount(1, $crawler->filter('.api-tokens-table form[action$="/revoke"]'));
        self::assertCount(0, $crawler->filter('input[name="private_views[]"]'), 'Private views are not offered while they are off');
        self::assertSame('0', $crawler->filter('select[name="private_views"] option[selected]')->attr('value'));
        self::assertSame('10000', $crawler->filter('input[name="max_page_size"]')->attr('value'));
        self::assertStringNotContainsString('sha256:', $crawler->html());
    }

    public function testANewTokenIsShownOnceInTheResponseAndNotInTheSession(): void
    {
        $response = $this->call('create', $this->request('POST', '/dashboard/api-tokens/create', [
            '_csrf_token' => 'valid', 'name' => 'Power BI', 'scope' => 'selected', 'views' => ['bi_anonymous_daily_v1'], 'expires' => 'never',
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $crawler = $this->crawl($response);
        $secret = $crawler->filter('#api-token-secret')->text();
        self::assertMatchesRegularExpression('/^agg_[a-f0-9]{8}_[A-Za-z0-9_-]{43}$/D', $secret);
        self::assertStringContainsString(ApiTokenStore::NEVER_EXPIRES_WARNING, $crawler->filter('.api-token-created')->text());
        self::assertStringNotContainsString($secret, serialize($this->session?->all()), 'The token is not stored in the session');
        self::assertStringNotContainsString($secret, (string) file_get_contents($this->store()->path()));
        $token = array_values($this->store()->tokens())[0];
        self::assertSame(['bi_anonymous_daily_v1'], $token->views);
        self::assertSame('admin', $token->createdBy);
        self::assertSame($token->id, $this->store()->authenticate($secret)?->id);
    }

    public function testPrivateViewsNeedTheToggleAndAConfirmation(): void
    {
        $form = ['_csrf_token' => 'valid', 'name' => 'Raw', 'scope' => 'selected', 'views' => ['bi_dim_website_token_v1'], 'private_views' => ['analytics_custom_events_v1'], 'expires' => '90d'];
        $this->assertRedirectsWithError('create', $form, 'Confirm that this token may read row-level');
        $this->assertRedirectsWithError('create', [...$form, 'private_views_confirmed' => '1'], 'Private views are turned off');

        $this->configure(['reporting_api_private_views' => true]);
        self::assertCount(count(\App\Service\ViewSync\ViewCatalog::PRIVATE), $this->crawl($this->call('index', $this->request('GET', '/dashboard/api-tokens')))->filter('input[name="private_views[]"]'));
        $response = $this->call('create', $this->request('POST', '/dashboard/api-tokens/create', [...$form, 'private_views_confirmed' => '1']));
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('row-level or unsuppressed data: analytics_custom_events_v1', $this->crawl($response)->filter('.api-token-created')->text());
    }

    public function testInvalidFormsChangeNothing(): void
    {
        $this->assertRedirectsWithError('create', ['_csrf_token' => 'invalid', 'name' => 'X', 'scope' => 'all', 'expires' => '1y'], 'Invalid security token', csrfValid: false);
        $this->assertRedirectsWithError('create', ['_csrf_token' => 'valid', 'name' => 'X', 'scope' => 'every', 'expires' => '1y'], 'Choose which approved views');
        $this->assertRedirectsWithError('create', ['_csrf_token' => 'valid', 'name' => 'X', 'scope' => 'all', 'expires' => 'date'], 'Choose the date');
        $this->assertRedirectsWithError('create', ['_csrf_token' => 'valid', 'name' => 'X', 'scope' => 'all', 'expires' => 'date', 'expires_date' => '2020-01-01'], 'must be in the future');
        $this->assertRedirectsWithError('create', ['_csrf_token' => 'valid', 'name' => 'X', 'scope' => 'all', 'expires' => '7d'], 'Choose when the token expires');
        self::assertSame([], $this->store()->tokens());
    }

    public function testATokenExpiresOnAChosenDateOfAnyDistance(): void
    {
        $this->call('create', $this->request('POST', '/dashboard/api-tokens/create', ['_csrf_token' => 'valid', 'name' => 'Long', 'scope' => 'all', 'expires' => 'date', 'expires_date' => '2075-06-30']));
        self::assertSame('2075-06-30', array_values($this->store()->tokens())[0]->expiresAt?->format('Y-m-d'));
    }

    public function testSettingsAreSavedToYamlUnlessTheEnvironmentControlsThem(): void
    {
        $response = $this->call('saveSettings', $this->request('POST', '/dashboard/api-tokens/settings', ['_csrf_token' => 'valid', 'private_views' => '1', 'max_page_size' => '250000']));
        self::assertSame(302, $response->getStatusCode());
        $yaml = Yaml::parseFile($this->projectDir.'/config/aggregate.yaml');
        self::assertTrue($yaml['reporting_api_private_views']);
        self::assertSame(250000, $yaml['reporting_api_max_page_size']);
        self::assertTrue($yaml['feature_flags']['reporting_api']['enabled'], 'Other settings are kept');

        $this->assertRedirectsWithError('saveSettings', ['_csrf_token' => 'valid', 'private_views' => '0', 'max_page_size' => '0'], 'reporting_api_max_page_size must be');
        self::assertTrue(Yaml::parseFile($this->projectDir.'/config/aggregate.yaml')['reporting_api_private_views'], 'Nothing is saved when a value is invalid');

        $_SERVER['REPORTING_API_PRIVATE_VIEWS'] = 'false';
        $crawler = $this->crawl($this->call('index', $this->request('GET', '/dashboard/api-tokens')));
        self::assertCount(1, $crawler->filter('select[name="private_views"][disabled]'));
        self::assertStringContainsString('REPORTING_API_PRIVATE_VIEWS', $crawler->text());
        $this->call('saveSettings', $this->request('POST', '/dashboard/api-tokens/settings', ['_csrf_token' => 'valid', 'private_views' => '1', 'max_page_size' => '500']));
        $yaml = Yaml::parseFile($this->projectDir.'/config/aggregate.yaml');
        self::assertTrue($yaml['reporting_api_private_views'], 'A locked setting keeps its YAML value');
        self::assertSame(500, $yaml['reporting_api_max_page_size']);
    }

    public function testWhileTheApiIsOffTokensCanStillBeRevokedButNotCreated(): void
    {
        [$token] = $this->store()->create('Leaked', null, [], null, 'scott');
        $this->configure(flag: false);

        $crawler = $this->crawl($this->call('index', $this->request('GET', '/dashboard/api-tokens')));
        self::assertStringContainsString('The reporting API is turned off', $crawler->text());
        self::assertCount(0, $crawler->filter('form[action="/dashboard/api-tokens/create"]'));
        self::assertCount(0, $crawler->filter('form[action="/dashboard/api-tokens/settings"]'));

        foreach (['create' => '/dashboard/api-tokens/create', 'saveSettings' => '/dashboard/api-tokens/settings'] as $action => $path) {
            try {
                $this->call($action, $this->request('POST', $path, ['_csrf_token' => 'valid', 'name' => 'X', 'scope' => 'all', 'expires' => '1y']));
                self::fail('Creating tokens and saving settings are unavailable while the API is off.');
            } catch (NotFoundHttpException) {
            }
        }

        $response = $this->call('revoke', $this->request('POST', '/dashboard/api-tokens/'.$token->id.'/revoke', ['_csrf_token' => 'valid']), $token->id);
        self::assertSame(302, $response->getStatusCode());
        self::assertSame([], $this->store()->tokens());
        self::assertStringContainsString('Revoked token', implode(' ', $this->session?->getFlashBag()->get('success') ?? []));
    }

    public function testThePageIsUnavailableWithoutTheDashboard(): void
    {
        $_SERVER['DASHBOARD_ENABLED'] = '0';
        $this->expectException(NotFoundHttpException::class);
        $this->call('index', $this->request('GET', '/dashboard/api-tokens'));
    }

    /** @param array<string, mixed> $form */
    private function assertRedirectsWithError(string $action, array $form, string $message, bool $csrfValid = true): void
    {
        $response = $this->call($action, $this->request('POST', '/dashboard/api-tokens/x', $form), csrfValid: $csrfValid);
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/dashboard/api-tokens', $response->headers->get('Location'));
        self::assertStringContainsString($message, implode(' ', $this->session?->getFlashBag()->get('error') ?? []));
    }

    private function call(string $action, Request $request, ?string $id = null, bool $csrfValid = true): Response
    {
        $controller = $this->controller($request, $csrfValid);

        return match ($action) {
            'index' => $controller->index($request),
            'create' => $controller->create($request),
            'revoke' => $controller->revoke($request, (string) $id),
            'saveSettings' => $controller->saveSettings($request),
        };
    }

    private function crawl(Response $response): Crawler
    {
        return new Crawler((string) $response->getContent());
    }

    private function store(): ApiTokenStore
    {
        $config = new AggregateConfigLoader($this->projectDir, 'test');

        return new ApiTokenStore($this->projectDir, new FeatureFlags($config), new ReportingApiSettings($config));
    }

    private function controller(Request $request, bool $csrfValid): ApiTokensController
    {
        $config = new AggregateConfigLoader($this->projectDir, 'test');
        $controller = new ApiTokensController($config, $this->store(), new ReportingApiSettings($config), new FeatureFlags($config), new OAuthStore($this->projectDir), new NullLogger());

        $authorization = $this->createStub(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willReturn(true);
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturnCallback(static fn (CsrfToken $token): bool => $csrfValid && $token->getId() === ApiTokensController::CSRF_TOKEN_ID);
        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturn('/dashboard/api-tokens');
        $stack = new RequestStack();
        $stack->push($request);
        $tokens = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken(new InMemoryUser('admin', null, ['ROLE_ADMIN']), 'main', ['ROLE_ADMIN']));

        $twig = new Environment(new ChainLoader([
            new ArrayLoader(['base.html.twig' => '<title>{% block title %}{% endblock %}</title>{% block body %}{% endblock %}']),
            new FilesystemLoader(dirname(__DIR__, 2).'/templates'),
        ]), ['strict_variables' => true]);
        $twig->addGlobal('app_branding', ['name' => 'Example Analytics']);
        $twig->addFunction(new TwigFunction('path', static fn (string $route, array $parameters = []): string => match ($route) {
            'app_api_tokens_create' => '/dashboard/api-tokens/create',
            'app_api_tokens_settings' => '/dashboard/api-tokens/settings',
            'app_api_tokens_revoke' => '/dashboard/api-tokens/'.$parameters['id'].'/revoke',
            'app_mcp_connection_revoke' => '/dashboard/api-tokens/connections/'.$parameters['id'].'/revoke',
            'app_audit_trail' => '/dashboard/audit-trail?area[]=assistant',
            'app_feature_flags' => '/dashboard/feature-flags',
        }));
        $twig->addFunction(new TwigFunction('csrf_token', static fn (): string => 'rendered-token'));
        \App\Tests\Support\TwigComponents::register($twig);

        $container = new Container();
        $container->set('security.authorization_checker', $authorization);
        $container->set('security.token_storage', $tokens);
        $container->set('security.csrf.token_manager', $csrf);
        $container->set('router', $router);
        $container->set('request_stack', $stack);
        $container->set('twig', $twig);
        $controller->setContainer($container);

        return $controller;
    }

    /** @param array<string, mixed> $form */
    private function request(string $method, string $path, array $form = []): Request
    {
        $request = Request::create($path, $method, $form);
        $this->session = new Session(new MockArraySessionStorage());
        $request->setSession($this->session);

        return $request;
    }

    /** @param array<string, mixed> $settings */
    private function configure(array $settings = [], bool $flag = true): void
    {
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump([
            'feature_flags' => ['reporting_api' => ['enabled' => $flag, 'hide_from_navigation' => false]],
            ...$settings,
        ], 4));
    }
}
