<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\DataModelController;
use App\Service\AggregateConfigLoader;
use App\Service\CustomDataSettings;
use App\Service\InternalTrafficSettings;
use App\Service\ReportingViewManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
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

final class DataModelControllerTest extends TestCase
{
    private string $projectDir;
    private AggregateConfigLoader $config;
    private CustomDataSettings $settings;
    private ReportingViewManager $views;
    private array $environment;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        foreach ([...array_keys(InternalTrafficSettings::DEFAULTS), 'dashboard_enabled', 'anonymous_tracking_enabled', 'anonymous_excluded_paths'] as $key) {
            unset($_ENV[strtoupper($key)], $_SERVER[strtoupper($key)]);
        }
        $this->projectDir = sys_get_temp_dir().'/aggregate-data-model-controller-'.bin2hex(random_bytes(8));
        mkdir($this->projectDir.'/config', 0700, true);
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump([
            'admin_token' => 'private-admin-token',
            'environments' => [
                'test' => ['internal_traffic_share_token' => str_repeat('s', 64), 'app_host' => 'https://test.example'],
                'prod' => ['app_host' => 'https://prod.example', 'custom_data_properties' => ['production' => []]],
            ],
        ], 5, 2));
        $this->config = new AggregateConfigLoader($this->projectDir, 'test');
        $this->settings = new CustomDataSettings($this->config);
        $this->views = $this->createMock(ReportingViewManager::class);
    }

    protected function tearDown(): void
    {
        [$_ENV, $_SERVER] = $this->environment;
        unlink($this->projectDir.'/config/aggregate.yaml');
        rmdir($this->projectDir.'/config');
        rmdir($this->projectDir);
    }

    public function testAdminSavesTheCompleteModelInTheActiveEnvironmentWithoutRegeneratingViews(): void
    {
        $this->views->expects(self::never())->method('regenerate');
        $request = $this->request([
            'properties' => [
                ['key' => ' utm_medium ', 'description' => ' Marketing channel ', 'column' => ' marketing_channel ', 'consent_required' => '0'],
                ['key' => 'plan', 'description' => '', 'column' => '', 'consent_required' => '1'],
                ['key' => '', 'description' => '', 'column' => '', 'consent_required' => '1'],
            ],
            'mappings' => [
                ['parameter' => 'utm_medium', 'property' => 'utm_medium'],
                ['parameter' => ' channel ', 'property' => ' utm_medium '],
                ['parameter' => '', 'property' => ''],
            ],
        ]);

        $response = $this->controller($request)->save($request);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/dashboard/data-model', $response->headers->get('Location'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $written = Yaml::parseFile($this->projectDir.'/config/aggregate.yaml');
        self::assertSame('private-admin-token', $written['admin_token']);
        self::assertSame(['app_host' => 'https://prod.example', 'custom_data_properties' => ['production' => []]], $written['environments']['prod']);
        self::assertSame(str_repeat('s', 64), $written['environments']['test']['internal_traffic_share_token']);
        self::assertSame('https://test.example', $written['environments']['test']['app_host']);
        self::assertSame([
            'utm_medium' => ['description' => 'Marketing channel', 'consent_required' => false, 'column' => 'marketing_channel'],
            'plan' => ['description' => '', 'consent_required' => true, 'column' => ''],
        ], $written['environments']['test']['custom_data_properties']);
        self::assertSame(['utm_medium' => 'utm_medium', 'channel' => 'utm_medium'], $written['environments']['test']['query_parameter_mappings']);
        self::assertSame(['utm_medium' => 'email'], $this->settings->filterEventData(['utm_medium' => 'email', 'plan' => 'pro'], false));
        self::assertNotEmpty($request->getSession()->getFlashBag()->peek('success'));
    }

    #[DataProvider('invalidForms')]
    public function testMalformedOrConflictingFormsNeverPartiallySave(array $values): void
    {
        $this->views->expects(self::never())->method('regenerate');
        $before = file_get_contents($this->projectDir.'/config/aggregate.yaml');
        $request = $this->request($values);

        $response = $this->controller($request)->save($request);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame($before, file_get_contents($this->projectDir.'/config/aggregate.yaml'));
        self::assertNotEmpty($request->getSession()->getFlashBag()->peek('error'));
    }

    public static function invalidForms(): iterable
    {
        $row = ['key' => 'plan', 'description' => '', 'column' => 'plan', 'consent_required' => '1'];
        $base = ['properties' => [$row], 'mappings' => []];
        yield 'unexpected setting' => [$base + ['admin_token' => 'cannot-change']];
        yield 'duplicate property' => [array_replace($base, ['properties' => [$row, array_replace($row, ['key' => ' plan '])]])];
        yield 'duplicate alias' => [array_replace($base, ['properties' => [$row, array_replace($row, ['key' => 'tier'])]])];
        yield 'malformed property collection' => [array_replace($base, ['properties' => 'plan'])];
        yield 'null property collection' => [array_replace($base, ['properties' => null])];
        yield 'malformed property row' => [array_replace($base, ['properties' => ['plan']])];
        yield 'incomplete property row' => [array_replace($base, ['properties' => [['key' => 'plan']]])];
        yield 'array field' => [array_replace($base, ['properties' => [array_replace($row, ['key' => ['plan']])]])];
        yield 'invalid consent' => [array_replace($base, ['properties' => [array_replace($row, ['consent_required' => 'false'])]])];
        yield 'unsafe column' => [array_replace($base, ['properties' => [array_replace($row, ['column' => 'visitor_id'])]])];
        yield 'unknown property field' => [array_replace($base, ['properties' => [$row + ['unknown' => 'private']]])];
        yield 'malformed mapping collection' => [array_replace($base, ['mappings' => 'source'])];
        yield 'null mapping collection' => [array_replace($base, ['mappings' => null])];
        yield 'missing mapping destination' => [array_replace($base, ['mappings' => [['parameter' => 'source']]])];
        yield 'undefined mapping destination' => [array_replace($base, ['mappings' => [['parameter' => 'source', 'property' => 'missing']]])];
        yield 'duplicate parameter' => [array_replace($base, ['mappings' => [['parameter' => 'source', 'property' => 'plan'], ['parameter' => ' source ', 'property' => 'plan']]])];
        yield 'unexpected mapping field' => [array_replace($base, ['mappings' => [['parameter' => 'source', 'property' => 'plan', 'unknown' => 'private']]])];
    }

    #[DataProvider('invalidTokens')]
    public function testBothMutationsRequireAValidScalarCsrfToken(string $action, mixed $token): void
    {
        $this->views->expects(self::never())->method('regenerate');
        $before = file_get_contents($this->projectDir.'/config/aggregate.yaml');
        $request = $this->request(['_csrf_token' => $token]);

        $response = $this->invokeAction($this->controller($request), $action, $request);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame($before, file_get_contents($this->projectDir.'/config/aggregate.yaml'));
        self::assertSame(['Invalid security token. Please try again.'], $request->getSession()->getFlashBag()->peek('error'));
    }

    public static function invalidTokens(): iterable
    {
        foreach (['save', 'regenerate'] as $action) {
            foreach (['wrong-token', null, ['valid-token']] as $index => $token) {
                yield $action.' token '.$index => [$action, $token];
            }
        }
    }

    #[DataProvider('adminActions')]
    public function testEveryActionRequiresAdministratorAccess(string $action): void
    {
        $this->views->expects(self::never())->method('regenerate');
        $this->views->expects(self::never())->method('previewSql');
        $this->views->expects(self::never())->method('discoverProperties');
        $request = $this->request([]);

        $this->expectException(AccessDeniedException::class);
        $this->invokeAction($this->controller($request, admin: false), $action, $request);
    }

    #[DataProvider('adminActions')]
    public function testHeadlessModeDoesNotExposeAnyModelAction(string $action): void
    {
        $this->config->set('dashboard_enabled', false);
        $this->views->expects(self::never())->method('regenerate');
        $this->views->expects(self::never())->method('previewSql');
        $this->views->expects(self::never())->method('discoverProperties');
        $request = $this->request([]);

        $this->expectException(NotFoundHttpException::class);
        $this->invokeAction($this->controller($request), $action, $request);
    }

    public static function adminActions(): iterable
    {
        foreach (['index', 'save', 'regenerate', 'download'] as $action) {
            yield $action => [$action];
        }
    }

    public function testRegenerationUsesOnlyTheSavedModelAndIgnoresForgedFormDefinitions(): void
    {
        $this->settings->save(['custom_data_properties' => ['plan' => ['column' => 'saved_plan']], 'query_parameter_mappings' => []]);
        $before = file_get_contents($this->projectDir.'/config/aggregate.yaml');
        $this->views->expects(self::once())->method('regenerate')->with()->willReturnCallback(function (): array {
            self::assertSame(['saved_plan' => 'plan'], $this->settings->reportingColumns());

            return ReportingViewManager::VIEW_NAMES;
        });
        $request = $this->request(['properties' => [['key' => 'forged']]]);

        $response = $this->controller($request)->regenerate($request);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame($before, file_get_contents($this->projectDir.'/config/aggregate.yaml'));
        self::assertSame(['Regenerated '.implode(', ', ReportingViewManager::VIEW_NAMES).'.'], $request->getSession()->getFlashBag()->peek('success'));
    }

    public function testDownloadIsPrivateYamlContainingOnlyTheModel(): void
    {
        $this->views->expects(self::never())->method('regenerate');
        $request = $this->request([]);

        $response = $this->controller($request)->download();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/yaml', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('aggregate-data-model.yaml', (string) $response->headers->get('Content-Disposition'));
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame($this->settings->toArray(), Yaml::parse((string) $response->getContent()));
        self::assertStringNotContainsString('private-admin-token', (string) $response->getContent());
        self::assertStringNotContainsString(str_repeat('s', 64), (string) $response->getContent());
        self::assertStringNotContainsString('https://', (string) $response->getContent());
    }

    #[DataProvider('utmRecommendations')]
    public function testTemplateWarnsAboutDetailedAnonymousUtmPropertiesAndAliases(array $model, array $warnedProperties): void
    {
        $this->settings->save($model);
        $this->views->expects(self::once())->method('previewSql')->willReturn(['analytics_custom_events_v1' => 'CREATE VIEW analytics_custom_events_v1 AS SELECT 1;']);
        $this->views->expects(self::never())->method('discoverProperties');
        $this->views->expects(self::never())->method('regenerate');
        $request = $this->request([], 'GET');

        $response = $this->controller($request)->index($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $crawler = new Crawler((string) $response->getContent());
        self::assertCount(2, $crawler->filter('form input[name="_csrf_token"][value="valid-token"]'));
        self::assertCount(1, $crawler->filter('form[action="/dashboard/data-model/save"][method="post"]'));
        self::assertCount(1, $crawler->filter('form[action="/dashboard/data-model/regenerate"][method="post"]'));
        self::assertStringContainsString('utm_medium', $crawler->filter('.notification.is-info')->text());
        $warning = $crawler->filter('.notification.is-warning[role="alert"]');
        if ($warnedProperties === []) {
            self::assertCount(0, $warning);
        } else {
            self::assertCount(1, $warning);
            self::assertStringContainsString('Anonymous UTM recommendation overridden.', $warning->text());
            self::assertSame(implode(', ', $warnedProperties), $warning->filter('code')->text());
        }
    }

    public static function utmRecommendations(): iterable
    {
        yield 'default consent required' => [CustomDataSettings::defaults(), []];
        yield 'medium allowed' => [['custom_data_properties' => ['utm_medium' => ['consent_required' => false]], 'query_parameter_mappings' => ['utm_medium' => 'utm_medium']], []];
        yield 'detailed property allowed' => [['custom_data_properties' => ['utm_campaign' => ['consent_required' => false]], 'query_parameter_mappings' => []], ['utm_campaign']];
        yield 'detailed UTM through alias' => [['custom_data_properties' => ['campaign_origin' => ['consent_required' => false]], 'query_parameter_mappings' => ['utm_source' => 'campaign_origin']], ['campaign_origin']];
        yield 'detailed UTM mapped to medium' => [['custom_data_properties' => ['utm_medium' => ['consent_required' => false]], 'query_parameter_mappings' => ['utm_source' => 'utm_medium']], ['utm_medium']];
    }

    public function testDiscoveryIsExplicitAndTemplateEscapesUnsupportedPropertyNames(): void
    {
        $this->settings->save(['custom_data_properties' => [], 'query_parameter_mappings' => []]);
        $this->views->expects(self::once())->method('previewSql')->willReturn([]);
        $this->views->expects(self::once())->method('discoverProperties')->willReturn([
            ['key' => 'plan', 'types' => ['string'], 'event_count' => 9],
            ['key' => '<img src=x onerror=alert(1)>', 'types' => ['string'], 'event_count' => 1],
            ['key' => 'constructor', 'types' => ['string'], 'event_count' => 1],
        ]);
        $this->views->expects(self::never())->method('regenerate');
        $request = $this->request([], 'GET');
        $request->query->set('discover', '1');

        $response = $this->controller($request)->index($request);

        $crawler = new Crawler((string) $response->getContent());
        self::assertCount(1, $crawler->filter('button[data-add-observed="plan"]'));
        self::assertCount(1, $crawler->filter('[data-add-observed]'));
        self::assertCount(0, $crawler->filter('img'));
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', (string) $response->getContent());
    }

    public function testReportingFailuresRenderAnEditableModelAndDoNotExposeExceptionDetails(): void
    {
        $this->views->expects(self::once())->method('previewSql')->willThrowException(new \RuntimeException('private-database-password'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with('Custom data model operation failed.', ['operation' => 'preview', 'exception_class' => \RuntimeException::class]);
        $request = $this->request([], 'GET');

        $response = $this->controller($request, logger: $logger)->index($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringNotContainsString('private-database-password', (string) $response->getContent());
        $crawler = new Crawler((string) $response->getContent());
        self::assertStringContainsString('The model can still be edited.', $crawler->filter('.notification.is-warning')->text());
        self::assertCount(1, $crawler->filter('#data-model-form button[type="submit"]:not([disabled])'));
        self::assertCount(0, $crawler->filter('form[action="/dashboard/data-model/regenerate"]'));
    }

    private function request(array $values, string $method = 'POST'): Request
    {
        $request = Request::create('/dashboard/data-model', $method, ['_csrf_token' => 'valid-token', ...$values]);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    private function invokeAction(DataModelController $controller, string $action, Request $request): Response
    {
        return $action === 'download' ? $controller->download() : $controller->$action($request);
    }

    private function controller(Request $request, bool $admin = true, ?LoggerInterface $logger = null): DataModelController
    {
        $controller = new DataModelController($this->config, $this->settings, $this->views, $logger ?? new NullLogger());
        $authorization = $this->createStub(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willReturnCallback(static fn (string $role): bool => $admin && $role === 'ROLE_ADMIN');
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturnCallback(static fn (CsrfToken $token): bool =>
            $token->getId() === DataModelController::CSRF_TOKEN_ID && $token->getValue() === 'valid-token');
        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturn('/dashboard/data-model');
        $stack = new RequestStack();
        $stack->push($request);
        $container = new Container();
        $container->set('security.authorization_checker', $authorization);
        $container->set('security.csrf.token_manager', $csrf);
        $container->set('router', $router);
        $container->set('request_stack', $stack);
        $container->set('twig', $this->twig());
        $controller->setContainer($container);

        return $controller;
    }

    private function twig(): Environment
    {
        $twig = new Environment(new ChainLoader([
            new ArrayLoader(['base.html.twig' => '{% block body %}{% endblock %}{% block javascripts %}{% endblock %}']),
            new FilesystemLoader(dirname(__DIR__, 2).'/templates'),
        ]), ['strict_variables' => true]);
        $twig->addGlobal('app_branding', ['name' => 'Aggregate']);
        $twig->addFunction(new TwigFunction('path', static fn (string $name): string => match ($name) {
            'app_data_model' => '/dashboard/data-model',
            'app_data_model_save' => '/dashboard/data-model/save',
            'app_data_model_regenerate' => '/dashboard/data-model/regenerate',
            'app_data_model_download' => '/dashboard/data-model/download',
        }));
        $twig->addFunction(new TwigFunction('csrf_token', static fn (string $id): string => $id === DataModelController::CSRF_TOKEN_ID ? 'valid-token' : 'wrong-token'));

        return $twig;
    }
}
