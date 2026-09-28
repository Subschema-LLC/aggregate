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
            'page_sequence_enabled' => '1',
            'page_sequence_method' => 'url_parameter',
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
        self::assertTrue($written['environments']['test']['page_sequence_enabled']);
        self::assertSame('url_parameter', $written['environments']['test']['page_sequence_method']);
        self::assertSame(['page_sequence' => 2], $this->settings->filterEventData(['page_sequence' => 2], false));
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
        foreach ([null, true, false, 'false', 'true', '2', ['1']] as $index => $value) {
            yield 'invalid page depth toggle '.$index => [$base + ['page_sequence_enabled' => $value]];
        }
        foreach ([null, true, false, '', 'url', 'URL_PARAMETER', ['url_parameter']] as $index => $value) {
            yield 'invalid page depth method '.$index => [$base + ['page_sequence_method' => $value]];
        }
        yield 'duplicate property' => [array_replace($base, ['properties' => [$row, array_replace($row, ['key' => ' plan '])]])];
        yield 'duplicate alias' => [array_replace($base, ['properties' => [$row, array_replace($row, ['key' => 'tier'])]])];
        yield 'malformed property collection' => [array_replace($base, ['properties' => 'plan'])];
        yield 'null property collection' => [array_replace($base, ['properties' => null])];
        yield 'malformed property row' => [array_replace($base, ['properties' => ['plan']])];
        yield 'incomplete property row' => [array_replace($base, ['properties' => [['key' => 'plan']]])];
        yield 'array field' => [array_replace($base, ['properties' => [array_replace($row, ['key' => ['plan']])]])];
        yield 'invalid consent' => [array_replace($base, ['properties' => [array_replace($row, ['consent_required' => 'false'])]])];
        yield 'unsafe column' => [array_replace($base, ['properties' => [array_replace($row, ['column' => 'visitor_id'])]])];
        yield 'array type' => [array_replace($base, ['properties' => [$row + ['type' => ['double']]]])];
        yield 'null type' => [array_replace($base, ['properties' => [$row + ['type' => null]]])];
        yield 'unknown type' => [array_replace($base, ['properties' => [$row + ['type' => 'object']]])];
        yield 'array numeric column' => [array_replace($base, ['properties' => [$row + ['numeric_column' => ['total']]]])];
        yield 'numeric projection on scalar' => [array_replace($base, ['properties' => [$row + ['numeric_column' => 'total']]])];
        yield 'numeric alias duplicates text alias' => [array_replace($base, ['properties' => [$row + ['type' => 'double', 'numeric_column' => 'plan']]])];
        yield 'reserved numeric alias' => [array_replace($base, ['properties' => [$row + ['type' => 'double', 'numeric_column' => 'visitor_id']]])];
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
        foreach (['index', 'discovery', 'reporting', 'save', 'regenerate', 'download'] as $action) {
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
        self::assertSame('/dashboard/data-model/reporting', $response->headers->get('Location'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
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
        $this->views->expects(self::never())->method('previewSql');
        $this->views->expects(self::never())->method('discoverProperties');
        $this->views->expects(self::never())->method('regenerate');
        $request = $this->request([], 'GET');

        $response = $this->controller($request)->index($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $crawler = new Crawler((string) $response->getContent());
        self::assertCount(1, $crawler->filter('form input[name="_csrf_token"][value="valid-token"]'));
        self::assertCount(1, $crawler->filter('form[action="/dashboard/data-model/save"][method="post"]'));
        self::assertCount(0, $crawler->filter('form[action="/dashboard/data-model/regenerate"]'));
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

    public function testDiscoveryProvidesExplicitReviewLinksAndEscapesUnsupportedPropertyNames(): void
    {
        $this->settings->save(['custom_data_properties' => [], 'query_parameter_mappings' => []]);
        $this->views->expects(self::never())->method('previewSql');
        $this->views->expects(self::once())->method('discoverProperties')->willReturn([
            ['key' => 'plan', 'types' => ['string'], 'event_count' => 9],
            ['key' => '<img src=x onerror=alert(1)>', 'types' => ['string'], 'event_count' => 1],
            ['key' => 'constructor', 'types' => ['string'], 'event_count' => 1],
        ]);
        $this->views->expects(self::never())->method('regenerate');
        $request = $this->request([], 'GET');
        $request->query->set('discover', '1');

        $before = file_get_contents($this->projectDir.'/config/aggregate.yaml');
        $response = $this->controller($request)->discovery($request);

        self::assertSame($before, file_get_contents($this->projectDir.'/config/aggregate.yaml'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));

        $crawler = new Crawler((string) $response->getContent());
        self::assertCount(1, $crawler->filter('a[href="/dashboard/data-model?add_property=plan"]'));
        self::assertCount(1, $crawler->filter('a[href*="add_property="]'));
        self::assertCount(0, $crawler->filter('form'));
        self::assertStringContainsString('Review in model editor', $crawler->text());
        self::assertCount(0, $crawler->filter('img'));
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', (string) $response->getContent());
    }

    public function testReportingFailuresLinkToTheIndependentEditorWithoutExposingExceptionDetails(): void
    {
        $this->views->expects(self::once())->method('previewSql')->willThrowException(new \RuntimeException('private-database-password'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with('Custom data model operation failed.', ['operation' => 'preview', 'exception_class' => \RuntimeException::class]);
        $request = $this->request([], 'GET');

        $response = $this->controller($request, logger: $logger)->reporting();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringNotContainsString('private-database-password', (string) $response->getContent());
        $crawler = new Crawler((string) $response->getContent());
        self::assertStringContainsString('The model can still be edited on its own page.', $crawler->filter('.notification.is-warning')->text());
        self::assertCount(1, $crawler->filter('a[href="/dashboard/data-model"]'));
        self::assertCount(0, $crawler->filter('#data-model-form'));
        self::assertCount(0, $crawler->filter('form[action="/dashboard/data-model/regenerate"]'));
    }

    public function testEditorKeepsFalseConsentSelectedAndDoesNotReadReportingData(): void
    {
        $this->settings->save([
            'custom_data_properties' => [
                'category' => ['consent_required' => false],
                'revenue' => ['consent_required' => true, 'type' => 'double', 'column' => 'revenue_text', 'numeric_column' => 'revenue_number'],
            ],
            'query_parameter_mappings' => [],
        ]);
        $this->views->expects(self::never())->method('previewSql');
        $this->views->expects(self::never())->method('discoverProperties');
        $this->views->expects(self::never())->method('regenerate');
        $request = $this->request([], 'GET');
        $response = $this->controller($request)->index($request);
        $crawler = new Crawler((string) $response->getContent());

        self::assertSame('0', $crawler->filter('#property-0-consent option[selected]')->attr('value'));
        self::assertSame('0', $crawler->filter('#page-sequence-enabled option[selected]')->attr('value'));
        self::assertSame('session_storage', $crawler->filter('#page-sequence-method option[selected]')->attr('value'));
        self::assertSame('1', $crawler->filter('#property-1-consent option[selected]')->attr('value'));
        self::assertSame('double', $crawler->filter('#property-1-type option[selected]')->attr('value'));
        self::assertSame('revenue_number', $crawler->filter('#property-1-numeric_column')->attr('value'));
        self::assertCount(1, $crawler->filter('#data-model-form'));
        self::assertCount(0, $crawler->filter('form[action="/dashboard/data-model/regenerate"]'));
        self::assertCount(1, $crawler->filter('a[href="/dashboard/data-model/reporting"]'));
    }

    public function testPageDepthToggleUsesSavedYamlAndCanBeDisabledWithoutChangingOtherSettings(): void
    {
        $this->config->set('page_sequence_enabled', true);
        $this->config->set('page_sequence_method', 'url_parameter');
        $request = $this->request([], 'GET');
        $response = $this->controller($request)->index($request);
        $crawler = new Crawler((string) $response->getContent());

        self::assertSame('1', $crawler->filter('#page-sequence-enabled option[selected]')->attr('value'));
        self::assertCount(1, $crawler->filter('label[for="page-sequence-enabled"]'));
        self::assertSame('url_parameter', $crawler->filter('#page-sequence-method option[selected]')->attr('value'));
        self::assertCount(1, $crawler->filter('label[for="page-sequence-method"]'));
        self::assertStringContainsString('browser session storage', $crawler->filter('#page-depth-storage')->text());
        self::assertStringContainsString('no cookies or Web Storage', $crawler->filter('#page-depth-url')->text());
        self::assertStringContainsString('copied or shared links', $crawler->filter('#page-depth-url')->text());
        self::assertStringContainsString('enhanced analytics is rejected', $crawler->filter('#page-depth-privacy')->text());

        $before = $this->settings->toArray();
        $request = $this->request([
            'page_sequence_enabled' => '0',
            'properties' => array_map(static fn (string $key, array $property): array => [
                'key' => $key,
                'description' => $property['description'],
                'column' => $property['column'],
                'consent_required' => $property['consent_required'] ? '1' : '0',
            ], array_keys($before['custom_data_properties']), array_values($before['custom_data_properties'])),
            'mappings' => array_map(static fn (string $parameter, string $property): array => compact('parameter', 'property'),
                array_keys($before['query_parameter_mappings']), array_values($before['query_parameter_mappings'])),
        ]);
        $this->controller($request)->save($request);

        self::assertSame([], $request->getSession()->getFlashBag()->peek('error'));
        self::assertSame(array_replace($before, ['page_sequence_enabled' => false]), $this->settings->toArray());
        self::assertNull($this->settings->filterEventData(['page_sequence' => 2], true));
        self::assertSame('https://test.example', $this->config->get('app_host'));
    }

    public function testReviewingPageSequenceUsesItsBuiltInIntegerDefinition(): void
    {
        $request = $this->request(['add_property' => 'page_sequence'], 'GET');
        $response = $this->controller($request)->index($request);
        $crawler = new Crawler((string) $response->getContent());

        self::assertSame(200, $response->getStatusCode());
        $row = $crawler->filter('#property-rows [data-property-row]')->last();
        self::assertSame('integer', $row->filter('select[name$="[type]"] option[selected]')->attr('value'));
        self::assertSame('0', $row->filter('select[name$="[consent_required]"] option[selected]')->attr('value'));
        self::assertFalse($this->settings->toArray()['page_sequence_enabled']);
        self::assertArrayNotHasKey('page_sequence', $this->settings->properties());
    }

    public function testLegacyPageSequenceMarkerCanBeReviewedWhilePageDepthIsDisabled(): void
    {
        $this->config->set('internal_traffic_name', 'page_sequence');
        $request = $this->request(['add_property' => 'page_sequence'], 'GET');
        $response = $this->controller($request)->index($request);
        $crawler = new Crawler((string) $response->getContent());

        self::assertSame(200, $response->getStatusCode());
        $row = $crawler->filter('#property-rows [data-property-row]')->last();
        self::assertSame('scalar', $row->filter('select[name$="[type]"] option[selected]')->attr('value'));
        self::assertSame('1', $row->filter('select[name$="[consent_required]"] option[selected]')->attr('value'));
        self::assertFalse($this->settings->toArray()['page_sequence_enabled']);
        self::assertArrayNotHasKey('page_sequence', $this->settings->properties());
    }

    public function testSavingTypedAndLegacyRowsPreservesTheirSharedValidationAndReportingAliases(): void
    {
        $this->views->expects(self::never())->method('regenerate');
        $row = ['description' => '', 'column' => '', 'consent_required' => '1'];
        $request = $this->request(['properties' => [
            ['key' => 'total', 'column' => 'total_text', 'type' => 'double', 'numeric_column' => ' total_number '] + $row,
            ['key' => 'quantity', 'type' => 'integer', 'numeric_column' => 'quantity_number'] + $row,
            ['key' => 'discount', 'type' => 'float', 'numeric_column' => 'discount_number'] + $row,
            ['key' => 'legacy', 'type' => 'scalar', 'numeric_column' => ''] + $row,
        ], 'mappings' => []]);
        $response = $this->controller($request)->save($request);

        self::assertSame('/dashboard/data-model', $response->headers->get('Location'));
        self::assertSame([], $request->getSession()->getFlashBag()->peek('error'));
        $saved = Yaml::parseFile($this->projectDir.'/config/aggregate.yaml')['environments']['test']['custom_data_properties'];
        self::assertSame('double', $saved['total']['type']);
        self::assertSame('total_text', $saved['total']['column']);
        self::assertSame('total_number', $saved['total']['numeric_column']);
        self::assertSame('integer', $saved['quantity']['type']);
        self::assertSame('float', $saved['discount']['type']);
        self::assertArrayNotHasKey('type', $saved['legacy']);
        self::assertArrayNotHasKey('numeric_column', $saved['legacy']);
        self::assertSame(['total' => 42.5, 'quantity' => 3, 'discount' => 1.25], $this->settings->filterEventData([
            'total' => 42.5, 'quantity' => 3, 'discount' => 1.25,
        ], true));
    }

    public function testLegacyDiscoveryLinkRedirectsToItsOwnPageWithoutQueryingTheDatabase(): void
    {
        $this->views->expects(self::never())->method('previewSql');
        $this->views->expects(self::never())->method('discoverProperties');
        $request = $this->request(['discover' => '1'], 'GET');
        $response = $this->controller($request)->index($request);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/dashboard/data-model/discovery?discover=1', $response->headers->get('Location'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }

    #[DataProvider('nonDiscoveryQueries')]
    public function testDiscoveryRequiresItsExactExplicitQueryBeforeReadingEvents(mixed $discover): void
    {
        $this->views->expects(self::never())->method('discoverProperties');
        $this->views->expects(self::never())->method('previewSql');
        $request = $this->request(['discover' => $discover], 'GET');
        $response = $this->controller($request)->discovery($request);

        self::assertSame(200, $response->getStatusCode());
        $crawler = new Crawler((string) $response->getContent());
        self::assertCount(1, $crawler->filter('a[href="/dashboard/data-model/discovery?discover=1"]'));
        self::assertCount(0, $crawler->filter('table, form'));
    }

    public static function nonDiscoveryQueries(): iterable
    {
        foreach ([null, '', '0', 'true', ['1']] as $index => $value) {
            yield 'value '.$index => [$value];
        }
    }

    public function testReviewingAPropertyCreatesOnlyAnUnsavedConsentRequiredEditorRow(): void
    {
        $this->settings->save(['custom_data_properties' => [], 'query_parameter_mappings' => []]);
        $this->views->expects(self::never())->method('discoverProperties');
        $this->views->expects(self::never())->method('previewSql');
        $this->views->expects(self::never())->method('regenerate');
        $before = file_get_contents($this->projectDir.'/config/aggregate.yaml');
        $request = $this->request(['add_property' => 'product.category'], 'GET');
        $response = $this->controller($request)->index($request);
        $crawler = new Crawler((string) $response->getContent());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($before, file_get_contents($this->projectDir.'/config/aggregate.yaml'));
        self::assertSame([], $this->settings->toArray()['custom_data_properties']);
        self::assertSame('product.category', $crawler->filter('#property-0-key')->attr('value'));
        self::assertSame('1', $crawler->filter('#property-0-consent option[selected]')->attr('value'));
        self::assertSame('', $crawler->filter('#property-0-column')->attr('value'));
        self::assertCount(1, $crawler->filter('#property-rows details[open]'));
        self::assertStringContainsString('save to apply the change', $crawler->text());
    }

    #[DataProvider('invalidReviewKeys')]
    public function testUnsafeOrMalformedReviewKeysCannotCreateModelRows(mixed $key): void
    {
        $this->settings->save(['custom_data_properties' => [], 'query_parameter_mappings' => []]);
        $before = file_get_contents($this->projectDir.'/config/aggregate.yaml');
        $request = $this->request(['add_property' => $key], 'GET');
        $response = $this->controller($request)->index($request);
        $crawler = new Crawler((string) $response->getContent());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($before, file_get_contents($this->projectDir.'/config/aggregate.yaml'));
        self::assertCount(0, $crawler->filter('#property-rows input, img'));
        self::assertStringContainsString('This property name cannot be added', $crawler->text());
    }

    public static function invalidReviewKeys(): iterable
    {
        foreach (['constructor', '<img src=x onerror=alert(1)>', ['plan'], str_repeat('a', 129)] as $index => $key) {
            yield 'key '.$index => [$key];
        }
    }

    public function testReportingPageOnlyPreviewsTheSavedModelAndProvidesAProtectedRegenerationForm(): void
    {
        $this->views->expects(self::once())->method('previewSql')->willReturn([
            'analytics_custom_events_v1' => 'SELECT 1 AS "<script>";',
        ]);
        $this->views->expects(self::never())->method('discoverProperties');
        $this->views->expects(self::never())->method('regenerate');
        $request = $this->request([], 'GET');
        $response = $this->controller($request)->reporting();
        $crawler = new Crawler((string) $response->getContent());

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertCount(1, $crawler->filter('form[action="/dashboard/data-model/regenerate"][method="post"] input[name="_csrf_token"][value="valid-token"]'));
        self::assertCount(0, $crawler->filter('#data-model-form, script'));
        self::assertStringContainsString('&lt;script&gt;', (string) $response->getContent());
    }

    public function testDiscoveryFailureIsPrivateAndLogsOnlyOperationAndExceptionClass(): void
    {
        $this->views->expects(self::once())->method('discoverProperties')->willThrowException(new \RuntimeException('private-password'));
        $this->views->expects(self::never())->method('previewSql');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with('Custom data model operation failed.', [
            'operation' => 'discover', 'exception_class' => \RuntimeException::class,
        ]);
        $request = $this->request(['discover' => '1'], 'GET');
        $response = $this->controller($request, logger: $logger)->discovery($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertStringNotContainsString('private-password', (string) $response->getContent());
        self::assertStringContainsString('Observed properties could not be loaded', (string) $response->getContent());
    }

    private function request(array $values, string $method = 'POST'): Request
    {
        $request = Request::create('/dashboard/data-model', $method, ['_csrf_token' => 'valid-token', ...$values]);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    private function invokeAction(DataModelController $controller, string $action, Request $request): Response
    {
        return in_array($action, ['download', 'reporting'], true) ? $controller->$action() : $controller->$action($request);
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
        $router->method('generate')->willReturnCallback(self::url(...));
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
        $twig->addFunction(new TwigFunction('asset', static fn (string $path): string => '/assets/'.$path));
        $twig->addGlobal('app_branding', ['name' => 'Aggregate']);
        $twig->addFunction(new TwigFunction('path', self::url(...)));
        $twig->addFunction(new TwigFunction('csrf_token', static fn (string $id): string => $id === DataModelController::CSRF_TOKEN_ID ? 'valid-token' : 'wrong-token'));

        \App\Tests\Support\TwigComponents::register($twig);

        return $twig;
    }
    private static function url(string $name, array $parameters = []): string
    {
        $path = match ($name) {
            'app_data_model' => '/dashboard/data-model',
            'app_data_model_save' => '/dashboard/data-model/save',
            'app_data_model_regenerate' => '/dashboard/data-model/regenerate',
            'app_data_model_download' => '/dashboard/data-model/download',
            'app_data_model_discovery' => '/dashboard/data-model/discovery',
            'app_data_model_reporting' => '/dashboard/data-model/reporting',
            'app_event_examples' => '/dashboard/data-model/examples',
        };

        return $path.($parameters === [] ? '' : '?'.http_build_query($parameters));
    }

}
