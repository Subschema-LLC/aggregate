<?php

declare(strict_types=1);

namespace App\Service\Mcp;

use App\Service\ReportingApi\ApiProblem;
use App\Service\ReportingApi\ReportingApiSettings;
use App\Service\ReportingApi\ViewQuery;
use App\Service\ReportingApi\ViewQueryRunner;
use App\Service\ReportingApi\ViewSchema;
use App\Service\ViewSync\ViewCatalog;
use App\Service\ViewSync\ViewSyncException;

/**
 * The MCP server's four read-only tools (docs/MCP.md#tools), over the same
 * view catalog, schema, query validation and runner as the reporting API:
 *
 * - list_views: the views the caller may read, with grain and time column.
 * - describe_view: a view's columns, labels, codes and the notes for reading it.
 * - query_view: a structured query, optionally grouped (event_count summed),
 *   validated by ViewQuery exactly like an API request. No SQL is accepted.
 * - get_coverage: how much of each day an event view releases, in bands.
 *
 * A tool never reads around the views: suppression, current-period
 * withholding and coverage stay the views' own. A failure the assistant can
 * act on (a wrong column, a view it may not read) is a tool result with
 * isError, so the model sees it and can correct the call.
 */
class McpTools
{
    public const NAMES = ['list_views', 'describe_view', 'query_view', 'get_coverage'];
    /** The most rows one call returns, below the API's own maximum when that is smaller. */
    public const MAX_ROWS = 1000;
    public const DEFAULT_ROWS = 100;
    /** The longest period get_coverage reports in one call. */
    public const MAX_COVERAGE_DAYS = 366;
    public const OPERATORS = ['eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'in', 'starts_with', 'null'];

    public function __construct(
        private readonly ViewSchema $schema,
        private readonly ViewQueryRunner $runner,
        private readonly ReportingApiSettings $settings,
    ) {
    }

    /**
     * The tool definitions for tools/list.
     *
     * @return list<array<string, mixed>>
     */
    public function definitions(): array
    {
        $readOnly = ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];
        $range = [
            'type' => 'object',
            'description' => 'Days to include, inclusive. For an hourly view the days are in the view\'s time zone.',
            'properties' => [
                'from' => ['type' => 'string', 'description' => 'First day, YYYY-MM-DD.'],
                'to' => ['type' => 'string', 'description' => 'Last day, YYYY-MM-DD.'],
            ],
            'additionalProperties' => false,
        ];

        return [
            [
                'name' => 'list_views',
                'title' => 'List reporting views',
                'description' => 'Lists the reporting views you may read, with what each row represents and its time column. Call this first.',
                'inputSchema' => ['type' => 'object', 'properties' => new \ArrayObject(), 'additionalProperties' => false],
                'annotations' => $readOnly,
            ],
            [
                'name' => 'describe_view',
                'title' => 'Describe a reporting view',
                'description' => 'Describes one view: each column\'s meaning, type, time zone and filter operators, the codes and labels of category columns, and notes on reading it correctly. Call it before querying a view.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['view' => ['type' => 'string', 'description' => 'A view name from list_views.']],
                    'required' => ['view'],
                    'additionalProperties' => false,
                ],
                'annotations' => $readOnly,
            ],
            [
                'name' => 'query_view',
                'title' => 'Query a reporting view',
                'description' => 'Reads rows from one view, or sums event_count by the columns in group_by. Filters are combined with AND. Small counts are already withheld by the view: a missing row means withheld or incomplete, never zero. Results come in pages of at most '.self::MAX_ROWS.' rows; use offset for the next page.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'view' => ['type' => 'string', 'description' => 'A view name from list_views.'],
                        'columns' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Columns to return. Default: every column, or the group_by columns and event_count when grouping.'],
                        'filters' => [
                            'type' => 'array',
                            'description' => 'Conditions on columns. describe_view lists the operators each column accepts.',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'column' => ['type' => 'string'],
                                    'operator' => ['type' => 'string', 'enum' => self::OPERATORS],
                                    'value' => ['description' => 'A string, number or boolean; a list for in; true for null to match empty values, false for non-empty ones.'],
                                ],
                                'required' => ['column', 'operator', 'value'],
                                'additionalProperties' => false,
                            ],
                        ],
                        'date_range' => $range,
                        'group_by' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Sum event_count for each combination of these columns. An empty list returns one total. Only views with event_count can be grouped.'],
                        'order_by' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Columns to sort by; prefix with - for descending, for example -event_count.'],
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_ROWS, 'default' => self::DEFAULT_ROWS],
                        'offset' => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
                    ],
                    'required' => ['view'],
                    'additionalProperties' => false,
                ],
                'annotations' => $readOnly,
            ],
            [
                'name' => 'get_coverage',
                'title' => 'Check how complete an event view is',
                'description' => 'Reports, for each day and website, the share of anonymous events an event view releases after withholding small counts: 90-100, 50-90, 10-50 or 0-10 percent. Use it before reporting totals or trends, so a low number reads as withheld rather than a real drop. Days missing from the result had too little traffic for a band.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'view' => ['type' => 'string', 'enum' => McpViewGuide::COVERAGE_VIEWS],
                        'date_range' => [...$range, 'description' => 'Days to report, inclusive, at most '.self::MAX_COVERAGE_DAYS.' days.'],
                        'website_token' => ['type' => 'string', 'description' => 'One website; all websites when left out.'],
                    ],
                    'required' => ['view', 'date_range'],
                    'additionalProperties' => false,
                ],
                'annotations' => $readOnly,
            ],
        ];
    }

    /**
     * Runs a tool and returns its result: the data, the view it read, the
     * rows returned, the group columns of a grouped query, and an error code
     * when the call failed in a way the assistant can act on.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{data: array<string, mixed>, view: ?string, rows: int, group_by: ?list<string>, error: ?string, message: ?string}
     */
    public function call(string $name, array $arguments, McpPrincipal $principal): array
    {
        $view = is_string($arguments['view'] ?? null) ? mb_substr($arguments['view'], 0, 128) : null;
        try {
            return match ($name) {
                'list_views' => $this->listViews($arguments, $principal),
                'describe_view' => $this->describeView($arguments, $principal),
                'query_view' => $this->queryView($arguments, $principal),
                'get_coverage' => $this->coverage($arguments, $principal),
                default => throw new McpToolError('unknown_tool', sprintf('There is no tool named %s. Use %s.', mb_substr($name, 0, 64), implode(', ', self::NAMES))),
            };
        } catch (McpToolError $error) {
            return self::failure($view, $error->errorCode, $error->getMessage());
        } catch (ApiProblem $problem) {
            return self::failure($view, $problem->problemCode, $problem->getMessage());
        } catch (ViewSyncException $error) {
            return self::failure($view, 'view_unavailable', $error->getMessage());
        } catch (\Doctrine\DBAL\Exception) {
            return self::failure($view, 'view_unavailable', sprintf('The view %s could not be read.', $view ?? ''));
        }
    }

    /** @return array{data: array<string, mixed>, view: ?string, rows: int, group_by: ?list<string>, error: string, message: string} */
    private static function failure(?string $view, string $code, string $message): array
    {
        return ['data' => ['error' => $code, 'message' => $message], 'view' => $view, 'rows' => 0, 'group_by' => null, 'error' => $code, 'message' => $message];
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{data: array<string, mixed>, view: ?string, rows: int, group_by: ?list<string>, error: null, message: null}
     */
    private function listViews(array $arguments, McpPrincipal $principal): array
    {
        self::assertArguments($arguments, []);
        $views = [];
        foreach ($principal->grant->readableViews($this->privateAllowed()) as $view) {
            $views[] = [
                'name' => $view,
                'description' => ViewCatalog::describe($view),
                'private' => ViewCatalog::isPrivate($view),
                'grain' => McpViewGuide::grain($view),
            ];
        }

        return self::success(['views' => $views, 'reporting_timezone' => $this->schema->reportingTimezone()], null, count($views));
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{data: array<string, mixed>, view: ?string, rows: int, group_by: ?list<string>, error: null, message: null}
     */
    private function describeView(array $arguments, McpPrincipal $principal): array
    {
        self::assertArguments($arguments, ['view'], ['view']);
        $view = $this->readableView($arguments['view'], $principal);

        return self::success($this->description($view), $view, 0);
    }

    /**
     * A view's description, as describe_view and the view resources give it.
     *
     * @return array<string, mixed>
     */
    public function description(string $view): array
    {
        $columns = $this->schema->described($view);
        $names = array_column($columns, 'name');

        return [
            'name' => $view,
            'description' => ViewCatalog::describe($view),
            'private' => ViewCatalog::isPrivate($view),
            'grain' => McpViewGuide::grain($view),
            'time_column' => McpViewGuide::timeColumn($names),
            'can_group' => in_array(ViewQuery::MEASURE, $names, true),
            'columns' => array_map(static fn (array $column): array => array_filter([
                'name' => $column['name'],
                'type' => $column['type'],
                'label' => $column['label'],
                'description' => $column['description'],
                'timezone' => $column['timezone'],
                'filter_operators' => $column['operators'],
                'values' => $column['values'],
                'values_truncated' => $column['values_truncated'] ?: null,
            ], static fn (mixed $value): bool => $value !== null), $columns),
            'notes' => McpViewGuide::notes($view),
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{data: array<string, mixed>, view: ?string, rows: int, group_by: ?list<string>, error: null, message: null}
     */
    private function queryView(array $arguments, McpPrincipal $principal): array
    {
        self::assertArguments($arguments, ['view', 'columns', 'filters', 'date_range', 'group_by', 'order_by', 'limit', 'offset'], ['view']);
        $view = $this->readableView($arguments['view'], $principal);
        $columns = $this->schema->columns($view);
        $names = array_column($columns, 'name');
        $types = array_column($columns, 'catalog_type', 'name');

        $parameters = ['filter' => []];
        if (array_key_exists('columns', $arguments)) {
            $parameters['fields'] = implode(',', self::stringList($arguments['columns'], 'columns'));
        }
        if (array_key_exists('group_by', $arguments)) {
            $parameters['group'] = implode(',', self::stringList($arguments['group_by'], 'group_by'));
        }
        if (array_key_exists('order_by', $arguments)) {
            $parameters['sort'] = implode(',', self::stringList($arguments['order_by'], 'order_by'));
        }
        foreach (self::filterList($arguments['filters'] ?? []) as [$column, $operator, $value]) {
            if (isset($parameters['filter'][$column][$operator])) {
                throw new McpToolError('invalid_argument', sprintf('filters name %s with %s twice. Combine them into one filter.', $column, $operator));
            }
            $parameters['filter'][$column][$operator] = $value;
        }
        if (array_key_exists('date_range', $arguments)) {
            $timeColumn = McpViewGuide::timeColumn($names) ?? throw new McpToolError('invalid_argument', sprintf('%s has no time column, so date_range does not apply. Leave it out.', $view));
            foreach (self::dateRange($arguments['date_range'], $types[$timeColumn] === 'TIMESTAMP') as $operator => $value) {
                if (isset($parameters['filter'][$timeColumn][$operator])) {
                    throw new McpToolError('invalid_argument', sprintf('date_range and filters both limit %s with %s. Use one of them.', $timeColumn, $operator));
                }
                $parameters['filter'][$timeColumn][$operator] = $value;
            }
        }
        $limit = self::integer($arguments['limit'] ?? self::DEFAULT_ROWS, 'limit', 1, $this->maxRows());
        $offset = self::integer($arguments['offset'] ?? 0, 'offset', 0, ViewQuery::MAX_OFFSET);
        $parameters['limit'] = (string) $limit;
        $parameters['offset'] = (string) $offset;

        $query = ViewQuery::parse($view, $parameters, $columns, self::DEFAULT_ROWS, $this->maxRows());
        $generator = $this->runner->rows($query, $types, ViewSchema::uniqueOrder($view, $names));
        $rows = iterator_to_array($generator, false);
        $more = $generator->getReturn();

        $data = [
            'view' => $view,
            'columns' => $query->fields,
            'rows' => $rows,
            'returned' => count($rows),
            'has_more' => $more,
            'next_offset' => $more ? $offset + $limit : null,
        ];
        if ($query->isGrouped()) {
            $data['group_by'] = $query->group;
            $data['note'] = 'event_count is the sum of the released rows in each group. Withheld cells are not in any sum.';
            if ($query->group === [] && ($rows[0][ViewQuery::MEASURE] ?? null) === null) {
                $data['note'] = 'No released rows match. That means nothing was released for these conditions, not that nothing happened.';
            }
        }
        if (in_array($view, McpViewGuide::COVERAGE_VIEWS, true)) {
            $data['coverage'] = $this->coverageSummary($view, $arguments, $principal);
        }

        return self::success($data, $view, count($rows), $query->group);
    }

    /**
     * A summary of the coverage bands for the days a query covered, or a
     * pointer to get_coverage when the query named no days.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>|string
     */
    private function coverageSummary(string $view, array $arguments, McpPrincipal $principal): array|string
    {
        if (!array_key_exists('date_range', $arguments)) {
            return 'Some cells may be withheld. Call get_coverage for the days you report to see how complete they are.';
        }
        if (!$principal->grant->mayRead(McpViewGuide::COVERAGE_VIEW, $this->privateAllowed())) {
            return 'Coverage is not available: this credential may not read '.McpViewGuide::COVERAGE_VIEW.'.';
        }
        $website = null;
        foreach (self::filterList($arguments['filters'] ?? []) as [$column, $operator, $value]) {
            if ($column === 'website_token' && $operator === 'eq') {
                $website = $value;
            }
        }
        try {
            $rows = $this->coverageRows($view, $arguments['date_range'], $website);
        } catch (\Throwable) {
            // The rows were already answered; a missing summary must not fail the call.
            return 'Coverage could not be read for these days. Call get_coverage to check them.';
        }
        $bands = array_count_values(array_map('strval', array_column($rows, 'coverage_band')));
        ksort($bands);

        return [
            'days_with_a_band' => count($rows),
            'bands' => $bands === [] ? new \ArrayObject() : $bands,
            'note' => 'Counts of days (per website) by the share of their anonymous events this view releases. Days without a band had too little traffic to report one.',
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{data: array<string, mixed>, view: ?string, rows: int, group_by: ?list<string>, error: null, message: null}
     */
    private function coverage(array $arguments, McpPrincipal $principal): array
    {
        self::assertArguments($arguments, ['view', 'date_range', 'website_token'], ['view', 'date_range']);
        if (!in_array($arguments['view'], McpViewGuide::COVERAGE_VIEWS, true)) {
            throw new McpToolError('invalid_argument', 'Coverage is reported for '.implode(', ', McpViewGuide::COVERAGE_VIEWS).'.');
        }
        $view = $this->readableView($arguments['view'], $principal);
        $this->readableView(McpViewGuide::COVERAGE_VIEW, $principal);
        $website = $arguments['website_token'] ?? null;
        if ($website !== null && (!is_string($website) || $website === '')) {
            throw new McpToolError('invalid_argument', 'website_token must be a website token from the website_token codes describe_view lists.');
        }
        $rows = $this->coverageRows($view, $arguments['date_range'], $website);

        return self::success([
            'view' => $view,
            'rows' => $rows,
            'returned' => count($rows),
            'note' => 'coverage_band is the percentage of the day\'s anonymous events '.$view.' releases. Days missing here had too little traffic for a band; they may be entirely withheld.',
        ], $view, count($rows));
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws McpToolError|ApiProblem
     */
    private function coverageRows(string $view, mixed $range, ?string $website): array
    {
        $bounds = self::dateRange($range, false);
        if (!isset($bounds['gte'], $bounds['lte'])) {
            throw new McpToolError('invalid_argument', 'date_range needs both from and to.');
        }
        $days = (int) (new \DateTimeImmutable($bounds['gte']))->diff(new \DateTimeImmutable($bounds['lte']))->format('%a') + 1;
        if ($days > self::MAX_COVERAGE_DAYS) {
            throw new McpToolError('invalid_argument', sprintf('date_range covers %d days; get_coverage reports at most %d at a time.', $days, self::MAX_COVERAGE_DAYS));
        }
        $columns = $this->schema->columns(McpViewGuide::COVERAGE_VIEW);
        $filter = ['view_name' => ['eq' => $view], 'event_day' => $bounds];
        if ($website !== null) {
            $filter['website_token'] = ['eq' => $website];
        }
        $query = ViewQuery::parse(McpViewGuide::COVERAGE_VIEW, [
            'filter' => $filter,
            'fields' => 'website_token,event_day,coverage_band',
            'sort' => 'event_day,website_token',
            'limit' => (string) self::MAX_ROWS,
        ], $columns, self::MAX_ROWS, self::MAX_ROWS);
        $types = array_column($columns, 'catalog_type', 'name');
        $generator = $this->runner->rows($query, $types, ViewSchema::uniqueOrder(McpViewGuide::COVERAGE_VIEW, array_keys($types)));

        return iterator_to_array($generator, false);
    }

    /** @throws McpToolError when the caller may not read the view */
    private function readableView(mixed $view, McpPrincipal $principal): string
    {
        if (!is_string($view) || $view === '') {
            throw new McpToolError('invalid_argument', 'view must be a view name from list_views.');
        }
        if (!ViewCatalog::isKnown($view)) {
            throw new McpToolError('unknown_view', sprintf('%s is not a reporting view. list_views lists the views you may read.', mb_substr($view, 0, 100)));
        }
        if (ViewCatalog::isPrivate($view) && !$this->privateAllowed()) {
            throw new McpToolError('private_views_disabled', sprintf('%s is a private view, and private views are turned off on this installation.', $view));
        }
        if (!$principal->grant->mayRead($view, $this->privateAllowed())) {
            throw new McpToolError('view_not_permitted', sprintf('You may not read %s. list_views lists the views you may read; an administrator can grant more.', $view));
        }

        return $view;
    }

    private function privateAllowed(): bool
    {
        return $this->settings->privateViewsAllowed();
    }

    private function maxRows(): int
    {
        try {
            return min(self::MAX_ROWS, $this->settings->maxPageSize());
        } catch (\InvalidArgumentException|\RuntimeException) {
            throw new McpToolError('configuration_invalid', 'The reporting settings on this installation are invalid. An administrator needs to correct them.');
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>|null    $groupBy
     *
     * @return array{data: array<string, mixed>, view: ?string, rows: int, group_by: ?list<string>, error: null, message: null}
     */
    private static function success(array $data, ?string $view, int $rows, ?array $groupBy = null): array
    {
        return ['data' => $data, 'view' => $view, 'rows' => $rows, 'group_by' => $groupBy, 'error' => null, 'message' => null];
    }

    /**
     * @param array<string, mixed> $arguments
     * @param list<string>         $allowed
     * @param list<string>         $required
     */
    private static function assertArguments(array $arguments, array $allowed, array $required = []): void
    {
        foreach (array_keys($arguments) as $name) {
            if (!in_array((string) $name, $allowed, true)) {
                throw new McpToolError('invalid_argument', sprintf('"%s" is not an argument of this tool.%s', mb_substr((string) $name, 0, 64), $allowed === [] ? ' It takes none.' : ' It takes '.implode(', ', $allowed).'.'));
            }
        }
        foreach ($required as $name) {
            if (!array_key_exists($name, $arguments)) {
                throw new McpToolError('invalid_argument', sprintf('%s is required.', $name));
            }
        }
    }

    /** @return list<string> */
    private static function stringList(mixed $value, string $name): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new McpToolError('invalid_argument', sprintf('%s must be a list of column names.', $name));
        }
        foreach ($value as $item) {
            if (!is_string($item) || $item === '' || str_contains($item, ',')) {
                throw new McpToolError('invalid_argument', sprintf('%s must be a list of column names.', $name));
            }
        }

        return $value;
    }

    /**
     * Filters as [column, operator, value], with each value written the way
     * the API's query string carries it, for ViewQuery to check.
     *
     * @return list<array{0: string, 1: string, 2: string|list<string>}>
     */
    private static function filterList(mixed $filters): array
    {
        if (!is_array($filters) || !array_is_list($filters)) {
            throw new McpToolError('invalid_argument', 'filters must be a list of {column, operator, value} objects.');
        }
        if (count($filters) > ViewQuery::MAX_FILTERS) {
            throw new McpToolError('invalid_argument', sprintf('A query may have at most %d filters.', ViewQuery::MAX_FILTERS));
        }
        $list = [];
        foreach ($filters as $filter) {
            if (!is_array($filter) || array_diff(array_keys($filter), ['column', 'operator', 'value']) !== [] || !is_string($filter['column'] ?? null) || !is_string($filter['operator'] ?? null) || !array_key_exists('value', $filter)) {
                throw new McpToolError('invalid_argument', 'Each filter must be an object with column, operator and value.');
            }
            if (!in_array($filter['operator'], self::OPERATORS, true)) {
                throw new McpToolError('invalid_argument', sprintf('"%s" is not a filter operator. Use %s.', mb_substr($filter['operator'], 0, 32), implode(', ', self::OPERATORS)));
            }
            $value = $filter['operator'] === 'in'
                ? array_map(static fn (mixed $item): string => self::scalar($item, $filter['column']), is_array($filter['value']) && array_is_list($filter['value']) ? $filter['value'] : throw new McpToolError('invalid_argument', sprintf('The in filter on %s needs a list of values.', $filter['column'])))
                : self::scalar($filter['value'], $filter['column']);
            $list[] = [$filter['column'], $filter['operator'], $value];
        }

        return $list;
    }

    private static function scalar(mixed $value, string $column): string
    {
        return match (true) {
            is_string($value) => $value,
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            is_float($value) && is_finite($value) => rtrim(rtrim(sprintf('%.10F', $value), '0'), '.'),
            default => throw new McpToolError('invalid_argument', sprintf('The filter on %s needs a string, number or boolean value.', $column)),
        };
    }

    /**
     * date_range as filter operators on a time column: from is gte its day;
     * to is lte its day, or for an hourly column lt the next day, so the last
     * day's hours are included.
     *
     * @return array<string, string>
     */
    private static function dateRange(mixed $range, bool $hourly): array
    {
        if (!is_array($range) || array_diff(array_keys($range), ['from', 'to']) !== [] || $range === []) {
            throw new McpToolError('invalid_argument', 'date_range must be an object with from and/or to, each a day as YYYY-MM-DD.');
        }
        $bounds = [];
        foreach (['from', 'to'] as $key) {
            if (!array_key_exists($key, $range)) {
                continue;
            }
            $day = $range[$key];
            if (!is_string($day) || preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $day, $matches) !== 1 || !checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])) {
                throw new McpToolError('invalid_argument', sprintf('date_range.%s must be a day as YYYY-MM-DD.', $key));
            }
            if ($key === 'from') {
                $bounds['gte'] = $day;
            } elseif ($hourly) {
                $bounds['lt'] = (new \DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d');
            } else {
                $bounds['lte'] = $day;
            }
        }
        if (isset($bounds['gte'], $range['to']) && $range['to'] < $bounds['gte']) {
            throw new McpToolError('invalid_argument', 'date_range.to is before date_range.from.');
        }

        return $bounds;
    }

    private static function integer(mixed $value, string $name, int $minimum, int $maximum): int
    {
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new McpToolError('invalid_argument', sprintf('%s must be a whole number from %d to %d.', $name, $minimum, $maximum));
        }

        return $value;
    }
}
