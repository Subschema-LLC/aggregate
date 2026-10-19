<?php

declare(strict_types=1);

namespace App\Tests\Service\Mcp;

use App\Service\AggregateConfigLoader;
use App\Service\CustomDataSettings;
use App\Service\Glossary\BuiltinGlossaryCatalog;
use App\Service\Mcp\McpPrincipal;
use App\Service\Mcp\McpTools;
use App\Service\ReportingApi\ApiToken;
use App\Service\ReportingApi\ReportingApiSettings;
use App\Service\ReportingApi\ViewQueryRunner;
use App\Service\ReportingApi\ViewSchema;
use App\Service\ReportingTimezone;
use App\Service\ViewSync\ViewCatalog;
use App\Service\ViewSync\ViewReader;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Yaml\Yaml;

/**
 * The MCP tools against SQLite views standing in for the real ones. The
 * daily view here withholds cells below 5 the way the real views withhold
 * small cells, so the contract tests can show that no tool argument brings a
 * withheld cell back.
 */
final class McpToolsTest extends TestCase
{
    private string $projectDir;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/aggregate-mcp-tools-'.bin2hex(random_bytes(8));
        mkdir($this->projectDir.'/config', 0700, true);
        $this->configure();

        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('CREATE TABLE cells (website_token TEXT, event_day TEXT, event_name TEXT, page_group TEXT, referrer_channel TEXT, device_class TEXT, event_count INTEGER)');
        $this->connection->executeStatement('CREATE VIEW bi_anonymous_daily_v1 AS SELECT website_token, event_day, event_name, page_group, referrer_channel, device_class, event_count FROM cells WHERE event_count >= 5');
        $this->connection->executeStatement('CREATE TABLE hours (website_token TEXT, event_hour TEXT, event_name TEXT, page_path TEXT, event_count INTEGER)');
        $this->connection->executeStatement('CREATE VIEW bi_anonymous_events_v1 AS SELECT website_token, event_hour, event_name, page_path, event_count FROM hours');
        $this->connection->executeStatement('CREATE TABLE coverage (website_token TEXT, event_day TEXT, view_name TEXT, coverage_band TEXT)');
        $this->connection->executeStatement('CREATE VIEW bi_anonymous_coverage_v1 AS SELECT website_token, event_day, view_name, coverage_band FROM coverage');
        $this->connection->executeStatement("CREATE VIEW bi_dim_device_class_v1 AS SELECT 'mobile' AS device_class, 'Mobile' AS device_class_label");
        $this->connection->executeStatement('CREATE TABLE raw (id INTEGER, website_token TEXT, created_at TEXT)');
        $this->connection->executeStatement('CREATE VIEW analytics_custom_events_v1 AS SELECT id, website_token, created_at FROM raw');
        foreach ([
            ['site-a', '2026-10-01', 'view', '/blog', 'search', 'mobile', 12],
            ['site-a', '2026-10-01', 'view', '/blog', 'direct', 'desktop', 8],
            ['site-a', '2026-10-01', 'view', '/secret', 'direct', 'desktop', 2],
            ['site-a', '2026-10-02', 'view', '/blog', 'search', 'mobile', 6],
            ['site-b', '2026-10-01', 'view', '/pricing', 'social', 'mobile', 30],
        ] as $row) {
            $this->connection->insert('cells', array_combine(['website_token', 'event_day', 'event_name', 'page_group', 'referrer_channel', 'device_class', 'event_count'], $row));
        }
        foreach ([['site-a', '2026-10-01 23:00:00', 'view', '/blog', 7], ['site-a', '2026-10-02 00:00:00', 'view', '/blog', 9], ['site-a', '2026-10-03 00:00:00', 'view', '/blog', 4]] as $row) {
            $this->connection->insert('hours', array_combine(['website_token', 'event_hour', 'event_name', 'page_path', 'event_count'], $row));
        }
        foreach ([['site-a', '2026-10-01', 'bi_anonymous_daily_v1', '90-100'], ['site-a', '2026-10-02', 'bi_anonymous_daily_v1', '50-90'], ['site-b', '2026-10-01', 'bi_anonymous_daily_v1', '90-100'], ['site-a', '2026-10-01', 'bi_anonymous_events_v1', '10-50']] as $row) {
            $this->connection->insert('coverage', array_combine(['website_token', 'event_day', 'view_name', 'coverage_band'], $row));
        }
        $this->connection->insert('raw', ['id' => 1, 'website_token' => 'site-a', 'created_at' => '2026-10-01 13:00:00']);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectDir);
    }

    public function testTheDefinitionsDescribeFourReadOnlyTools(): void
    {
        $definitions = $this->tools()->definitions();

        self::assertSame(McpTools::NAMES, array_column($definitions, 'name'));
        foreach ($definitions as $definition) {
            self::assertTrue($definition['annotations']['readOnlyHint'], $definition['name']);
            self::assertFalse($definition['annotations']['destructiveHint'], $definition['name']);
            self::assertSame('object', $definition['inputSchema']['type']);
            self::assertFalse($definition['inputSchema']['additionalProperties']);
        }
        self::assertSame('{}', json_encode($definitions[0]['inputSchema']['properties']), 'An empty properties object, not a list');
    }

    public function testListViewsShowsOnlyTheCredentialsViews(): void
    {
        $result = $this->call('list_views', [], ['bi_anonymous_daily_v1']);

        $names = array_column($result['data']['views'], 'name');
        self::assertSame('bi_anonymous_daily_v1', $names[0]);
        self::assertNotContains('bi_anonymous_events_v1', $names);
        self::assertContains('bi_dim_device_class_v1', $names, 'Labels come with any scope');
        self::assertNotContains('analytics_custom_events_v1', $names);
        self::assertSame('America/Chicago', $result['data']['reporting_timezone']);
    }

    public function testDescribeViewGivesColumnsCodesAndNotes(): void
    {
        $data = $this->call('describe_view', ['view' => 'bi_anonymous_daily_v1'])['data'];

        self::assertSame('event_day', $data['time_column']);
        self::assertTrue($data['can_group']);
        $columns = array_column($data['columns'], null, 'name');
        self::assertSame('America/Chicago', $columns['event_day']['timezone']);
        self::assertContains('mobile', array_column($columns['device_class']['values'], 'code'));
        self::assertArrayNotHasKey('values', $columns['page_group'], 'Only glossary dimensions list codes');
        self::assertNotEmpty($data['notes']);
    }

    public function testQueriesFilterGroupAndPage(): void
    {
        $rows = $this->call('query_view', [
            'view' => 'bi_anonymous_daily_v1',
            'filters' => [['column' => 'website_token', 'operator' => 'eq', 'value' => 'site-a']],
            'group_by' => ['event_day'],
            'order_by' => ['event_day'],
        ]);
        self::assertSame([['event_day' => '2026-10-01', 'event_count' => 20], ['event_day' => '2026-10-02', 'event_count' => 6]], $rows['data']['rows']);
        self::assertSame(['event_day'], $rows['group_by']);
        self::assertSame(2, $rows['rows']);

        $page = $this->call('query_view', ['view' => 'bi_anonymous_daily_v1', 'columns' => ['page_group', 'event_count'], 'order_by' => ['-event_count'], 'limit' => 2]);
        self::assertSame([['page_group' => '/pricing', 'event_count' => 30], ['page_group' => '/blog', 'event_count' => 12]], $page['data']['rows']);
        self::assertTrue($page['data']['has_more']);
        self::assertSame(2, $page['data']['next_offset']);
        $next = $this->call('query_view', ['view' => 'bi_anonymous_daily_v1', 'columns' => ['page_group', 'event_count'], 'order_by' => ['-event_count'], 'limit' => 2, 'offset' => 2]);
        self::assertSame([8, 6], array_column($next['data']['rows'], 'event_count'));
        self::assertFalse($next['data']['has_more']);

        $in = $this->call('query_view', ['view' => 'bi_anonymous_daily_v1', 'filters' => [['column' => 'referrer_channel', 'operator' => 'in', 'value' => ['social', 'direct']], ['column' => 'event_count', 'operator' => 'gte', 'value' => 8]], 'group_by' => []]);
        self::assertSame([['event_count' => 38]], $in['data']['rows']);
    }

    public function testDateRangesIncludeWholeDaysAndCoverageIsSummarized(): void
    {
        $hourly = $this->call('query_view', ['view' => 'bi_anonymous_events_v1', 'date_range' => ['from' => '2026-10-02', 'to' => '2026-10-02'], 'group_by' => []]);
        self::assertSame([['event_count' => 9]], $hourly['data']['rows'], 'The last day\'s hours are included, the next day\'s are not');

        $daily = $this->call('query_view', ['view' => 'bi_anonymous_daily_v1', 'date_range' => ['from' => '2026-10-01', 'to' => '2026-10-02'], 'filters' => [['column' => 'website_token', 'operator' => 'eq', 'value' => 'site-a']], 'group_by' => []]);
        self::assertSame([['event_count' => 26]], $daily['data']['rows']);
        self::assertSame(['50-90' => 1, '90-100' => 1], $daily['data']['coverage']['bands']);
        self::assertSame(2, $daily['data']['coverage']['days_with_a_band']);

        $undated = $this->call('query_view', ['view' => 'bi_anonymous_daily_v1', 'group_by' => []]);
        self::assertIsString($undated['data']['coverage'], 'Without days, a pointer to get_coverage');

        $coverage = $this->call('get_coverage', ['view' => 'bi_anonymous_daily_v1', 'date_range' => ['from' => '2026-10-01', 'to' => '2026-10-31'], 'website_token' => 'site-a']);
        self::assertSame([
            ['website_token' => 'site-a', 'event_day' => '2026-10-01', 'coverage_band' => '90-100'],
            ['website_token' => 'site-a', 'event_day' => '2026-10-02', 'coverage_band' => '50-90'],
        ], $coverage['data']['rows']);
    }

    /**
     * Contract: whatever the arguments, a cell the view withholds never comes
     * back, and nothing outside the catalog can be named.
     */
    public function testNoArgumentsBringAWithheldCellOrARawTableBack(): void
    {
        $attempts = [
            ['view' => 'bi_anonymous_daily_v1', 'filters' => [['column' => 'page_group', 'operator' => 'eq', 'value' => '/secret']]],
            ['view' => 'bi_anonymous_daily_v1', 'filters' => [['column' => 'event_count', 'operator' => 'lt', 'value' => 5]]],
            ['view' => 'bi_anonymous_daily_v1', 'filters' => [['column' => 'page_group', 'operator' => 'starts_with', 'value' => '/s']], 'group_by' => []],
            ['view' => 'bi_anonymous_daily_v1', 'group_by' => ['page_group'], 'filters' => [['column' => 'page_group', 'operator' => 'null', 'value' => false]]],
            ['view' => 'bi_anonymous_daily_v1', 'filters' => [['column' => 'page_group', 'operator' => 'ne', 'value' => '/blog']], 'offset' => 0, 'limit' => 1000],
        ];
        foreach ($attempts as $index => $arguments) {
            $result = $this->call('query_view', $arguments);
            self::assertNull($result['error'], (string) $index);
            $json = json_encode($result['data']);
            self::assertStringNotContainsString('/secret', (string) $json, (string) $index);
            foreach ($result['data']['rows'] as $row) {
                self::assertNotSame(2, $row['event_count'] ?? null, (string) $index);
            }
        }
        $total = $this->call('query_view', ['view' => 'bi_anonymous_daily_v1', 'filters' => [['column' => 'page_group', 'operator' => 'starts_with', 'value' => '/s']], 'group_by' => []]);
        self::assertSame([['event_count' => null]], $total['data']['rows']);
        self::assertStringContainsString('not that nothing happened', $total['data']['note']);

        foreach (['cells', 'events', 'raw', 'bi_anonymous_daily_v1; DROP TABLE cells', 'sqlite_master'] as $name) {
            self::assertSame('unknown_view', $this->call('query_view', ['view' => $name])['error'], $name);
        }
        self::assertSame('unknown_column', $this->call('query_view', ['view' => 'bi_anonymous_daily_v1', 'columns' => ['event_count) FROM cells --']])['error']);
        self::assertSame(5, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM cells'));
    }

    public function testViewsOutsideTheGrantAndPrivateViewsAreRefused(): void
    {
        self::assertSame('view_not_permitted', $this->call('query_view', ['view' => 'bi_anonymous_events_v1'], ['bi_anonymous_daily_v1'])['error']);
        self::assertSame('view_not_permitted', $this->call('describe_view', ['view' => 'bi_anonymous_events_v1'], ['bi_anonymous_daily_v1'])['error']);
        self::assertSame('private_views_disabled', $this->call('query_view', ['view' => 'analytics_custom_events_v1'], null, ['analytics_custom_events_v1'])['error']);
        self::assertSame('view_not_permitted', $this->call('get_coverage', ['view' => 'bi_anonymous_daily_v1', 'date_range' => ['from' => '2026-10-01', 'to' => '2026-10-02']], ['bi_anonymous_daily_v1'])['error'], 'Coverage needs the coverage view too');

        $this->configure(['reporting_api_private_views' => true]);
        $private = $this->call('query_view', ['view' => 'analytics_custom_events_v1', 'columns' => ['id']], [], ['analytics_custom_events_v1']);
        self::assertSame([['id' => 1]], $private['data']['rows']);
        self::assertSame('view_not_permitted', $this->call('query_view', ['view' => 'analytics_custom_events_v1'])['error'], 'Private views are granted one by one');
    }

    public function testBadArgumentsAreToolErrorsTheModelCanFix(): void
    {
        foreach ([
            'unknown argument' => ['list_views', ['view' => 'x'], 'invalid_argument'],
            'missing view' => ['query_view', [], 'invalid_argument'],
            'unknown operator' => ['query_view', ['view' => 'bi_anonymous_daily_v1', 'filters' => [['column' => 'page_group', 'operator' => 'like', 'value' => '%']]], 'invalid_argument'],
            'range on text' => ['query_view', ['view' => 'bi_anonymous_daily_v1', 'filters' => [['column' => 'page_group', 'operator' => 'gt', 'value' => 'a']]], 'unsupported_operator'],
            'object value' => ['query_view', ['view' => 'bi_anonymous_daily_v1', 'filters' => [['column' => 'page_group', 'operator' => 'eq', 'value' => ['a' => 1]]]], 'invalid_argument'],
            'limit above the cap' => ['query_view', ['view' => 'bi_anonymous_daily_v1', 'limit' => 1001], 'invalid_argument'],
            'limit as text' => ['query_view', ['view' => 'bi_anonymous_daily_v1', 'limit' => '10'], 'invalid_argument'],
            'group by the measure' => ['query_view', ['view' => 'bi_anonymous_daily_v1', 'group_by' => ['event_count']], 'invalid_parameter'],
            'group a label view' => ['query_view', ['view' => 'bi_dim_device_class_v1', 'group_by' => []], 'invalid_parameter'],
            'view missing from the database' => ['query_view', ['view' => 'bi_dim_geo_area_v1'], 'view_unavailable'],
            'bad date' => ['query_view', ['view' => 'bi_anonymous_daily_v1', 'date_range' => ['from' => '2026-02-30']], 'invalid_argument'],
            'reversed range' => ['query_view', ['view' => 'bi_anonymous_daily_v1', 'date_range' => ['from' => '2026-10-02', 'to' => '2026-10-01']], 'invalid_argument'],
            'range twice' => ['query_view', ['view' => 'bi_anonymous_daily_v1', 'date_range' => ['from' => '2026-10-01'], 'filters' => [['column' => 'event_day', 'operator' => 'gte', 'value' => '2026-10-01']]], 'invalid_argument'],
            'coverage of a goal view' => ['get_coverage', ['view' => 'bi_anonymous_goals_v1', 'date_range' => ['from' => '2026-10-01', 'to' => '2026-10-02']], 'invalid_argument'],
            'coverage over a year' => ['get_coverage', ['view' => 'bi_anonymous_daily_v1', 'date_range' => ['from' => '2025-01-01', 'to' => '2026-10-02']], 'invalid_argument'],
            'unknown tool' => ['drop_table', [], 'unknown_tool'],
        ] as $case => [$tool, $arguments, $code]) {
            $result = $this->call($tool, $arguments);
            self::assertSame($code, $result['error'], $case);
            self::assertNotSame('', (string) $result['message'], $case);
        }
    }

    public function testRowsAreCappedBelowTheApiSetting(): void
    {
        $this->configure(['reporting_api_max_page_size' => 2]);
        self::assertSame('invalid_argument', $this->call('query_view', ['view' => 'bi_anonymous_daily_v1', 'limit' => 3])['error']);
        self::assertSame(2, $this->call('query_view', ['view' => 'bi_anonymous_daily_v1', 'limit' => 2])['rows']);
    }

    /**
     * @param array<string, mixed> $arguments
     * @param list<string>|null    $views
     * @param list<string>         $privateViews
     *
     * @return array{data: array<string, mixed>, view: ?string, rows: int, group_by: ?list<string>, error: ?string, message: ?string}
     */
    private function call(string $tool, array $arguments, ?array $views = null, array $privateViews = []): array
    {
        $token = new ApiToken('3f9a1c2e', 'Test', 'sha256:'.str_repeat('0', 64), $views, $privateViews, new \DateTimeImmutable(), 'test', null, true);

        return $this->tools()->call($tool, $arguments, McpPrincipal::token($token));
    }

    private function tools(): McpTools
    {
        $config = new AggregateConfigLoader($this->projectDir, 'test');
        $reader = new ViewReader($this->connection, new ViewCatalog(new CustomDataSettings($config)));
        $translator = new Translator('en');
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource('yaml', dirname(__DIR__, 3).'/translations/bi_glossary.en.yaml', 'en', 'bi_glossary');

        return new McpTools(
            new ViewSchema($reader, new BuiltinGlossaryCatalog($translator), new ReportingTimezone($config)),
            new ViewQueryRunner($this->connection, $reader),
            new ReportingApiSettings($config),
        );
    }

    /** @param array<string, mixed> $settings */
    private function configure(array $settings = []): void
    {
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump(['reporting_timezone' => 'America/Chicago', ...$settings]));
    }
}
