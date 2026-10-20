<?php

declare(strict_types=1);

namespace App\Tests\Service\ReportingApi;

use App\Service\AggregateConfigLoader;
use App\Service\CustomDataSettings;
use App\Service\Glossary\BuiltinGlossaryCatalog;
use App\Service\ReportingApi\ApiProblem;
use App\Service\ReportingApi\ViewQuery;
use App\Service\ReportingApi\ViewQueryRunner;
use App\Service\ReportingApi\ViewSchema;
use App\Service\ReportingTimezone;
use App\Service\ViewSync\ViewCatalog;
use App\Service\ViewSync\ViewReader;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Yaml\Yaml;

/**
 * Runs the API's queries against real SQLite views. The SQL other engines
 * receive is checked as text; that is not evidence it runs on them.
 */
final class ViewQueryRunnerTest extends TestCase
{
    private string $projectDir;
    private Connection $connection;
    private ViewSchema $schema;
    private ViewQueryRunner $runner;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/aggregate-api-query-'.bin2hex(random_bytes(8));
        mkdir($this->projectDir.'/config', 0700, true);
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump(['reporting_timezone' => 'America/Chicago']));
        $config = new AggregateConfigLoader($this->projectDir, 'test');

        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('CREATE TABLE daily (website_token TEXT, event_day TEXT, page_group TEXT, event_count INTEGER)');
        $this->connection->executeStatement('CREATE VIEW bi_anonymous_daily_v1 AS SELECT website_token, event_day, page_group, event_count FROM daily');
        $this->connection->executeStatement('CREATE TABLE raw (id INTEGER, website_token TEXT, page_path TEXT, privacy_mode TEXT, utm_medium TEXT, created_at TEXT, archived_at TEXT)');
        $this->connection->executeStatement('CREATE VIEW analytics_custom_events_v1 AS SELECT id, website_token, page_path, privacy_mode, utm_medium, created_at, archived_at FROM raw');
        foreach ([
            ['site-a', '2026-10-01', '/blog', 12],
            ['site-a', '2026-10-01', '/Blog', 7],
            ['site-a', '2026-10-02', '/blog', 9],
            ['site-a', '2026-10-02', '/a%b', 5],
            ['site-a', '2026-10-02', '/abc', 6],
            ['site-b', '2026-10-01', '/pricing', 30],
            ['site-b', '2026-10-03', null, 8],
        ] as $row) {
            $this->connection->insert('daily', array_combine(['website_token', 'event_day', 'page_group', 'event_count'], $row));
        }
        foreach ([[1, 'site-a', '/x', '2026-10-01 13:00:00'], [2, 'site-a', '/y', '2026-10-01 14:00:00'], [3, 'site-b', '/z', '2026-10-02 09:30:00']] as $row) {
            $this->connection->insert('raw', array_combine(['id', 'website_token', 'page_path', 'created_at'], $row));
        }

        $reader = new ViewReader($this->connection, new ViewCatalog(new CustomDataSettings($config)));
        $translator = new Translator('en');
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource('yaml', dirname(__DIR__, 3).'/translations/bi_glossary.en.yaml', 'en', 'bi_glossary');
        $this->schema = new ViewSchema($reader, new BuiltinGlossaryCatalog($translator), new ReportingTimezone($config));
        $this->runner = new ViewQueryRunner($this->connection, $reader);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectDir);
    }

    public function testTheSchemaDescribesTypesTimeZonesAndOperators(): void
    {
        $columns = array_column($this->schema->columns('bi_anonymous_daily_v1'), null, 'name');

        self::assertSame(['website_token', 'event_day', 'page_group', 'event_count'], array_keys($columns));
        self::assertSame('date', $columns['event_day']['type']);
        self::assertSame('America/Chicago', $columns['event_day']['timezone'], 'The daily view labels days in the reporting timezone');
        self::assertSame('integer', $columns['event_count']['type']);
        self::assertNull($columns['page_group']['timezone']);
        self::assertSame(['eq', 'ne', 'in', 'starts_with', 'null'], $columns['page_group']['operators']);
        self::assertNotEmpty($columns['event_count']['description']);

        $custom = array_column($this->schema->columns('analytics_custom_events_v1'), null, 'name');
        self::assertSame('timestamp', $custom['created_at']['type']);
        self::assertSame('UTC', $custom['created_at']['timezone'], 'Stored times are UTC');
        self::assertSame(['id'], ViewSchema::uniqueOrder('analytics_custom_events_v1', array_keys($custom)));
        self::assertSame(array_keys($columns), ViewSchema::uniqueOrder('bi_anonymous_daily_v1', array_keys($columns)));
    }

    public function testAViewMissingFromTheDatabaseIsUnavailable(): void
    {
        try {
            $this->schema->columns('bi_anonymous_geo_events_v1');
            self::fail('A view that does not exist is unavailable.');
        } catch (ApiProblem $problem) {
            self::assertSame(503, $problem->status);
            self::assertSame('view_unavailable', $problem->problemCode);
        }
    }

    public function testPagesAreStableAndSayWhetherMoreRowsExist(): void
    {
        $first = $this->page([], 3, 0);
        $second = $this->page([], 3, 3);
        $last = $this->page([], 3, 6);

        self::assertTrue($first['more']);
        self::assertTrue($second['more']);
        self::assertFalse($last['more']);
        self::assertCount(7, [...$first['rows'], ...$second['rows'], ...$last['rows']]);
        self::assertSame(['website_token' => 'site-a', 'event_day' => '2026-10-01', 'page_group' => '/Blog', 'event_count' => 7], $first['rows'][0], 'Ordered by every column; counts are integers');
        self::assertSame([], $this->page([], 3, 7)['rows']);
    }

    /**
     * @param array<string, mixed> $query
     * @param list<int>            $counts
     */
    #[DataProvider('filters')]
    public function testFiltersSelectTheExpectedRows(array $query, array $counts): void
    {
        self::assertSame($counts, array_column($this->page($query, 100, 0)['rows'], 'event_count'));
    }

    public static function filters(): iterable
    {
        yield 'equals' => [['filter' => ['page_group' => '/blog']], [12, 9]];
        yield 'prefix is literal and follows equality' => [['filter' => ['page_group' => ['starts_with' => '/a%']]], [5]];
        yield 'prefix' => [['filter' => ['page_group' => ['starts_with' => '/b']]], [12, 9]];
        yield 'not equal excludes null' => [['filter' => ['website_token' => ['ne' => 'site-a']]], [30, 8]];
        yield 'null' => [['filter' => ['page_group' => ['null' => 'true']]], [8]];
        yield 'not null and date range' => [['filter' => ['page_group' => ['null' => 'false'], 'event_day' => ['gte' => '2026-10-02', 'lte' => '2026-10-03']]], [5, 6, 9]];
        yield 'in' => [['filter' => ['event_count' => ['in' => '5,30,99']]], [5, 30]];
        yield 'integer range' => [['filter' => ['event_count' => ['gt' => '8', 'lt' => '30']]], [12, 9]];
        yield 'sorted descending with ties broken' => [['sort' => '-event_count', 'filter' => ['website_token' => 'site-a']], [12, 9, 7, 6, 5]];
    }

    public function testFieldsAndTimestampsComeBackAsLabels(): void
    {
        $page = $this->page(['fields' => 'created_at,id', 'filter' => ['created_at' => ['gte' => '2026-10-01T14:00']], 'sort' => '-id'], 10, 0, 'analytics_custom_events_v1');

        self::assertSame([['created_at' => '2026-10-02T09:30:00', 'id' => 3], ['created_at' => '2026-10-01T14:00:00', 'id' => 2]], $page['rows']);
    }

    /** @param class-string<AbstractPlatform> $platform */
    #[DataProvider('platforms')]
    public function testOtherEnginesGetQuotedIdentifiersBoundValuesAndTheirLimitSyntax(string $platform, string $expected): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new $platform());
        $runner = new ViewQueryRunner($connection, $this->createStub(ViewReader::class));
        $query = new ViewQuery('bi_anonymous_daily_v1', ['event_day', 'event_count'], [
            ['column' => 'page_group', 'type' => 'STRING', 'operator' => 'starts_with', 'value' => '/blog'],
            ['column' => 'event_count', 'type' => 'INT64', 'operator' => 'in', 'value' => [5, 10]],
        ], [['column' => 'event_day', 'descending' => true]], 50, 100);

        $built = $runner->build($query, ['website_token', 'event_day']);

        self::assertSame($expected, $built['sql']);
        self::assertSame(['/blog', 5, 10], $built['params']);
        self::assertSame([ParameterType::STRING, ParameterType::INTEGER, ParameterType::INTEGER], $built['types']);
    }

    public static function platforms(): iterable
    {
        yield 'MySQL' => [MySQL80Platform::class, 'SELECT `event_day`, `event_count` FROM `bi_anonymous_daily_v1` WHERE SUBSTRING(`page_group` FROM 1 FOR 5) = ? AND `event_count` IN (?, ?) ORDER BY `event_day` DESC, `website_token` ASC LIMIT 51 OFFSET 100'];
        yield 'PostgreSQL' => [PostgreSQLPlatform::class, 'SELECT "event_day", "event_count" FROM "bi_anonymous_daily_v1" WHERE SUBSTRING("page_group" FROM 1 FOR 5) = ? AND "event_count" IN (?, ?) ORDER BY "event_day" DESC, "website_token" ASC LIMIT 51 OFFSET 100'];
        yield 'SQL Server' => [SQLServerPlatform::class, 'SELECT [event_day], [event_count] FROM [bi_anonymous_daily_v1] WHERE SUBSTRING([page_group], 1, 5) = ? AND [event_count] IN (?, ?) ORDER BY [event_day] DESC, [website_token] ASC OFFSET 100 ROWS FETCH NEXT 51 ROWS ONLY'];
    }

    public function testGroupingSumsTheReleasedRows(): void
    {
        $byDay = $this->page(['group' => 'event_day', 'sort' => '-event_count'], 10, 0);
        self::assertSame([
            ['event_day' => '2026-10-01', 'event_count' => 49],
            ['event_day' => '2026-10-02', 'event_count' => 20],
            ['event_day' => '2026-10-03', 'event_count' => 8],
        ], $byDay['rows']);
        self::assertFalse($byDay['more']);

        $filtered = $this->page(['group' => 'website_token,event_day', 'filter' => ['website_token' => 'site-a']], 10, 0);
        self::assertSame([
            ['website_token' => 'site-a', 'event_day' => '2026-10-01', 'event_count' => 19],
            ['website_token' => 'site-a', 'event_day' => '2026-10-02', 'event_count' => 20],
        ], $filtered['rows'], 'Filters apply before grouping; rows are ordered by the group columns');

        self::assertSame([['event_count' => 77]], $this->page(['group' => ''], 10, 0)['rows'], 'An empty group is the total');
        self::assertSame([['event_count' => null]], $this->page(['group' => '', 'filter' => ['website_token' => 'nobody']], 10, 0)['rows'], 'No released rows is null, never zero');

        $paged = [$this->page(['group' => 'page_group'], 2, 0), $this->page(['group' => 'page_group'], 2, 2), $this->page(['group' => 'page_group'], 2, 4)];
        self::assertTrue($paged[0]['more']);
        self::assertSame([null, '/Blog', '/a%b', '/abc', '/blog', '/pricing'], array_column([...$paged[0]['rows'], ...$paged[1]['rows'], ...$paged[2]['rows']], 'page_group'), 'Grouped pages are stable');
        self::assertFalse($paged[2]['more']);
    }

    public function testGroupedQueriesOnOtherEnginesSumAndSortOnTheSum(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $runner = new ViewQueryRunner($connection, $this->createStub(ViewReader::class));
        $query = new ViewQuery('bi_anonymous_daily_v1', ['event_day', 'event_count'], [], [['column' => 'event_count', 'descending' => true]], 10, 0, ['event_day']);

        self::assertSame(
            'SELECT "event_day", SUM("event_count") AS "event_count" FROM "bi_anonymous_daily_v1" GROUP BY "event_day" ORDER BY SUM("event_count") DESC, "event_day" ASC LIMIT 11',
            $runner->build($query, ['website_token', 'event_day', 'page_group', 'event_count'])['sql'],
        );
        $total = new ViewQuery('bi_anonymous_daily_v1', ['event_count'], [], [], 10, 0, []);
        self::assertSame('SELECT SUM("event_count") AS "event_count" FROM "bi_anonymous_daily_v1" LIMIT 11', $runner->build($total, ['website_token'])['sql']);
    }

    public function testTheSchemaUsesThePublishedGlossaryAndListsCodeLabels(): void
    {
        self::assertArrayNotHasKey('values', $this->schema->columns('bi_anonymous_daily_v1')[0], 'Queries read no glossary');
        $columns = array_column($this->schema->described('bi_anonymous_daily_v1'), null, 'name');
        self::assertNull($columns['page_group']['values'], 'Not a glossary dimension');
        self::assertNull($columns['website_token']['values'], 'Website codes come only from configuration until published');

        $this->connection->executeStatement('CREATE TABLE glossary (entry_type TEXT, subject TEXT, code TEXT, locale TEXT, label TEXT, description TEXT, sort_order INTEGER, is_default_locale INTEGER)');
        $this->connection->executeStatement("CREATE VIEW bi_glossary_columns_v1 AS SELECT subject AS object_name, code AS column_name, locale, label, locale AS label_locale, 0 AS is_fallback, description, is_default_locale FROM glossary WHERE entry_type = 'column'");
        $this->connection->executeStatement("CREATE VIEW bi_glossary_values_v1 AS SELECT subject AS dimension, code, locale, label, locale AS label_locale, 0 AS is_fallback, NULL AS group_label, description, sort_order, is_default_locale FROM glossary WHERE entry_type = 'value'");
        foreach ([
            ['column', 'bi_anonymous_daily_v1', 'page_group', 'en', 'Section', 'Edited by an administrator.', 10, 1],
            ['column', 'bi_anonymous_daily_v1', 'page_group', 'fr', 'Rubrique', 'Pas la langue par défaut.', 10, 0],
            ['value', 'website_token', 'site-b', 'en', 'Shop', 'shop.example', 20, 1],
            ['value', 'website_token', 'site-a', 'en', 'Blog', 'blog.example', 10, 1],
            ['value', 'utm_medium', 'email', 'en', 'Email newsletter', 'Scheduled campaign.', 10, 1],
        ] as $row) {
            $this->connection->insert('glossary', array_combine(['entry_type', 'subject', 'code', 'locale', 'label', 'description', 'sort_order', 'is_default_locale'], $row));
        }
        $reader = new ViewReader($this->connection, new ViewCatalog(new CustomDataSettings(new AggregateConfigLoader($this->projectDir, 'test'))));
        $translator = new Translator('en');
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource('yaml', dirname(__DIR__, 3).'/translations/bi_glossary.en.yaml', 'en', 'bi_glossary');
        $schema = new ViewSchema($reader, new BuiltinGlossaryCatalog($translator), new ReportingTimezone(new AggregateConfigLoader($this->projectDir, 'test')));

        $published = array_column($schema->described('bi_anonymous_daily_v1'), null, 'name');
        self::assertSame('Section', $published['page_group']['label']);
        self::assertSame('Edited by an administrator.', $published['page_group']['description']);
        self::assertSame($columns['event_count']['description'], $published['event_count']['description'], 'Columns the glossary does not list keep the built-in text');
        self::assertSame([
            ['code' => 'site-a', 'label' => 'Blog', 'description' => 'blog.example'],
            ['code' => 'site-b', 'label' => 'Shop', 'description' => 'shop.example'],
        ], $published['website_token']['values'], 'In glossary order, default locale only');
        self::assertFalse($published['website_token']['values_truncated']);

        $customDescribed = array_column($schema->described('analytics_custom_events_v1'), null, 'name');
        self::assertSame(['anonymous', 'enhanced'], array_column($customDescribed['privacy_mode']['values'], 'code'));
        self::assertSame([['code' => 'email', 'label' => 'Email newsletter', 'description' => 'Scheduled campaign.']], $customDescribed['utm_medium']['values']);
    }

    public function testOnlyCatalogViewsCanBeQueried(): void
    {
        $this->expectException(\LogicException::class);
        $this->runner->build(new ViewQuery('events', ['id'], [], [], 10, 0), ['id']);
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return array{rows: list<array<string, mixed>>, more: bool}
     */
    private function page(array $parameters, int $limit, int $offset, string $view = 'bi_anonymous_daily_v1'): array
    {
        $columns = $this->schema->columns($view);
        $query = ViewQuery::parse($view, [...$parameters, 'limit' => (string) $limit, 'offset' => (string) $offset], $columns, 100, 10000);
        $types = array_column($columns, 'catalog_type', 'name');
        $generator = $this->runner->rows($query, $types, ViewSchema::uniqueOrder($view, array_keys($types)));
        $rows = iterator_to_array($generator, false);

        return ['rows' => $rows, 'more' => $generator->getReturn()];
    }
}
