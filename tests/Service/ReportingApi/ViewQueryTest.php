<?php

declare(strict_types=1);

namespace App\Tests\Service\ReportingApi;

use App\Service\ReportingApi\ApiProblem;
use App\Service\ReportingApi\ViewQuery;
use App\Service\ReportingApi\ViewSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ViewQueryTest extends TestCase
{
    public function testDefaultsSelectEveryColumnOnTheFirstPage(): void
    {
        $query = $this->parse([]);

        self::assertSame(['website_token', 'event_day', 'page_group', 'event_count', 'is_fallback', 'share', 'created_at'], $query->fields);
        self::assertSame([], $query->filters);
        self::assertSame([], $query->sort);
        self::assertSame(100, $query->limit);
        self::assertSame(0, $query->offset);
    }

    public function testFiltersAreTypedForBinding(): void
    {
        $query = $this->parse([
            'filter' => [
                'page_group' => '/blog',
                'event_day' => ['gte' => '2026-10-01', 'lt' => '2026-11-01'],
                'event_count' => ['in' => '5,10,10'],
                'website_token' => ['in' => ['a', 'b'], 'null' => 'false'],
                'is_fallback' => 'true',
                'share' => ['gt' => '0.5'],
                'created_at' => ['gte' => '2026-10-01T13:00'],
            ],
            'fields' => 'event_day, event_count',
            'sort' => '-event_day,page_group',
            'limit' => '5000',
            'offset' => '200',
        ]);

        self::assertSame([
            ['column' => 'page_group', 'type' => 'STRING', 'operator' => 'eq', 'value' => '/blog'],
            ['column' => 'event_day', 'type' => 'DATE', 'operator' => 'gte', 'value' => '2026-10-01'],
            ['column' => 'event_day', 'type' => 'DATE', 'operator' => 'lt', 'value' => '2026-11-01'],
            ['column' => 'event_count', 'type' => 'INT64', 'operator' => 'in', 'value' => [5, 10]],
            ['column' => 'website_token', 'type' => 'STRING', 'operator' => 'in', 'value' => ['a', 'b']],
            ['column' => 'website_token', 'type' => 'STRING', 'operator' => 'null', 'value' => false],
            ['column' => 'is_fallback', 'type' => 'BOOL', 'operator' => 'eq', 'value' => true],
            ['column' => 'share', 'type' => 'FLOAT64', 'operator' => 'gt', 'value' => 0.5],
            ['column' => 'created_at', 'type' => 'TIMESTAMP', 'operator' => 'gte', 'value' => '2026-10-01 13:00:00'],
        ], $query->filters);
        self::assertSame(['event_day', 'event_count'], $query->fields);
        self::assertSame([['column' => 'event_day', 'descending' => true], ['column' => 'page_group', 'descending' => false]], $query->sort);
        self::assertSame(5000, $query->limit);
        self::assertSame(200, $query->offset);
    }

    /** @param array<string, mixed> $query */
    #[DataProvider('invalidQueries')]
    public function testInvalidQueriesFailBeforeTheDatabase(array $query, string $code, string $message): void
    {
        try {
            $this->parse($query);
            self::fail('The query must be refused.');
        } catch (ApiProblem $problem) {
            self::assertSame(400, $problem->status);
            self::assertSame($code, $problem->problemCode);
            self::assertStringContainsString($message, $problem->getMessage());
        }
    }

    public static function invalidQueries(): iterable
    {
        yield 'unknown parameter' => [['filters' => ['page_group' => 'x']], 'unknown_parameter', '"filters"'];
        yield 'unknown filter column' => [['filter' => ['visitor_id' => 'x']], 'unknown_column', 'no column named visitor_id'];
        yield 'unknown field' => [['fields' => 'event_day,secret'], 'unknown_column', 'secret'];
        yield 'unknown sort column' => [['sort' => '-nope'], 'unknown_column', 'nope'];
        yield 'duplicate field' => [['fields' => 'event_day,event_day'], 'invalid_parameter', 'more than once'];
        yield 'fields as array' => [['fields' => ['event_day']], 'invalid_parameter', 'comma-separated'];
        yield 'no fields' => [['fields' => ' , '], 'invalid_parameter', 'at least one column'];
        yield 'range on text' => [['filter' => ['page_group' => ['gt' => 'a']]], 'unsupported_operator', 'eq, ne, in, starts_with, null'];
        yield 'unknown operator' => [['filter' => ['event_count' => ['like' => '1']]], 'unsupported_operator', '"like"'];
        yield 'in on a number column' => [['filter' => ['share' => ['in' => '1,2']]], 'unsupported_operator', 'share'];
        yield 'starts_with on a date' => [['filter' => ['event_day' => ['starts_with' => '2026']]], 'unsupported_operator', 'event_day'];
        yield 'integer' => [['filter' => ['event_count' => '1.5']], 'invalid_filter_value', 'whole number'];
        yield 'integer injection' => [['filter' => ['event_count' => '1 OR 1=1']], 'invalid_filter_value', 'whole number'];
        yield 'date' => [['filter' => ['event_day' => '2026-02-30']], 'invalid_filter_value', 'YYYY-MM-DD'];
        yield 'timestamp with offset' => [['filter' => ['created_at' => ['gte' => '2026-10-01T13:00:00Z']]], 'invalid_filter_value', 'no offset'];
        yield 'timestamp with numeric offset' => [['filter' => ['created_at' => '2026-10-01T13:00:00-05:00']], 'invalid_filter_value', 'no offset'];
        yield 'timestamp hour' => [['filter' => ['created_at' => '2026-10-01T25:00']], 'invalid_filter_value', 'YYYY-MM-DDTHH:MM:SS'];
        yield 'boolean' => [['filter' => ['is_fallback' => 'yes']], 'invalid_filter_value', 'true or false'];
        yield 'number' => [['filter' => ['share' => 'NaN']], 'invalid_filter_value', 'a number'];
        yield 'null value' => [['filter' => ['page_group' => ['null' => 'maybe']]], 'invalid_filter_value', 'true or false'];
        yield 'empty starts_with' => [['filter' => ['page_group' => ['starts_with' => '']]], 'invalid_filter_value', 'non-empty'];
        yield 'too many in values' => [['filter' => ['website_token' => ['in' => implode(',', range(1, 101))]]], 'invalid_filter_value', 'at most 100'];
        yield 'nested value' => [['filter' => ['page_group' => ['eq' => ['a']]]], 'invalid_filter_value', 'one value'];
        yield 'list filter' => [['filter' => ['page_group' => ['a', 'b']]], 'invalid_parameter', 'filter[page_group]'];
        yield 'filter not a mapping' => [['filter' => 'x'], 'invalid_parameter', 'filter[column]'];
        yield 'limit zero' => [['limit' => '0'], 'invalid_parameter', 'from 1 to 10000'];
        yield 'limit above maximum' => [['limit' => '10001'], 'invalid_parameter', 'from 1 to 10000'];
        yield 'limit text' => [['limit' => 'all'], 'invalid_parameter', 'limit'];
        yield 'negative offset' => [['offset' => '-1'], 'invalid_parameter', 'offset'];
        yield 'text too long' => [['filter' => ['page_group' => str_repeat('a', 2049)]], 'invalid_filter_value', '2048'];
        yield 'grouping by the measure' => [['group' => 'event_day,event_count'], 'invalid_parameter', 'the measure'];
        yield 'grouped field outside the group' => [['group' => 'event_day', 'fields' => 'event_day,page_group,event_count'], 'invalid_parameter', 'not page_group'];
        yield 'grouped field missing a group column' => [['group' => 'event_day,page_group', 'fields' => 'event_day,event_count'], 'invalid_parameter', 'page_group is missing'];
        yield 'grouped sort outside the group' => [['group' => 'event_day', 'sort' => '-share'], 'invalid_parameter', 'not share'];
        yield 'unknown group column' => [['group' => 'visitor_id'], 'unknown_column', 'visitor_id'];
    }

    public function testGroupingDefaultsToTheGroupColumnsAndTheSum(): void
    {
        $query = $this->parse(['group' => 'event_day, page_group', 'sort' => '-event_count']);
        self::assertTrue($query->isGrouped());
        self::assertSame(['event_day', 'page_group'], $query->group);
        self::assertSame(['event_day', 'page_group', 'event_count'], $query->fields);
        self::assertSame(['event_day', 'page_group'], $query->uniqueOrder(['website_token', 'event_day']));

        $total = $this->parse(['group' => '']);
        self::assertSame([], $total->group);
        self::assertSame(['event_count'], $total->fields);

        self::assertFalse($this->parse([])->isGrouped());
        self::assertSame(['website_token'], $this->parse([])->uniqueOrder(['website_token']));
    }

    public function testOnlyAViewWithTheMeasureCanBeGrouped(): void
    {
        try {
            ViewQuery::parse('bi_dim_device_class_v1', ['group' => 'device_class'], [['name' => 'device_class', 'catalog_type' => 'STRING', 'type' => 'string', 'operators' => ViewSchema::OPERATORS['STRING']]], 100, 1000);
            self::fail('A label view has nothing to sum.');
        } catch (ApiProblem $problem) {
            self::assertSame('invalid_parameter', $problem->problemCode);
            self::assertStringContainsString('cannot be grouped', $problem->getMessage());
        }
    }

    public function testTheMaximumPageSizeIsTheSetting(): void
    {
        self::assertSame(250000, $this->parse(['limit' => '250000'], 250000)->limit);
    }

    /** @param array<string, mixed> $query */
    private function parse(array $query, int $maxLimit = 10000): ViewQuery
    {
        $columns = [];
        foreach (['website_token' => 'STRING', 'event_day' => 'DATE', 'page_group' => 'STRING', 'event_count' => 'INT64', 'is_fallback' => 'BOOL', 'share' => 'FLOAT64', 'created_at' => 'TIMESTAMP'] as $name => $type) {
            $columns[] = ['name' => $name, 'catalog_type' => $type, 'type' => ViewSchema::API_TYPES[$type], 'operators' => ViewSchema::OPERATORS[$type]];
        }

        return ViewQuery::parse('bi_anonymous_daily_v1', $query, $columns, 100, $maxLimit);
    }
}
