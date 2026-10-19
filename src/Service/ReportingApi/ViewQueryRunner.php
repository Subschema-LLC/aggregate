<?php

declare(strict_types=1);

namespace App\Service\ReportingApi;

use App\Service\ViewSync\ViewCatalog;
use App\Service\ViewSync\ViewReader;
use App\Service\ViewSync\ViewSyncException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractPlatform;

/**
 * Runs a validated ViewQuery as one portable SELECT over a catalog view:
 * identifiers quoted by the platform, every value bound, the platform's own
 * limit syntax, and an order that ends with columns making each row unique
 * so offset paging is stable. Rows are streamed (ViewReader::select) and
 * converted to the JSON form of each column's catalog type. A grouped query
 * selects the group columns and SUM(event_count), grouped by those columns,
 * which together make each result row unique.
 *
 * Only views in ViewCatalog can be named; the caller has already checked
 * that the token may read the view.
 */
class ViewQueryRunner
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ViewReader $reader,
    ) {
    }

    /**
     * The SQL, parameters and types for a page of the query, reading one row
     * beyond the limit so the caller knows whether more rows exist.
     *
     * @param list<string> $uniqueOrder columns that make each row unique (ViewSchema::uniqueOrder)
     *
     * @return array{sql: string, params: list<mixed>, types: list<ParameterType>}
     */
    public function build(ViewQuery $query, array $uniqueOrder): array
    {
        if (!ViewCatalog::isKnown($query->view)) {
            throw new \LogicException('Only catalog views can be queried.');
        }
        $platform = $this->connection->getDatabasePlatform();
        $quote = static fn (string $name): string => $platform->quoteSingleIdentifier($name);
        $params = [];
        $types = [];
        $conditions = [];
        foreach ($query->filters as $filter) {
            $conditions[] = $this->condition($platform, $quote($filter['column']), $filter, $params, $types);
        }
        // The measure is summed, and sorted on as the sum, when grouped.
        $expression = static fn (string $column): string => $query->isGrouped() && $column === ViewQuery::MEASURE
            ? 'SUM('.$quote($column).')'
            : $quote($column);
        $order = [];
        $ordered = [];
        foreach ($query->sort as $entry) {
            $order[] = $expression($entry['column']).($entry['descending'] ? ' DESC' : ' ASC');
            $ordered[$entry['column']] = true;
        }
        foreach ($query->uniqueOrder($uniqueOrder) as $column) {
            if (!isset($ordered[$column])) {
                $order[] = $quote($column).' ASC';
            }
        }
        $select = array_map(
            static fn (string $column): string => $query->isGrouped() && $column === ViewQuery::MEASURE ? $expression($column).' AS '.$quote($column) : $quote($column),
            $query->fields,
        );

        $sql = 'SELECT '.implode(', ', $select)
            .' FROM '.$quote($query->view)
            .($conditions === [] ? '' : ' WHERE '.implode(' AND ', $conditions))
            .($query->group === null || $query->group === [] ? '' : ' GROUP BY '.implode(', ', array_map($quote, $query->group)))
            .($order === [] ? '' : ' ORDER BY '.implode(', ', $order));

        return [
            'sql' => $platform->modifyLimitQuery($sql, $query->limit + 1, $query->offset),
            'params' => $params,
            'types' => $types,
        ];
    }

    /**
     * Streams a page of rows, each an associative array of JSON-ready values,
     * then returns whether more rows exist beyond the page.
     *
     * @param array<string, string> $types catalog type by column
     * @param list<string>          $uniqueOrder
     *
     * @return \Generator<int, array<string, mixed>, mixed, bool>
     *
     * @throws ViewSyncException naming the view and column when a value does not fit its type
     * @throws \Doctrine\DBAL\Exception when the query fails
     */
    public function rows(ViewQuery $query, array $types, array $uniqueOrder): \Generator
    {
        $built = $this->build($query, $uniqueOrder);
        $returned = 0;
        foreach ($this->reader->select($built['sql'], $built['params'], $built['types']) as $values) {
            if ($returned === $query->limit) {
                return true;
            }
            $row = [];
            foreach ($query->fields as $index => $column) {
                $row[$column] = self::value($values[$index] ?? null, $types[$column], $query->view, $column);
            }
            ++$returned;
            yield $row;
        }

        return false;
    }

    /** A database value in the JSON form of its catalog type. @throws ViewSyncException */
    public static function value(mixed $value, string $type, string $view, string $column): mixed
    {
        $converted = ViewReader::convert($value, $type, $view, $column);

        return match (true) {
            $converted === null => null,
            $type === 'INT64' => filter_var($converted, FILTER_VALIDATE_INT) !== false ? (int) $converted : $converted,
            // ViewReader writes Y-m-d H:i:s.u; the API returns the label without an offset.
            $type === 'TIMESTAMP' => str_replace(' ', 'T', str_ends_with((string) $converted, '.000000') ? substr((string) $converted, 0, -7) : (string) $converted),
            default => $converted,
        };
    }

    /**
     * @param array{column: string, type: string, operator: string, value: mixed} $filter
     * @param list<mixed>                                                        $params
     * @param list<ParameterType>                                                $types
     */
    private function condition(AbstractPlatform $platform, string $column, array $filter, array &$params, array &$types): string
    {
        $bind = static function (mixed $value) use ($filter, &$params, &$types): string {
            $params[] = $value;
            $types[] = match ($filter['type']) {
                'INT64' => ParameterType::INTEGER,
                'BOOL' => ParameterType::BOOLEAN,
                default => ParameterType::STRING,
            };

            return '?';
        };

        return match ($filter['operator']) {
            'eq' => $column.' = '.$bind($filter['value']),
            'ne' => $column.' <> '.$bind($filter['value']),
            'gt' => $column.' > '.$bind($filter['value']),
            'gte' => $column.' >= '.$bind($filter['value']),
            'lt' => $column.' < '.$bind($filter['value']),
            'lte' => $column.' <= '.$bind($filter['value']),
            'in' => $column.' IN ('.implode(', ', array_map($bind, $filter['value'])).')',
            'null' => $column.($filter['value'] ? ' IS NULL' : ' IS NOT NULL'),
            // A prefix compared like equality, so it follows the same collation
            // on every engine; LIKE would treat % and _ specially and differ in case.
            'starts_with' => $platform->getSubstringExpression($column, '1', (string) mb_strlen((string) $filter['value'])).' = '.$bind($filter['value']),
            default => throw new \LogicException('Unknown operator.'),
        };
    }
}
