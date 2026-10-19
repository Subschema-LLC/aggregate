<?php

declare(strict_types=1);

namespace App\Service\ReportingApi;

/**
 * One validated request for rows of a view: the columns to return, the
 * filters, the order and the page. Built from the query string by parse(),
 * which checks every column name, operator and value against the view's
 * columns before anything reaches the database, and fails with a 400
 * problem naming the parameter. Values are typed for binding, never
 * inserted into SQL.
 *
 *     filter[col]=value            equals
 *     filter[col][op]=value        eq, ne, gt, gte, lt, lte, in, starts_with, null
 *     fields=a,b,c                 columns to return, in this order
 *     sort=-a,b                    order; "-" for descending
 *     limit=100&offset=0           page
 *     group=a,b                    one row per combination of a and b, with
 *                                  event_count summed; group= (empty) is the total
 *
 * Grouping sums released rows only: the view has already withheld small
 * cells, so a sum can never contain them. It needs a view with the
 * event_count measure, which cannot itself be a group column; fields and
 * sort may then name only the group columns and event_count.
 */
final readonly class ViewQuery
{
    public const PARAMETERS = ['filter', 'fields', 'sort', 'limit', 'offset', 'group'];
    /** The measure grouping sums. */
    public const MEASURE = 'event_count';
    public const MAX_IN_VALUES = 100;
    public const MAX_FILTERS = 50;
    public const MAX_TEXT = 2048;
    public const MAX_OFFSET = 1000000000;

    /**
     * @param list<string>                                                                   $fields  columns to return
     * @param list<array{column: string, type: string, operator: string, value: mixed}>      $filters typed values; a list for "in", a bool for "null"
     * @param list<array{column: string, descending: bool}>                                  $sort    the caller's order, before the tie-breaking columns
     * @param list<string>|null                                                              $group   group columns, [] for one total, or null for rows
     */
    public function __construct(
        public string $view,
        public array $fields,
        public array $filters,
        public array $sort,
        public int $limit,
        public int $offset,
        public ?array $group = null,
    ) {
    }

    public function isGrouped(): bool
    {
        return $this->group !== null;
    }

    /**
     * Columns that make each result row unique: the group columns when
     * grouped, otherwise the view's own unique order.
     *
     * @param list<string> $viewUniqueOrder ViewSchema::uniqueOrder for the view
     *
     * @return list<string>
     */
    public function uniqueOrder(array $viewUniqueOrder): array
    {
        return $this->group ?? $viewUniqueOrder;
    }

    /**
     * @param array<string, mixed>                                $query   the request's query parameters
     * @param list<array{name: string, catalog_type: string, operators: list<string>}> $columns the view's columns (ViewSchema::columns)
     *
     * @throws ApiProblem for an unknown parameter, column or operator, or an invalid value
     */
    public static function parse(string $view, array $query, array $columns, int $defaultLimit, int $maxLimit): self
    {
        $unknown = array_diff(array_map('strval', array_keys($query)), self::PARAMETERS);
        if ($unknown !== []) {
            throw ApiProblem::badRequest('unknown_parameter', sprintf('"%s" is not a parameter of this endpoint. Use %s.', (string) reset($unknown), implode(', ', self::PARAMETERS)));
        }
        $byName = [];
        foreach ($columns as $column) {
            $byName[$column['name']] = $column;
        }
        $group = null;
        if (array_key_exists('group', $query)) {
            if (($byName[self::MEASURE]['catalog_type'] ?? null) !== 'INT64') {
                throw ApiProblem::badRequest('invalid_parameter', sprintf('%s has no %s column to sum, so it cannot be grouped. Read its rows instead.', $view, self::MEASURE));
            }
            $group = self::columnList($query['group'] ?? '', 'group', $view, $byName);
            if (in_array(self::MEASURE, $group, true)) {
                throw ApiProblem::badRequest('invalid_parameter', sprintf('group cannot include %s: it is the measure that grouping sums.', self::MEASURE));
            }
        }
        $outputs = $group === null ? array_keys($byName) : [...$group, self::MEASURE];
        $fields = isset($query['fields']) ? self::columnList($query['fields'], 'fields', $view, $byName) : $outputs;
        if ($fields === []) {
            throw ApiProblem::badRequest('invalid_parameter', 'fields must name at least one column.');
        }
        self::assertGroupedColumns($fields, $outputs, 'fields', $group);
        foreach ($group ?? [] as $column) {
            if (!in_array($column, $fields, true)) {
                throw ApiProblem::badRequest('invalid_parameter', sprintf('With group, fields must include every group column; %s is missing.', $column));
            }
        }
        $sort = [];
        if (isset($query['sort'])) {
            foreach (self::columnList($query['sort'], 'sort', $view, $byName, true) as $entry) {
                $sort[] = ['column' => ltrim($entry, '-'), 'descending' => str_starts_with($entry, '-')];
            }
            self::assertGroupedColumns(array_column($sort, 'column'), $outputs, 'sort', $group);
        }

        return new self(
            $view,
            $fields,
            self::filters($query['filter'] ?? [], $view, $byName),
            $sort,
            self::integer($query['limit'] ?? null, 'limit', 1, $maxLimit) ?? $defaultLimit,
            self::integer($query['offset'] ?? null, 'offset', 0, self::MAX_OFFSET) ?? 0,
            $group,
        );
    }

    /**
     * @param list<string>      $names
     * @param list<string>      $outputs the columns a grouped result has
     * @param list<string>|null $group
     */
    private static function assertGroupedColumns(array $names, array $outputs, string $parameter, ?array $group): void
    {
        if ($group === null) {
            return;
        }
        foreach ($names as $name) {
            if (!in_array($name, $outputs, true)) {
                throw ApiProblem::badRequest('invalid_parameter', sprintf('With group, %s may name only the group columns and %s, not %s. Add %s to group or leave it out.', $parameter, self::MEASURE, $name, $name));
            }
        }
    }

    /**
     * @param array<string, array{name: string, catalog_type: string, operators: list<string>}> $columns
     *
     * @return list<string>
     */
    private static function columnList(mixed $value, string $parameter, string $view, array $columns, bool $signed = false): array
    {
        if (!is_string($value)) {
            throw ApiProblem::badRequest('invalid_parameter', sprintf('%s must be a comma-separated list of column names.', $parameter));
        }
        $names = [];
        foreach (explode(',', $value) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            $name = $signed ? (string) preg_replace('/^-/', '', $entry) : $entry;
            if (!isset($columns[$name])) {
                throw self::unknownColumn($view, $name);
            }
            if (isset($names[$name])) {
                throw ApiProblem::badRequest('invalid_parameter', sprintf('%s names %s more than once.', $parameter, $name));
            }
            $names[$name] = $entry;
        }

        return array_values($names);
    }

    /**
     * @param array<string, array{name: string, catalog_type: string, operators: list<string>}> $columns
     *
     * @return list<array{column: string, type: string, operator: string, value: mixed}>
     */
    private static function filters(mixed $raw, string $view, array $columns): array
    {
        if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            throw ApiProblem::badRequest('invalid_parameter', 'Write filters as filter[column]=value or filter[column][operator]=value.');
        }
        $filters = [];
        foreach ($raw as $name => $spec) {
            $name = (string) $name;
            $column = $columns[$name] ?? throw self::unknownColumn($view, $name);
            $operations = is_array($spec) ? $spec : ['eq' => $spec];
            if ($operations === [] || array_is_list($operations)) {
                throw ApiProblem::badRequest('invalid_parameter', sprintf('Write the filter on %1$s as filter[%1$s]=value or filter[%1$s][operator]=value.', $name));
            }
            foreach ($operations as $operator => $value) {
                $operator = (string) $operator;
                if (!in_array($operator, $column['operators'], true)) {
                    throw ApiProblem::badRequest('unsupported_operator', sprintf('%s does not accept the operator "%s". It accepts %s.', $name, $operator, implode(', ', $column['operators'])));
                }
                $filters[] = [
                    'column' => $name,
                    'type' => $column['catalog_type'],
                    'operator' => $operator,
                    'value' => self::filterValue($name, $column['catalog_type'], $operator, $value),
                ];
                if (count($filters) > self::MAX_FILTERS) {
                    throw ApiProblem::badRequest('invalid_parameter', sprintf('A request may have at most %d filters.', self::MAX_FILTERS));
                }
            }
        }

        return $filters;
    }

    private static function filterValue(string $column, string $type, string $operator, mixed $value): mixed
    {
        if ($operator === 'null') {
            return match (is_string($value) ? strtolower($value) : null) {
                'true', '1' => true,
                'false', '0' => false,
                default => throw self::invalidValue($column, 'null takes true or false'),
            };
        }
        if ($operator === 'in') {
            $values = is_array($value) ? $value : (is_string($value) ? explode(',', $value) : null);
            if ($values === null || $values === [] || !array_is_list($values)) {
                throw self::invalidValue($column, 'in takes a comma-separated list or repeated filter['.$column.'][in][] values');
            }
            if (count($values) > self::MAX_IN_VALUES) {
                throw self::invalidValue($column, sprintf('in takes at most %d values', self::MAX_IN_VALUES));
            }

            return array_values(array_unique(array_map(static fn (mixed $item): mixed => self::scalar($column, $type, $item), $values), SORT_REGULAR));
        }
        if ($operator === 'starts_with' && (!is_string($value) || $value === '')) {
            throw self::invalidValue($column, 'starts_with takes non-empty text');
        }

        return self::scalar($column, $type, $value);
    }

    /** A filter value converted to the column's type, ready to bind. */
    private static function scalar(string $column, string $type, mixed $value): mixed
    {
        if (!is_string($value)) {
            throw self::invalidValue($column, 'each filter takes one value');
        }
        switch ($type) {
            case 'INT64':
                if (preg_match('/^-?[0-9]{1,18}$/D', $value) !== 1) {
                    throw self::invalidValue($column, 'expected a whole number');
                }

                return (int) $value;
            case 'FLOAT64':
                if (!is_numeric($value) || !is_finite((float) $value) || preg_match('/^\s|\s$/', $value) === 1) {
                    throw self::invalidValue($column, 'expected a number');
                }

                return (float) $value;
            case 'BOOL':
                return match (strtolower($value)) {
                    'true', '1' => true,
                    'false', '0' => false,
                    default => throw self::invalidValue($column, 'expected true or false'),
                };
            case 'DATE':
                if (preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $value, $matches) !== 1 || !checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])) {
                    throw self::invalidValue($column, 'expected a date as YYYY-MM-DD');
                }

                return $value;
            case 'TIMESTAMP':
                if (preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})(?:[T ]([0-9]{2}):([0-9]{2})(?::([0-9]{2}))?)?$/D', $value, $matches) !== 1
                    || !checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])
                    || (int) ($matches[4] ?? 0) > 23 || (int) ($matches[5] ?? 0) > 59 || (int) ($matches[6] ?? 0) > 59) {
                    $hint = preg_match('/(?:Z|[+-][0-9]{2}:?[0-9]{2})$/D', $value) === 1
                        ? 'times are compared with the view\'s labels, which carry no offset; send the local time without Z or an offset'
                        : 'expected YYYY-MM-DD or YYYY-MM-DDTHH:MM:SS';
                    throw self::invalidValue($column, $hint);
                }

                return sprintf('%s-%s-%s %s:%s:%s', $matches[1], $matches[2], $matches[3], $matches[4] ?? '00', $matches[5] ?? '00', ($matches[6] ?? '') === '' ? '00' : $matches[6]);
            default:
                if (mb_strlen($value) > self::MAX_TEXT || !mb_check_encoding($value, 'UTF-8')) {
                    throw self::invalidValue($column, sprintf('expected UTF-8 text of at most %d characters', self::MAX_TEXT));
                }

                return $value;
        }
    }

    private static function integer(mixed $value, string $parameter, int $minimum, int $maximum): ?int
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || preg_match('/^[0-9]{1,10}$/D', $value) !== 1 || (int) $value < $minimum || (int) $value > $maximum) {
            throw ApiProblem::badRequest('invalid_parameter', sprintf('%s must be a whole number from %s to %s.', $parameter, number_format($minimum, 0, '.', ''), number_format($maximum, 0, '.', '')));
        }

        return (int) $value;
    }

    private static function unknownColumn(string $view, string $column): ApiProblem
    {
        return ApiProblem::badRequest('unknown_column', sprintf('%s has no column named %s.', $view, mb_substr($column, 0, 100)));
    }

    private static function invalidValue(string $column, string $expected): ApiProblem
    {
        return ApiProblem::badRequest('invalid_filter_value', sprintf('The filter on %s has an invalid value: %s.', $column, $expected));
    }
}
