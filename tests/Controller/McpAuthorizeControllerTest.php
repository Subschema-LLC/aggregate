<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\McpAuthorizeController;
use App\Service\AggregateConfigLoader;
use App\Service\AppBranding;
use App\Service\DocumentationLinks;
use App\Service\FeatureFlags;
use App\Service\Mcp\OAuth\ClientResolver;
use App\Service\Mcp\OAuth\OAuthClient;
use App\Service\Mcp\OAuth\OAuthServer;
use App\Service\Mcp\OAuth\OAuthStore;
use App\Service\ReportingApi\ApiTokenStore;
use App\Service\ReportingApi\ReportingApiSettings;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
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

/**
 * The page where an administrator approves an AI assistant: requests that
 * cannot be verified are answered on the page and never redirected, other
 * errors go back to the assistant, and an approval returns a code that the
 * assistant can exchange with its PKCE verifier.
 */
final class McpAuthorizeControllerTest extends TestCase
{
    private const REDIRECT = 'https://assistant.example/callback?keep=1';
    private const VERIFIER = 'a-verifier-that-is-long-enough-to-be-valid-0123456789';

    private string $projectDir;
    private OAuthClient $client;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/aggregate-mcp-authorize-'.bin2hex(random_bytes(8));
        mkdir($this->projectDir.'/config', 0700, true);
        $this->configure();
        $this->client = (new OAuthStore($this->projectDir))->registerClient('Example assistant', [self::REDIRECT]);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectDir);
    }

    public function testTheApprovalPageNamesTheAssistantAndWhereItReturns(): void
    {
        $response = $this->get($this->query());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $page = new Crawler((string) $response->getContent());
        self::assertStringContainsString('Connect Example assistant?', $page->filter('h1')->text());
        self::assertStringContainsString('assistant.example', $page->filter('.mcp-authorize-facts')->text());
        self::assertSame('S256', $page->filter('input[name="code_challenge_method"]')->attr('value'));
        self::assertSame('xyz', $page->filter('input[name="state"]')->attr('value'));
        self::assertCount(1, $page->filter('button[name="decision"][value="approve"]'));
        self::assertCount(1, $page->filter('button[name="decision"][value="deny"]'));
        self::assertCount(0, $page->filter('.notification.is-warning'), 'localhost counts as local development');

        $request = Request::create('http://analytics.example/dashboard/oauth/authorize', 'GET', [...$this->query(), 'resource' => 'http://analytics.example/mcp']);
        $insecure = new Crawler((string) $this->controller($request)->authorize($request)->getContent());
        self::assertStringContainsString('not opened over HTTPS', $insecure->filter('.notification.is-warning')->text());
    }

    public function testAnUnverifiableClientOrRedirectIsNeverRedirected(): void
    {
        foreach ([
            'unknown client' => ['client_id' => 'aggc_0000000000000000'],
            'no client' => ['client_id' => ''],
            'unregistered redirect' => ['redirect_uri' => 'https://evil.example/steal'],
            'no redirect' => ['redirect_uri' => ''],
        ] as $case => $change) {
            $response = $this->get([...$this->query(), ...$change]);
            self::assertSame(400, $response->getStatusCode(), $case);
            self::assertNull($response->headers->get('Location'), $case);
            self::assertStringContainsString('cannot continue', (string) $response->getContent(), $case);
        }
    }

    public function testOtherRequestErrorsGoBackToTheAssistant(): void
    {
        foreach ([
            'no PKCE' => [['code_challenge' => ''], 'invalid_request'],
            'plain PKCE' => [['code_challenge_method' => 'plain'], 'invalid_request'],
            'token flow' => [['response_type' => 'token'], 'unsupported_response_type'],
            'another resource' => [['resource' => 'https://elsewhere.example/mcp'], 'invalid_target'],
        ] as $case => [$change, $error]) {
            $location = $this->location($this->get([...$this->query(), ...$change]));
            self::assertSame('https://assistant.example/callback', $location['base'], $case);
            self::assertSame($error, $location['query']['error'], $case);
            self::assertSame('xyz', $location['query']['state'], $case);
            self::assertSame('1', $location['query']['keep'], 'The redirect keeps its own query');
            self::assertSame('http://localhost', $location['query']['iss'], $case);
        }
    }

    public function testDenyingSendsAccessDenied(): void
    {
        $location = $this->location($this->post([...$this->query(), 'decision' => 'deny']));

        self::assertSame('access_denied', $location['query']['error']);
        self::assertSame([], (new OAuthStore($this->projectDir))->connections());
    }

    public function testApprovingReturnsACodeTheAssistantCanExchange(): void
    {
        $location = $this->location($this->post([...$this->query(), 'decision' => 'approve', 'scope_choice' => 'selected', 'views' => ['bi_anonymous_daily_v1'], 'expires' => '30d']));

        self::assertSame('xyz', $location['query']['state']);
        self::assertSame('http://localhost', $location['query']['iss']);
        [$connection] = (new OAuthStore($this->projectDir))->exchangeCode($location['query']['code'], $this->client->id, self::REDIRECT, self::VERIFIER);
        self::assertSame(['bi_anonymous_daily_v1'], $connection->views);
        self::assertSame('admin', $connection->approvedBy);
        self::assertNotNull($connection->expiresAt);
    }

    public function testAnIncompleteApprovalStaysOnThePage(): void
    {
        foreach ([
            'no views' => [['scope_choice' => 'selected'], 'at least one view'],
            'no scope' => [[], 'Choose which approved views'],
            'private views while off' => [['scope_choice' => 'all', 'private_views' => ['analytics_custom_events_v1'], 'private_views_confirmed' => '1'], 'turned off'],
            'unconfirmed private views' => [['scope_choice' => 'all', 'private_views' => ['analytics_custom_events_v1']], 'Confirm'],
            'no date' => [['scope_choice' => 'all', 'expires' => 'date'], 'date the connection expires'],
        ] as $case => [$form, $message]) {
            $response = $this->post([...$this->query(), 'decision' => 'approve', ...$form]);
            self::assertSame(422, $response->getStatusCode(), $case);
            self::assertStringContainsString($message, (new Crawler((string) $response->getContent()))->filter('.notification.is-danger')->text(), $case);
        }
        self::assertSame([], (new OAuthStore($this->projectDir))->connections());
    }

    public function testAForgedDecisionIsRefusedOnThePage(): void
    {
        $response = $this->post([...$this->query(), 'decision' => 'approve', 'scope_choice' => 'all'], csrfValid: false);

        self::assertSame(400, $response->getStatusCode());
        self::assertNull($response->headers->get('Location'));
    }

    public function testThePageDoesNotExistWhileTheServerIsOff(): void
    {
        $this->configure(false);
        $this->expectException(NotFoundHttpException::class);
        $this->get($this->query());
    }

    /** @return array<string, string> */
    private function query(): array
    {
        return [
            'response_type' => 'code',
            'client_id' => $this->client->id,
            'redirect_uri' => self::REDIRECT,
            'code_challenge' => OAuthStore::challenge(self::VERIFIER),
            'code_challenge_method' => 'S256',
            'state' => 'xyz',
            'resource' => 'http://localhost/mcp',
        ];
    }

    /** @return array{base: string, query: array<string, string>} */
    private function location(Response $response): array
    {
        self::assertSame(302, $response->getStatusCode());
        $location = (string) $response->headers->get('Location');
        [$base, $query] = explode('?', $location, 2) + [1 => ''];
        parse_str($query, $parameters);

        return ['base' => $base, 'query' => $parameters];
    }

    /** @param array<string, mixed> $query */
    private function get(array $query): Response
    {
        $request = Request::create('/dashboard/oauth/authorize', 'GET', $query);

        return $this->controller($request)->authorize($request);
    }

    /** @param array<string, mixed> $form */
    private function post(array $form, bool $csrfValid = true): Response
    {
        $request = Request::create('/dashboard/oauth/authorize', 'POST', [...$form, '_csrf_token' => 'token']);

        return $this->controller($request, $csrfValid)->decide($request);
    }

    private function controller(Request $request, bool $csrfValid = true): McpAuthorizeController
    {
        $request->setSession(new Session(new MockArraySessionStorage()));
        $config = new AggregateConfigLoader($this->projectDir, 'test');
        $store = new OAuthStore($this->projectDir);
        $controller = new McpAuthorizeController(
            $config,
            new FeatureFlags($config),
            new OAuthServer($store, new ClientResolver($store, new MockHttpClient(), false), new AppBranding($config, $this->projectDir), new DocumentationLinks($config)),
            $store,
            new ApiTokenStore($this->projectDir, new FeatureFlags($config), new ReportingApiSettings($config)),
            new ReportingApiSettings($config),
            new NullLogger(),
        );

        $authorization = $this->createStub(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willReturn(true);
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturnCallback(static fn (CsrfToken $token): bool => $csrfValid && $token->getId() === McpAuthorizeController::CSRF_TOKEN_ID);
        $stack = new RequestStack();
        $stack->push($request);
        $tokens = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken(new InMemoryUser('admin', null, ['ROLE_ADMIN']), 'main', ['ROLE_ADMIN']));
        $twig = new Environment(new ChainLoader([
            new ArrayLoader(['base.html.twig' => '<title>{% block title %}{% endblock %}</title>{% block body %}{% endblock %}']),
            new FilesystemLoader(dirname(__DIR__, 2).'/templates'),
        ]), ['strict_variables' => true]);
        $twig->addGlobal('app_branding', ['name' => 'Example Analytics']);
        $twig->addFunction(new TwigFunction('path', static fn (string $route): string => match ($route) {
            'app_mcp_authorize_decide' => '/dashboard/oauth/authorize',
        }));
        $twig->addFunction(new TwigFunction('csrf_token', static fn (): string => 'rendered-token'));
        \App\Tests\Support\TwigComponents::register($twig);

        $container = new Container();
        $container->set('security.authorization_checker', $authorization);
        $container->set('security.token_storage', $tokens);
        $container->set('security.csrf.token_manager', $csrf);
        $container->set('router', $this->createStub(UrlGeneratorInterface::class));
        $container->set('request_stack', $stack);
        $container->set('twig', $twig);
        $controller->setContainer($container);

        return $controller;
    }

    private function configure(bool $flag = true): void
    {
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump([
            'feature_flags' => ['mcp_server' => ['enabled' => $flag, 'hide_from_navigation' => true]],
        ], 4));
    }
}
