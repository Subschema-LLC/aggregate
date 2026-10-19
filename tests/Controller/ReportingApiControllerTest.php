<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Service\AggregateConfigLoader;
use App\Service\FeatureFlags;
use App\Service\ReportingApi\ApiTokenStore;
use App\Service\ReportingApi\ReportingApiSettings;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/**
 * The reporting API over HTTP, as a client sees it: the feature flag, bearer
 * tokens, view permissions, private views, streamed pages, problem
 * responses and the OpenAPI document. Views are SQLite views standing in for
 * the real ones; ViewQueryRunnerTest covers the queries.
 */
final class ReportingApiControllerTest extends WebTestCase
{
    private string $projectDir;
    private array $environment;
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        foreach (['REPORTING_API_PRIVATE_VIEWS', 'REPORTING_API_MAX_PAGE_SIZE', 'REPORTING_TIMEZONE'] as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
        }
        $this->projectDir = sys_get_temp_dir().'/aggregate-api-http-'.bin2hex(random_bytes(8));
        mkdir($this->projectDir.'/config', 0700, true);
        $this->client = self::createClient();
        // Keep the test configuration, token file and database across requests.
        $this->client->disableReboot();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        [$_ENV, $_SERVER] = $this->environment;
        (new Filesystem())->remove($this->projectDir);
    }

    public function testTheApiDoesNotExistWhileTheFlagIsOff(): void
    {
        $secret = $this->boot(flag: false, createToken: false);
        $this->client->request('GET', '/api/v1/views', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$secret]);

        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString('problem+json', (string) $this->client->getResponse()->headers->get('Content-Type'), 'An ordinary 404, not an API error');
    }

    public function testRequestsNeedAValidBearerToken(): void
    {
        $secret = $this->boot();

        $this->client->request('GET', '/api/v1/views');
        $this->assertProblem(401, 'missing_token');
        self::assertResponseHeaderSame('WWW-Authenticate', 'Bearer realm="reporting-api"');

        $this->client->request('GET', '/api/v1/views', server: ['HTTP_AUTHORIZATION' => 'Bearer '.substr($secret, 0, -1).'x']);
        $this->assertProblem(401, 'invalid_token');

        $this->client->request('GET', '/api/v1/views', server: ['HTTP_AUTHORIZATION' => 'Basic '.base64_encode('a:b')]);
        $this->assertProblem(401, 'invalid_token');

        $this->client->request('GET', '/api/v1/views?access_token='.$secret);
        $this->assertProblem(400, 'token_in_query');
        self::assertStringNotContainsString($secret, (string) $this->client->getResponse()->getContent());
    }

    public function testATokenListsAndReadsOnlyItsViews(): void
    {
        $secret = $this->boot(views: ['bi_anonymous_daily_v1']);

        $list = $this->json('/api/v1/views', $secret);
        self::assertSame(['bi_anonymous_daily_v1'], array_column($list['data'], 'name'));
        self::assertSame(['private_views_allowed' => false, 'reporting_timezone' => 'UTC'], $list['meta']);
        self::assertSame('http://localhost/api/v1/views/bi_anonymous_daily_v1', $list['data'][0]['links']['rows']);
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');

        $this->client->request('GET', '/api/v1/views/bi_anonymous_events_v1', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$secret]);
        $this->assertProblem(403, 'view_not_permitted');
        $this->client->request('GET', '/api/v1/views/events', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$secret]);
        $this->assertProblem(404, 'unknown_view');
    }

    public function testRowsAreStreamedWithMetaAndANextLink(): void
    {
        $secret = $this->boot();

        $page = $this->json('/api/v1/views/bi_anonymous_daily_v1?limit=2&sort=-event_count&fields=page_group,event_count', $secret);
        self::assertSame([['page_group' => '/pricing', 'event_count' => 30], ['page_group' => '/blog', 'event_count' => 12]], $page['data']);
        self::assertSame(['view' => 'bi_anonymous_daily_v1', 'approved' => true, 'limit' => 2, 'offset' => 0, 'returned' => 2, 'has_more' => true, 'reporting_timezone' => 'UTC'], $page['meta']);
        self::assertStringContainsString('offset=2', (string) $page['links']['next']);

        $next = $this->json((string) $page['links']['next'], $secret);
        self::assertSame([9], array_column($next['data'], 'event_count'));
        self::assertFalse($next['meta']['has_more']);
        self::assertNull($next['links']['next']);

        $this->client->request('GET', '/api/v1/views/bi_anonymous_daily_v1?filter[page_group][gt]=a', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$secret]);
        $this->assertProblem(400, 'unsupported_operator');
        $this->client->request('GET', '/api/v1/views/bi_anonymous_daily_v1?limit=11', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$secret]);
        $this->assertProblem(400, 'invalid_parameter');
    }

    public function testPrivateViewsFollowTheDeploymentToggle(): void
    {
        $secret = $this->boot(settings: ['reporting_api_private_views' => true], views: [], privateViews: ['analytics_custom_events_v1']);
        self::assertSame([1, 2], array_column($this->json('/api/v1/views/analytics_custom_events_v1?fields=id', $secret)['data'], 'id'));

        $this->writeConfig(['reporting_api_private_views' => false]);
        $this->client->request('GET', '/api/v1/views/analytics_custom_events_v1', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$secret]);
        $this->assertProblem(403, 'private_views_disabled');
        self::assertSame([], $this->json('/api/v1/views', $secret)['data']);
    }

    public function testTheSchemaAndOpenApiDocumentDescribeOnlyReadableViews(): void
    {
        $secret = $this->boot(views: ['bi_anonymous_daily_v1']);

        $schema = $this->json('/api/v1/views/bi_anonymous_daily_v1/schema', $secret)['data'];
        self::assertSame(['website_token', 'event_day', 'page_group', 'event_count'], array_column($schema['columns'], 'name'));
        self::assertSame(['eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'in', 'null'], $schema['columns'][3]['filter_operators']);

        $document = $this->json('/api/v1/openapi.json', $secret);
        self::assertSame('3.1.0', $document['openapi']);
        self::assertSame([['url' => 'http://localhost']], $document['servers']);
        self::assertArrayHasKey('/api/v1/views/bi_anonymous_daily_v1', $document['paths']);
        self::assertArrayNotHasKey('/api/v1/views/bi_anonymous_events_v1', $document['paths']);
        self::assertArrayNotHasKey('analytics_custom_events_v1_row', $document['components']['schemas']);
        self::assertSame(10, $document['paths']['/api/v1/views/bi_anonymous_daily_v1']['get']['parameters'][2]['schema']['maximum']);

        $this->client->request('GET', '/api/v1/openapi.json');
        $this->assertProblem(401, 'missing_token');
    }

    public function testUnknownAddressesAndWritesAreProblems(): void
    {
        $secret = $this->boot();
        $this->client->request('GET', '/api/v1/nothing', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$secret]);
        $this->assertProblem(404, 'not_found');
        $this->client->request('POST', '/api/v1/views', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$secret]);
        $this->assertProblem(405, 'method_not_allowed');
    }

    /** @return array<string, mixed> */
    private function json(string $uri, string $secret): array
    {
        $this->client->request('GET', $uri, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$secret]);
        self::assertResponseIsSuccessful();
        // Rows are a streamed response; the browser keeps what was sent.
        $content = (string) $this->client->getInternalResponse()->getContent();

        return json_decode($content, true, 64, JSON_THROW_ON_ERROR);
    }

    private function assertProblem(int $status, string $code): void
    {
        self::assertResponseStatusCodeSame($status);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        $problem = json_decode((string) $this->client->getInternalResponse()->getContent(), true, 8, JSON_THROW_ON_ERROR);
        self::assertSame($code, $problem['code']);
        self::assertSame($status, $problem['status']);
    }

    /**
     * Replaces the configuration, the token file and the database with test
     * copies, and returns a new token's secret.
     *
     * @param array<string, mixed> $settings
     * @param list<string>|null    $views
     * @param list<string>         $privateViews
     */
    private function boot(bool $flag = true, array $settings = [], ?array $views = null, array $privateViews = [], bool $createToken = true): string
    {
        $this->writeConfig([...$settings, 'reporting_api_max_page_size' => 10], $flag);
        $config = new AggregateConfigLoader($this->projectDir, 'test');
        $container = self::getContainer();
        $container->set(AggregateConfigLoader::class, $config);
        $container->set('doctrine.dbal.default_connection', $this->database());

        $secret = 'agg_00000000_'.str_repeat('A', 43);
        if ($createToken) {
            // The store creates tokens only while the flag is on.
            $this->writeConfig([...$settings, 'reporting_api_max_page_size' => 10], true);
            [, $secret] = (new ApiTokenStore($this->projectDir, new FeatureFlags($config), new ReportingApiSettings($config)))->create('Test', $views, $privateViews, null, 'test');
            $this->writeConfig([...$settings, 'reporting_api_max_page_size' => 10], $flag);
        }
        $container->set(ApiTokenStore::class, new ApiTokenStore($this->projectDir, new FeatureFlags($config), new ReportingApiSettings($config)));

        return $secret;
    }

    /** @param array<string, mixed> $settings */
    private function writeConfig(array $settings, bool $flag = true): void
    {
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump([
            'feature_flags' => ['reporting_api' => ['enabled' => $flag, 'hide_from_navigation' => false]],
            ...$settings,
        ], 4));
    }

    private function database(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE daily (website_token TEXT, event_day TEXT, page_group TEXT, event_count INTEGER)');
        $connection->executeStatement('CREATE VIEW bi_anonymous_daily_v1 AS SELECT website_token, event_day, page_group, event_count FROM daily');
        $connection->executeStatement('CREATE TABLE raw (id INTEGER, website_token TEXT, created_at TEXT)');
        $connection->executeStatement('CREATE VIEW analytics_custom_events_v1 AS SELECT id, website_token, created_at FROM raw');
        foreach ([['site-a', '2026-10-01', '/blog', 12], ['site-a', '2026-10-02', '/blog', 9], ['site-b', '2026-10-01', '/pricing', 30]] as $row) {
            $connection->insert('daily', array_combine(['website_token', 'event_day', 'page_group', 'event_count'], $row));
        }
        $connection->insert('raw', ['id' => 2, 'website_token' => 'site-a', 'created_at' => '2026-10-01 14:00:00']);
        $connection->insert('raw', ['id' => 1, 'website_token' => 'site-a', 'created_at' => '2026-10-01 13:00:00']);

        return $connection;
    }
}
