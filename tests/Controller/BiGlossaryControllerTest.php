<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\BiGlossaryController;
use App\Service\AggregateConfigLoader;
use App\Service\CustomDataSettings;
use App\Service\Glossary\BiGlossarySettings;
use App\Service\Glossary\BuiltinGlossaryCatalog;
use App\Service\Glossary\EventNameSuggestions;
use App\Service\Glossary\GlossaryResolver;
use App\Service\Glossary\GlossarySync;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
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
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Translation\Loader\ArrayLoader as TranslationArrayLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Yaml\Yaml;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class BiGlossaryControllerTest extends TestCase
{
    private string $directory;
    private AggregateConfigLoader $config;
    private BiGlossarySettings $settings;
    private GlossaryResolver $resolver;
    private GlossarySync $sync;
    private EventNameSuggestions $suggestions;
    private array $environment;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        unset($_ENV['DASHBOARD_ENABLED'], $_SERVER['DASHBOARD_ENABLED']);
        $this->directory = sys_get_temp_dir().'/aggregate-glossary-ui-'.bin2hex(random_bytes(6));
        mkdir($this->directory.'/config', 0700, true);
        file_put_contents($this->directory.'/config/aggregate.yaml', Yaml::dump([
            'app_host' => 'https://analytics.example.test', 'operator_setting' => 'preserve',
            'custom_data_properties' => ['utm_campaign' => ['column' => 'utm_campaign', 'description' => 'Declared campaign description.', 'consent_required' => true]],
            'query_parameter_mappings' => [],
            'bi_glossary' => ['locales' => ['en', 'es']],
        ], 8));
        file_put_contents($this->directory.'/config/goals.yaml', "# Must remain unchanged\nparameters:\n  app.goal_events:\n    contact: { label: 'Contact request', enabled: true, anonymous: true }\n");
        $this->config = new AggregateConfigLoader($this->directory, 'test');
        $translator = new Translator('en');
        $translator->addLoader('array', new TranslationArrayLoader());
        $translator->addResource('array', ['value.device_class.tablet.label' => 'Tablet'], 'en', 'bi_glossary');
        $catalog = new BuiltinGlossaryCatalog($translator);
        $this->settings = new BiGlossarySettings($this->config, new CustomDataSettings($this->config), $catalog, ['contact' => ['label' => 'Contact request', 'enabled' => true, 'anonymous' => true]]);
        $this->resolver = new GlossaryResolver($this->settings, $catalog);
        $this->sync = $this->createMock(GlossarySync::class);
        $this->sync->method('diff')->willReturn(['changed' => false, 'insert' => 0, 'change' => 0, 'delete' => 0, 'total' => 0]);
        $this->suggestions = $this->createMock(EventNameSuggestions::class);
    }

    protected function tearDown(): void
    {
        [$_ENV, $_SERVER] = $this->environment;
        (new Filesystem())->remove($this->directory);
    }

    public function testPageRendersFallbacksConsentWarningAndReadOnlyPropertyDescriptionWithoutSamplingEvents(): void
    {
        $this->suggestions->expects(self::never())->method('find');
        $this->sync->expects(self::never())->method('sync');
        $request = $this->request(['locale' => 'es'], 'GET');
        $response = $this->controller($request)->index($request);
        $crawler = new Crawler((string) $response->getContent());
        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertStringContainsString('In sync', $crawler->text());
        self::assertStringContainsString('Publishing its codes here makes them visible to every routine BI user', $crawler->text());
        self::assertCount(1, $crawler->filter('input[placeholder="Tablet (falls back to en)"]'));
        self::assertGreaterThan(0, $crawler->filter('form[data-controller="components--ui--validation"]')->count());
        $ids = $crawler->filter('[id]')->each(static fn (Crawler $node): string => (string) $node->attr('id'));
        self::assertSame(count($ids), count(array_unique($ids)), 'Every input and label needs a unique identifier.');
        foreach ($crawler->filter('input:not([type="hidden"]), select, textarea') as $control) {
            self::assertCount(1, $crawler->filter('label[for="'.$control->getAttribute('id').'"]'));
        }
        $request = $this->request([], 'GET');
        $crawler = new Crawler((string) $this->controller($request)->index($request)->getContent());
        foreach ($crawler->filter('input[name="code"][value="utm_campaign"]') as $input) {
            $form = (new Crawler($input))->ancestors()->filter('form')->first();
            self::assertCount(0, $form->filter('textarea[name="description"]'));
            self::assertStringContainsString('Declared campaign description.', $form->text());
        }
    }

    public function testSaveRoundTripsOnlyGlossaryAndPreservesGoalsAndOtherTranslations(): void
    {
        $this->settings->save(['locales' => ['en', 'es'], 'values' => ['device_class' => ['tablet' => ['label' => ['en' => 'My tablet']]]]]);
        $before = Yaml::parseFile($this->directory.'/config/aggregate.yaml');
        $goals = file_get_contents($this->directory.'/config/goals.yaml');
        $this->sync->expects(self::once())->method('sync')->willReturn([]);
        $request = $this->request($this->entry(['locale' => 'es', 'label' => 'Tableta']));
        $response = $this->controller($request)->save($request);
        self::assertSame(302, $response->getStatusCode());
        $after = Yaml::parseFile($this->directory.'/config/aggregate.yaml');
        $before['bi_glossary'] = $after['bi_glossary'];
        self::assertSame($before, $after);
        self::assertSame(['en' => 'My tablet', 'es' => 'Tableta'], $this->settings->get()['values']['device_class']['tablet']['label']);
        self::assertSame($goals, file_get_contents($this->directory.'/config/goals.yaml'));
    }

    public function testLocaleFormUsesSharedNormalization(): void
    {
        $this->sync->expects(self::once())->method('sync')->willReturn([]);
        $request = $this->request(['action' => 'locales', 'locales' => 'en, es, FR-ca', 'default_locale' => 'EN']);
        $this->controller($request)->save($request);
        self::assertSame(['en', 'es', 'fr-CA'], $this->settings->get()['locales']);
        self::assertSame('en', $this->settings->get()['default_locale']);
    }

    public function testLocaleValidationKeepsSubmittedFieldsAndRendersInlineMessages(): void
    {
        $this->sync->expects(self::never())->method('sync');
        $request = $this->request(['action' => 'locales', 'locales' => 'en, es_MX', 'default_locale' => 'es_MX']);
        $response = $this->controller($request)->save($request);
        self::assertSame(422, $response->getStatusCode());
        $crawler = new Crawler((string) $response->getContent());
        self::assertSame('en, es_MX', $crawler->filter('#glossary-locales')->attr('value'));
        self::assertSame('es_MX', $crawler->filter('#glossary-default-locale')->attr('value'));
        self::assertGreaterThan(0, $crawler->filter('[aria-labelledby="glossary-locales-title"] .field .help.is-danger')->count());
        self::assertSame(['en', 'es'], $this->settings->get()['locales']);
    }

    public function testPublishedOperatorTextIsEscapedInLabelsAndFormValues(): void
    {
        $label = '<img src=x onerror=alert(1)>';
        $this->settings->save(['values' => ['device_class' => ['tablet' => ['label' => $label]]]]);
        $request = $this->request([], 'GET');
        $response = $this->controller($request)->index($request);
        $crawler = new Crawler((string) $response->getContent());
        self::assertCount(0, $crawler->filter('img, script'));
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', (string) $response->getContent());
        self::assertStringContainsString($label, $crawler->text());
    }

    public function testResetRemovesOverrideAndDefaultCustomDescriptionCannotBeChangedByForgedInput(): void
    {
        $this->settings->save(['values' => ['device_class' => ['tablet' => ['label' => 'Override']]]]);
        $this->sync->expects(self::exactly(2))->method('sync')->willReturn([]);
        $request = $this->request($this->entry(['action' => 'reset']));
        $this->controller($request)->save($request);
        self::assertArrayNotHasKey('device_class', $this->settings->get()['values']);
        $request = $this->request($this->entry(['entry_type' => 'column', 'subject' => 'analytics_custom_events_v1', 'code' => 'utm_campaign', 'description' => 'Forged', 'label' => 'Campaign']));
        $this->controller($request)->save($request);
        self::assertArrayNotHasKey('description', $this->settings->get()['columns']['analytics_custom_events_v1']['utm_campaign']);
    }

    public function testInvalidEntryReturnsInlineFieldErrorsAndNoWriteOrSync(): void
    {
        $before = file_get_contents($this->directory.'/config/aggregate.yaml');
        $this->sync->expects(self::never())->method('sync');
        $request = $this->request($this->entry(['label' => str_repeat('a', 192)]));
        $response = $this->controller($request)->save($request);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame($before, file_get_contents($this->directory.'/config/aggregate.yaml'));
        self::assertStringContainsString('bi_glossary.values.device_class.tablet.label.en', (string) $response->getContent());
        self::assertGreaterThan(0, (new Crawler((string) $response->getContent()))->filter('details[open] .help.is-danger')->count());
    }

    public function testSaveSurvivesSyncFailureWithSpecificRecoveryWarning(): void
    {
        $this->sync->method('sync')->willThrowException(new \RuntimeException('private connection password'));
        $request = $this->request($this->entry(['label' => 'A saved label']));
        $this->controller($request)->save($request);
        self::assertSame('A saved label', $this->settings->get()['values']['device_class']['tablet']['label']['en']);
        $warning = implode(' ', $request->getSession()->getFlashBag()->peek('warning'));
        self::assertStringContainsString('Configuration saved, but the database was not updated', $warning);
        self::assertStringContainsString('app:analytics:glossary:sync', $warning);
        self::assertStringNotContainsString('private connection password', $warning);
    }

    public function testPageRemainsEditableBeforeGlossaryMigration(): void
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is unavailable.');
        }
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->sync = new GlossarySync($connection, $this->resolver);
        $request = $this->request([], 'GET');
        $response = $this->controller($request)->index($request);
        $crawler = new Crawler((string) $response->getContent());
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Sync status is unavailable', $crawler->text());
        self::assertGreaterThan(0, $crawler->filter('form[action="/dashboard/data-model/glossary/save"]')->count());
        self::assertStringNotContainsString('no such table', $crawler->text());
        $connection->close();
    }

    public function testSyncNowWithInvalidConfigurationShowsFieldErrorsBeforeDatabaseAccess(): void
    {
        $this->config->set('bi_glossary', ['values' => ['device_class' => ['tablett' => []]]]);
        $before = file_get_contents($this->directory.'/config/aggregate.yaml');
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('transactional');
        $connection->expects(self::never())->method('fetchAllAssociative');
        $this->sync = new GlossarySync($connection, $this->resolver);
        $request = $this->request([]);
        $response = $this->controller($request)->synchronize($request);
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('bi_glossary.values.device_class.tablett', (string) $response->getContent());
        self::assertSame($before, file_get_contents($this->directory.'/config/aggregate.yaml'));
        self::assertCount(0, (new Crawler((string) $response->getContent()))->filter('form[action="/dashboard/data-model/glossary/save"]'));
    }

    public function testDirectAdminSaveDeclaresOnlyValidatedEventAndPropertyCodes(): void
    {
        $this->suggestions->expects(self::never())->method('find');
        $this->sync->expects(self::exactly(2))->method('sync')->willReturn([]);
        foreach ([['event_name', 'newsletter_signup', 'Newsletter sign-up'], ['utm_campaign', 'spring_2027', 'Spring campaign']] as [$dimension, $code, $label]) {
            $request = $this->request($this->entry(['subject' => $dimension, 'code' => $code, 'label' => $label]));
            self::assertSame(302, $this->controller($request)->save($request)->getStatusCode());
            self::assertSame($label, $this->settings->get()['values'][$dimension][$code]['label']['en']);
        }
        $request = $this->request([], 'GET');
        $crawler = new Crawler((string) $this->controller($request)->index($request)->getContent());
        self::assertCount(1, $crawler->filter('input[name="code"][value="newsletter_signup"]'));
        self::assertCount(1, $crawler->filter('input[name="code"][value="spring_2027"]'));
        self::assertStringContainsString('Publishing its codes here makes them visible to every routine BI user', $crawler->text());
        self::assertStringContainsString('Spring campaign', $crawler->text());
    }

    public function testExplicitSuggestionsAndAddOpenUnsavedRowsWithoutWritingConfiguration(): void
    {
        $before = file_get_contents($this->directory.'/config/aggregate.yaml');
        $this->sync->expects(self::never())->method('sync');
        $this->suggestions->expects(self::once())->method('find')->with(['view'])->willReturn(['newsletter_signup']);
        $request = $this->request([]);
        $response = $this->controller($request)->discover($request);
        self::assertStringContainsString('newsletter_signup', (string) $response->getContent());
        self::assertStringNotContainsString('event_count', (new Crawler((string) $response->getContent()))->filter('[aria-labelledby="glossary-suggestions-title"]')->text());
        $request = $this->request(['add_dimension' => 'event_name', 'add_code' => 'newsletter_signup'], 'GET');
        $response = $this->controller($request)->index($request);
        self::assertStringContainsString('This code is not yet published. Save to apply the change.', (string) $response->getContent());
        self::assertCount(1, (new Crawler((string) $response->getContent()))->filter('input[name="code"][value="newsletter_signup"]'));
        self::assertSame($before, file_get_contents($this->directory.'/config/aggregate.yaml'));
    }

    public function testDownloadsContainOnlyGlossaryMetadataAndEscapeCsvFormulas(): void
    {
        $this->settings->save(['locales' => ['en', 'es'], 'values' => ['event_name' => ['newsletter_signup' => ['label' => '=FORMULA()']]]]);
        $request = $this->request([], 'GET');
        $controller = $this->controller($request);
        $download = $controller->download();
        self::assertSame(['bi_glossary'], array_keys(Yaml::parse((string) $download->getContent())));
        self::assertTrue($download->headers->hasCacheControlDirective('no-store'));
        $missing = $controller->missing();
        self::assertStringContainsString("'=FORMULA()", (string) $missing->getContent());
        self::assertStringNotContainsString('operator_setting', (string) $missing->getContent());
    }

    #[DataProvider('postActions')]
    public function testEveryPostRequiresCsrf(string $action): void
    {
        $before = file_get_contents($this->directory.'/config/aggregate.yaml');
        $this->sync->expects(self::never())->method('sync');
        $this->suggestions->expects(self::never())->method('find');
        foreach ([null, ['valid-token'], 'forged'] as $token) {
            $request = $this->request(['_csrf_token' => $token]);
            self::assertSame(302, $this->controller($request)->$action($request)->getStatusCode());
        }
        self::assertSame($before, file_get_contents($this->directory.'/config/aggregate.yaml'));
    }

    public static function postActions(): iterable
    {
        foreach (['save', 'synchronize', 'discover'] as $action) {
            yield [$action];
        }
    }

    #[DataProvider('allActions')]
    public function testAllActionsRequireAdmin(string $action): void
    {
        $this->expectException(AccessDeniedException::class);
        $request = $this->request([]);
        $this->invoke($this->controller($request, false), $action, $request);
    }

    #[DataProvider('allActions')]
    public function testAllActionsAreNotFoundWhenHeadless(string $action): void
    {
        $this->config->set('dashboard_enabled', false);
        $this->expectException(NotFoundHttpException::class);
        $request = $this->request([]);
        $this->invoke($this->controller($request), $action, $request);
    }

    public static function allActions(): iterable
    {
        foreach (['index', 'save', 'synchronize', 'discover', 'download', 'missing'] as $action) {
            yield [$action];
        }
    }

    private function entry(array $changes = []): array
    {
        return array_replace(['action' => 'entry', 'entry_type' => 'value', 'subject' => 'device_class', 'code' => 'tablet', 'locale' => 'en', 'label' => '', 'group' => '', 'description' => '', 'sort' => ''], $changes);
    }

    private function request(array $parameters, string $method = 'POST'): Request
    {
        $request = Request::create('/dashboard/data-model/glossary', $method, ['_csrf_token' => 'valid-token', ...$parameters]);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    private function invoke(BiGlossaryController $controller, string $action, Request $request): Response
    {
        return in_array($action, ['download', 'missing'], true) ? $controller->$action() : $controller->$action($request);
    }

    private function controller(Request $request, bool $admin = true): BiGlossaryController
    {
        $controller = new BiGlossaryController($this->config, $this->settings, $this->resolver, $this->sync, $this->suggestions, new NullLogger());
        $authorization = $this->createStub(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willReturn($admin);
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturnCallback(static fn (CsrfToken $token): bool => $token->getId() === BiGlossaryController::CSRF_TOKEN_ID && $token->getValue() === 'valid-token');
        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(self::url(...));
        $stack = new RequestStack();
        $stack->push($request);
        $container = new Container();
        $container->set('security.authorization_checker', $authorization);
        $container->set('security.csrf.token_manager', $csrf);
        $container->set('router', $router);
        $container->set('request_stack', $stack);
        $twig = new Environment(new ChainLoader([new ArrayLoader(['base.html.twig' => '{% block body %}{% endblock %}']), new FilesystemLoader(dirname(__DIR__, 2).'/templates')]), ['strict_variables' => true]);
        $twig->addGlobal('app_branding', ['name' => 'Example Analytics']);
        $twig->addFunction(new TwigFunction('path', self::url(...)));
        $twig->addFunction(new TwigFunction('csrf_token', static fn (): string => 'valid-token'));
        \App\Tests\Support\TwigComponents::register($twig);
        $container->set('twig', $twig);
        $controller->setContainer($container);

        return $controller;
    }

    private static function url(string $name, array $parameters = []): string
    {
        $path = $name === 'app_data_model' ? '/dashboard/data-model' : '/dashboard/data-model/glossary'.($name === 'app_bi_glossary' ? '' : '/'.substr($name, strlen('app_bi_glossary_')));

        return $path.($parameters === [] ? '' : '?'.http_build_query($parameters));
    }
}
