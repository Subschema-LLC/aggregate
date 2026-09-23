<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\FeatureFlagsController;
use App\Service\AggregateConfigLoader;
use App\Service\FeatureFlags;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
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

final class FeatureFlagsControllerTest extends TestCase
{
    private string $projectDir;
    private AggregateConfigLoader $config;
    private FeatureFlags $flags;
    private array $environment;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        foreach (['DASHBOARD_ENABLED', 'ANONYMOUS_TRACKING_ENABLED', 'ANONYMOUS_EXCLUDED_PATHS'] as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
        }
        $this->projectDir = sys_get_temp_dir().'/aggregate-feature-flags-controller-'.bin2hex(random_bytes(8));
        mkdir($this->projectDir.'/config', 0700, true);
        file_put_contents($this->configPath(), Yaml::dump([
            'admin_token' => 'private-admin-token',
            'environments' => [
                'test' => ['app_host' => 'https://test.example', 'updates_branch' => 'uat'],
                'prod' => ['app_host' => 'https://prod.example', 'feature_flags' => ['updates' => ['enabled' => false]]],
            ],
        ], 6, 2));
        $this->config = new AggregateConfigLoader($this->projectDir, 'test');
        $this->flags = new FeatureFlags($this->config);
    }

    protected function tearDown(): void
    {
        [$_ENV, $_SERVER] = $this->environment;
        unlink($this->configPath());
        rmdir($this->projectDir.'/config');
        rmdir($this->projectDir);
    }

    public function testAdminSeesSavedSettingsInAccessibleFormWithIndependentNavigationControls(): void
    {
        $this->flags->save(['updates' => ['enabled' => false, 'hide_from_navigation' => true]]);
        $request = $this->request('GET');

        $response = $this->controller($request)->index();

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $crawler = new Crawler((string) $response->getContent());
        self::assertSame('Feature flags - Example Analytics', $crawler->filter('title')->text());
        self::assertCount(1, $crawler->filter('form[action="/dashboard/feature-flags/save"][method="post"]'));
        self::assertCount(1, $crawler->filter('input[name="_csrf_token"][value="rendered-token"]'));
        self::assertCount(count($this->flags->definitions()), $crawler->filter('fieldset legend'));
        foreach ($this->flags->definitions() as $name => $definition) {
            self::assertStringContainsString($definition['description'], $crawler->filter('#feature-'.$name.'-description')->text());
            foreach (['enabled', 'navigation'] as $field) {
                self::assertCount(1, $crawler->filter('label[for="feature-'.$name.'-'.$field.'"]'));
                self::assertCount(1, $crawler->filter('select#feature-'.$name.'-'.$field.'[required]'));
            }
        }
        self::assertSame('0', $crawler->filter('select[name="feature_flags[updates][enabled]"] option[selected]')->attr('value'));
        self::assertSame('1', $crawler->filter('select[name="feature_flags[updates][hide_from_navigation]"] option[selected]')->attr('value'));
        self::assertStringContainsString('independently of whether it is enabled', $crawler->text());
        self::assertStringContainsString('can still be accessed directly by authorized users', $crawler->text());
        self::assertStringNotContainsString('private-admin-token', $crawler->text());
    }

    public function testAdminSavesStrictBooleansWithoutReplacingOtherConfigurationOrEnvironments(): void
    {
        $before = Yaml::parseFile($this->configPath());
        $request = $this->request('POST', $this->validForm());

        $response = $this->controller($request)->save($request);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/dashboard/feature-flags', $response->headers->get('Location'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $after = Yaml::parseFile($this->configPath());
        $expected = $before;
        foreach ($this->validForm()['feature_flags'] as $name => $settings) {
            $expected['environments']['test']['feature_flags'][$name] = [
                'enabled' => $settings['enabled'] === '1',
                'hide_from_navigation' => $settings['hide_from_navigation'] === '1',
            ];
        }
        self::assertSame($expected, $after);
        $reloaded = new FeatureFlags(new AggregateConfigLoader($this->projectDir, 'test'));
        self::assertFalse($reloaded->isEnabled('updates'));
        self::assertTrue($reloaded->isHiddenFromNavigation('updates'));
        self::assertCount(1, $request->getSession()->getFlashBag()->peek('success'));
    }

    #[DataProvider('invalidTokens')]
    public function testMissingInvalidOrMalformedCsrfDoesNotWrite(mixed $token): void
    {
        $before = file_get_contents($this->configPath());
        $request = $this->request('POST', array_replace($this->validForm(), ['_csrf_token' => $token]));

        $response = $this->controller($request, csrfValid: false)->save($request);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame($before, file_get_contents($this->configPath()));
        self::assertSame(['Invalid security token. Please try again.'], $request->getSession()->getFlashBag()->peek('error'));
    }

    public static function invalidTokens(): iterable
    {
        yield 'missing' => [null];
        yield 'invalid' => ['wrong-token'];
        yield 'array' => [['valid-token']];
        yield 'integer' => [123];
    }

    #[DataProvider('invalidForms')]
    public function testInvalidFormDoesNotWrite(array $form): void
    {
        $before = file_get_contents($this->configPath());
        $request = $this->request('POST', ['_csrf_token' => 'valid-token'] + $form);

        $response = $this->controller($request)->save($request);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame($before, file_get_contents($this->configPath()));
        self::assertCount(1, $request->getSession()->getFlashBag()->peek('error'));
        self::assertSame([], $request->getSession()->getFlashBag()->peek('success'));
    }

    public static function invalidForms(): iterable
    {
        $valid = ['updates' => ['enabled' => '0', 'hide_from_navigation' => '1']];
        yield 'missing flags' => [[]];
        yield 'missing registered flag' => [['feature_flags' => []]];
        yield 'unknown flag' => [['feature_flags' => $valid + ['unregistered' => ['enabled' => '1', 'hide_from_navigation' => '0']]]];
        yield 'scalar flags' => [['feature_flags' => 'updates']];
        yield 'numeric flag key' => [['feature_flags' => [['enabled' => '1', 'hide_from_navigation' => '0']]]];
        yield 'unrelated configuration' => [['feature_flags' => $valid, 'updates_branch' => 'forged']];
        yield 'extra flag setting' => [['feature_flags' => ['updates' => $valid['updates'] + ['role' => 'ROLE_ADMIN']]]];
        yield 'scalar row' => [['feature_flags' => ['updates' => '1']]];
        yield 'missing enabled' => [['feature_flags' => ['updates' => ['hide_from_navigation' => '1']]]];
        yield 'missing navigation setting' => [['feature_flags' => ['updates' => ['enabled' => '1']]]];
        yield 'nested enabled' => [['feature_flags' => ['updates' => ['enabled' => ['1'], 'hide_from_navigation' => '1']]]];
        yield 'nested navigation setting' => [['feature_flags' => ['updates' => ['enabled' => '1', 'hide_from_navigation' => ['1']]]]];
        yield 'truthy string' => [['feature_flags' => ['updates' => ['enabled' => 'false', 'hide_from_navigation' => '1']]]];
        yield 'invalid navigation string' => [['feature_flags' => ['updates' => ['enabled' => '1', 'hide_from_navigation' => 'true']]]];
        yield 'integer enabled' => [['feature_flags' => ['updates' => ['enabled' => 1, 'hide_from_navigation' => '1']]]];
        yield 'boolean enabled' => [['feature_flags' => ['updates' => ['enabled' => false, 'hide_from_navigation' => '1']]]];
        yield 'null navigation' => [['feature_flags' => ['updates' => ['enabled' => '1', 'hide_from_navigation' => null]]]];
    }

    #[DataProvider('actions')]
    public function testNonAdministratorsCannotReadOrSaveFlags(string $action): void
    {
        $request = $this->request($action === 'save' ? 'POST' : 'GET', $this->validForm());
        $controller = $this->controller($request, admin: false);
        $before = file_get_contents($this->configPath());
        $this->expectException(AccessDeniedException::class);

        try {
            $action === 'save' ? $controller->save($request) : $controller->index();
        } finally {
            self::assertSame($before, file_get_contents($this->configPath()));
        }
    }

    #[DataProvider('actions')]
    public function testHeadlessModeDeniesReadAndSave(string $action): void
    {
        $this->config->set('dashboard_enabled', false);
        $request = $this->request($action === 'save' ? 'POST' : 'GET', $this->validForm());
        $controller = $this->controller($request);
        $before = file_get_contents($this->configPath());
        $this->expectException(NotFoundHttpException::class);

        try {
            $action === 'save' ? $controller->save($request) : $controller->index();
        } finally {
            self::assertSame($before, file_get_contents($this->configPath()));
        }
    }

    public static function actions(): iterable
    {
        yield 'read' => ['index'];
        yield 'save' => ['save'];
    }

    #[DataProvider('invalidConfigurations')]
    public function testInvalidConfigurationShowsAnErrorWithoutASaveFormOrSensitiveParserDetails(string $yaml): void
    {
        file_put_contents($this->configPath(), $yaml);
        $request = $this->request('GET');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            'Feature flag configuration operation failed.',
            self::callback(static fn (array $context): bool => $context['operation'] === 'load'
                && is_string($context['exception_class']) && count($context) === 2),
        );

        $response = $this->controller($request, logger: $logger)->index();

        self::assertSame(200, $response->getStatusCode());
        $crawler = new Crawler((string) $response->getContent());
        self::assertCount(1, $crawler->filter('.notification.is-danger[role="alert"]'));
        self::assertCount(0, $crawler->filter('form'));
        self::assertStringContainsString('Correct the active YAML configuration', $crawler->text());
        self::assertStringNotContainsString('private-admin-token', $crawler->text());
        self::assertSame($yaml, file_get_contents($this->configPath()));
    }

    #[DataProvider('invalidConfigurations')]
    public function testSaveCannotReplaceInvalidYamlWithDefaults(string $yaml): void
    {
        file_put_contents($this->configPath(), $yaml);
        $request = $this->request('POST', $this->validForm());

        $response = $this->controller($request)->save($request);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame($yaml, file_get_contents($this->configPath()));
        self::assertCount(1, $request->getSession()->getFlashBag()->peek('error'));
        self::assertSame([], $request->getSession()->getFlashBag()->peek('success'));
    }

    public static function invalidConfigurations(): iterable
    {
        yield 'malformed YAML' => ['admin_token: [private-admin-token'];
        yield 'string boolean' => ["admin_token: private-admin-token\nfeature_flags:\n  updates:\n    enabled: 'false'\n"];
    }

    private function controller(Request $request, bool $admin = true, bool $csrfValid = true, ?LoggerInterface $logger = null): FeatureFlagsController
    {
        $controller = new FeatureFlagsController($this->config, $this->flags, $logger ?? new NullLogger());
        $dashboardEnabled = $this->config->isDashboardEnabled();
        $authorization = $this->createMock(AuthorizationCheckerInterface::class);
        $authorization->expects($dashboardEnabled ? self::once() : self::never())
            ->method('isGranted')->with('ROLE_ADMIN')->willReturn($admin);

        $stack = new RequestStack();
        $stack->push($request);
        $submitted = $request->request->all();
        $expectCsrfCheck = $request->isMethod('POST') && $admin && $dashboardEnabled
            && is_string($submitted['_csrf_token'] ?? null);
        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->expects($expectCsrfCheck ? self::once() : self::never())
            ->method('isTokenValid')
            ->with(self::callback(static fn (CsrfToken $token): bool =>
                $token->getId() === FeatureFlagsController::CSRF_TOKEN_ID
                && $token->getValue() === $submitted['_csrf_token']))
            ->willReturn($csrfValid);
        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturn('/dashboard/feature-flags');
        $twig = new Environment(new ChainLoader([
            new ArrayLoader(['base.html.twig' => '<title>{% block title %}{% endblock %}</title>{% block body %}{% endblock %}']),
            new FilesystemLoader(dirname(__DIR__, 2).'/templates'),
        ]), ['strict_variables' => true]);
        $twig->addGlobal('app_branding', ['name' => 'Example Analytics']);
        $twig->addFunction(new TwigFunction('path', static fn (string $route): string => match ($route) {
            'app_feature_flags_save' => '/dashboard/feature-flags/save',
        }));
        $twig->addFunction(new TwigFunction('csrf_token', static fn (): string => 'rendered-token'));
        $container = new Container();
        $container->set('security.authorization_checker', $authorization);
        $container->set('security.csrf.token_manager', $csrf);
        $container->set('router', $router);
        $container->set('request_stack', $stack);
        \App\Tests\Support\TwigComponents::register($twig);
        $container->set('twig', $twig);
        $controller->setContainer($container);

        return $controller;
    }

    private function validForm(): array
    {
        $settings = [];
        foreach ($this->flags->definitions() as $name => $definition) {
            $settings[$name] = ['enabled' => '0', 'hide_from_navigation' => '1'];
        }

        return ['_csrf_token' => 'valid-token', 'feature_flags' => $settings];
    }

    private function request(string $method, array $submitted = []): Request
    {
        $path = $method === 'POST' ? '/dashboard/feature-flags/save' : '/dashboard/feature-flags';
        $request = Request::create($path, $method, $submitted);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    private function configPath(): string
    {
        return $this->projectDir.'/config/aggregate.yaml';
    }
}
