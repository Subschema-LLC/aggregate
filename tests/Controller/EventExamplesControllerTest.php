<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\EventExamplesController;
use App\Service\AggregateConfigLoader;
use App\Service\CustomDataSettings;
use App\Service\EventExampleGenerator;
use App\Service\InternalTrafficSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Yaml\Yaml;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class EventExamplesControllerTest extends TestCase
{
    private string $projectDir;
    private array $environment;
    private AggregateConfigLoader $config;
    private CustomDataSettings $settings;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        foreach ([...array_keys(InternalTrafficSettings::DEFAULTS), 'dashboard_enabled', 'anonymous_tracking_enabled', 'anonymous_excluded_paths'] as $key) {
            unset($_ENV[strtoupper($key)], $_SERVER[strtoupper($key)]);
        }
        $this->projectDir = sys_get_temp_dir().'/aggregate-event-examples-controller-'.bin2hex(random_bytes(8));
        (new Filesystem())->mkdir($this->projectDir.'/config', 0700);
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump([
            'admin_token' => 'private-administrator-secret',
            'app_host' => 'https://private-installation.example',
            'internal_traffic_share_token' => str_repeat('s', 64),
            'custom_data_properties' => [
                'utm_medium' => ['consent_required' => false],
                'plan' => ['description' => 'private-description</script>', 'consent_required' => true],
            ],
            'query_parameter_mappings' => ['channel' => 'utm_medium'],
        ], 5));
        $this->config = new AggregateConfigLoader($this->projectDir, 'test');
        $this->settings = new CustomDataSettings($this->config);
    }

    protected function tearDown(): void
    {
        [$_ENV, $_SERVER] = $this->environment;
        (new Filesystem())->remove($this->projectDir);
    }

    public function testSavedExamplesRenderConsentAccurateCopyTargetsWithoutReadingOrSendingEvents(): void
    {
        $before = file_get_contents($this->projectDir.'/config/aggregate.yaml');
        $response = $this->controller()->index();
        $crawler = new Crawler((string) $response->getContent());
        $anonymous = json_decode($crawler->filter('#example-anonymous')->text(), true, flags: JSON_THROW_ON_ERROR);
        $enhanced = json_decode($crawler->filter('#example-enhanced')->text(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        $this->assertPrivate($response);
        self::assertSame($before, file_get_contents($this->projectDir.'/config/aggregate.yaml'));
        self::assertSame('denied', $anonymous['consentState']);
        self::assertSame(['utm_medium' => 'email'], $anonymous['eventData']);
        self::assertArrayNotHasKey('visitorId', $anonymous);
        self::assertArrayNotHasKey('sessionId', $anonymous);
        self::assertSame('granted', $enhanced['consentState']);
        self::assertArrayHasKey('plan', $enhanced['eventData']);
        self::assertSame('REPLACE_WITH_PUBLIC_WEBSITE_TOKEN', $enhanced['websiteToken']);
        foreach (['anonymous', 'enhanced'] as $mode) {
            self::assertCount(1, $crawler->filter('button[type="button"][data-copy-example="example-'.$mode.'"]'));
            self::assertCount(1, $crawler->filter('a[href="/dashboard/data-model/examples/download?mode='.$mode.'&example=model"]'));
        }
        self::assertCount(1, $crawler->filter('#example-copy-status[role="status"][aria-live="polite"]'));
        self::assertCount(0, $crawler->filter('form, script[src], iframe'));
        self::assertStringContainsString('copying the example does not obtain consent', $crawler->text());
        self::assertStringContainsString('Withdrawal does not erase stored history', $crawler->text());
        $this->assertNoSecrets((string) $response->getContent());
    }

    public function testEcommerceRecipeOffersCopyableRecommendedYamlWithoutSavingTheModel(): void
    {
        $before = file_get_contents($this->projectDir.'/config/aggregate.yaml');
        $response = $this->controller()->ecommerce();
        $crawler = new Crawler((string) $response->getContent());

        self::assertSame(200, $response->getStatusCode());
        $this->assertPrivate($response);
        self::assertSame($before, file_get_contents($this->projectDir.'/config/aggregate.yaml'));
        self::assertSame('Ecommerce examples', $crawler->filter('h1')->text());
        self::assertCount(1, $crawler->filter('button[data-copy-example="ecommerce-model-yaml"][data-copy-format="YAML"]'));
        $recommended = Yaml::parse($crawler->filter('#ecommerce-model-yaml')->text(null, false));
        $validated = $this->settings->validate($recommended);
        self::assertNotEmpty($validated['custom_data_properties']);
        self::assertSame(array_keys($recommended['custom_data_properties']), array_keys($validated['custom_data_properties']));
        self::assertCount(3, $crawler->filter('[data-copy-example]'));
        self::assertStringContainsString('does not change your saved model', $crawler->text());
        self::assertStringContainsString('Money is stored in integer minor units', $crawler->text());
        $this->assertNoSecrets((string) $response->getContent());
    }

    #[DataProvider('downloads')]
    public function testDownloadsUseTheSharedGeneratorAndOnlyAnAllowlistedFilename(string $mode, string $example): void
    {
        $request = Request::create('/dashboard/data-model/examples/download', 'GET', ['mode' => $mode, 'example' => $example]);
        $before = file_get_contents($this->projectDir.'/config/aggregate.yaml');
        $response = $this->controller()->download($request);
        $expected = (new EventExampleGenerator($this->settings))->exportJson($mode, $example);
        $filename = ($example === 'ecommerce' ? 'aggregate-ecommerce-examples' : 'aggregate-event-examples')
            .($mode === 'all' ? '' : '-'.$mode).'.json';

        self::assertSame(200, $response->getStatusCode());
        $this->assertPrivate($response);
        self::assertSame($expected, $response->getContent());
        self::assertSame($before, file_get_contents($this->projectDir.'/config/aggregate.yaml'));
        self::assertSame('application/json; charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertSame('attachment; filename="'.$filename.'"', $response->headers->get('Content-Disposition'));
        $bundle = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($mode === 'all' ? ['anonymous', 'enhanced'] : [$mode], array_keys($bundle['examples']));
        $this->assertNoSecrets((string) $response->getContent());
    }

    public static function downloads(): iterable
    {
        foreach (['model', 'ecommerce'] as $example) {
            foreach (['all', 'anonymous', 'enhanced'] as $mode) {
                yield $example.' '.$mode => [$mode, $example];
            }
        }
    }

    #[DataProvider('invalidDownloads')]
    public function testMalformedDownloadQueriesFailBeforeGeneratingExamples(array $query): void
    {
        $settings = $this->createMock(CustomDataSettings::class);
        $settings->expects(self::never())->method('toArray');
        $settings->expects(self::never())->method('validate');
        $response = $this->controller(settings: $settings)->download(Request::create('/examples/download', 'GET', $query));

        self::assertSame(400, $response->getStatusCode());
        $this->assertPrivate($response);
        self::assertFalse($response->headers->has('Content-Disposition'));
        self::assertArrayHasKey('error', json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('unsafe-input', (string) $response->getContent());
    }

    public static function invalidDownloads(): iterable
    {
        foreach (['mode', 'example'] as $parameter) {
            foreach (['', 'unsafe-input', '../unsafe-input', ['unsafe-input'], false, 1] as $index => $value) {
                yield $parameter.' '.$index => [[$parameter => $value]];
            }
        }
    }

    #[DataProvider('actions')]
    public function testEveryActionDeniesOrdinaryUsersBeforeReadingModelConfiguration(string $action): void
    {
        $settings = $this->createMock(CustomDataSettings::class);
        $settings->expects(self::never())->method('toArray');
        $settings->expects(self::never())->method('validate');
        $this->expectException(AccessDeniedException::class);
        $this->invoke($this->controller(admin: false, settings: $settings), $action);
    }

    #[DataProvider('actions')]
    public function testHeadlessModeDeniesEveryUiActionBeforeReadingModelConfiguration(string $action): void
    {
        $this->config->set('dashboard_enabled', false);
        $settings = $this->createMock(CustomDataSettings::class);
        $settings->expects(self::never())->method('toArray');
        $settings->expects(self::never())->method('validate');
        $this->expectException(NotFoundHttpException::class);
        $this->invoke($this->controller(settings: $settings), $action);
    }

    public static function actions(): iterable
    {
        foreach (['index', 'ecommerce', 'download'] as $action) {
            yield $action => [$action];
        }
    }

    #[DataProvider('failingActions')]
    public function testInvalidSavedModelsHavePrivateGenericErrorsAndNeverExposeExceptionDetails(string $action): void
    {
        $settings = $this->createMock(CustomDataSettings::class);
        $settings->method('toArray')->willThrowException(new \RuntimeException('private-database-password'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with('Event examples could not be generated.', [
            'exception_class' => \RuntimeException::class,
        ]);
        $response = $this->invoke($this->controller(settings: $settings, logger: $logger), $action);

        self::assertSame(503, $response->getStatusCode());
        $this->assertPrivate($response);
        self::assertFalse($response->headers->has('Content-Disposition'));
        self::assertStringNotContainsString('private-database-password', (string) $response->getContent());
        self::assertStringContainsString('Saved event examples are unavailable', (string) $response->getContent());
        self::assertCount(0, (new Crawler((string) $response->getContent()))->filter('[data-copy-example], #example-anonymous, #example-enhanced'));
    }

    public static function failingActions(): iterable
    {
        yield 'index' => ['index'];
        yield 'download' => ['download'];
    }

    private function invoke(EventExamplesController $controller, string $action): Response
    {
        return $action === 'download' ? $controller->download(Request::create('/examples/download')) : $controller->$action();
    }

    private function assertPrivate(Response $response): void
    {
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    private function assertNoSecrets(string $content): void
    {
        foreach (['private-administrator-secret', 'https://private-installation.example', str_repeat('s', 64), 'private-description'] as $secret) {
            self::assertStringNotContainsString($secret, $content);
        }
    }

    private function controller(bool $admin = true, ?CustomDataSettings $settings = null, ?LoggerInterface $logger = null): EventExamplesController
    {
        $controller = new EventExamplesController($this->config, new EventExampleGenerator($settings ?? $this->settings), $logger ?? new NullLogger());
        $authorization = $this->createStub(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willReturnCallback(static fn (string $role): bool => $admin && $role === 'ROLE_ADMIN');
        $container = new Container();
        $container->set('security.authorization_checker', $authorization);
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
        $twig->addGlobal('app_branding', ['name' => 'Example Analytics']);
        $twig->addFunction(new TwigFunction('path', static function (string $name, array $parameters = []): string {
            $path = match ($name) {
                'app_data_model' => '/dashboard/data-model',
                'app_data_model_discovery' => '/dashboard/data-model/discovery',
                'app_data_model_reporting' => '/dashboard/data-model/reporting',
                'app_event_examples' => '/dashboard/data-model/examples',
                'app_event_examples_ecommerce' => '/dashboard/data-model/examples/ecommerce',
                'app_event_examples_download' => '/dashboard/data-model/examples/download',
            };

            return $path.($parameters === [] ? '' : '?'.http_build_query($parameters));
        }));

        return $twig;
    }
}
