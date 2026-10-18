<?php

declare(strict_types=1);

namespace App\Service\ViewSync;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;

/**
 * Reads one reporting view for a view sync: its columns, with their catalog
 * types, and its rows in that column order. Rows are streamed in batches, so
 * large private views do not have to fit in memory. Both syncs read views
 * through this, so their copies have the same rows and types. Errors name the
 * view and column, never a value.
 */
class ViewReader
{
    private const FETCH_ROWS = 2000;

    public function __construct(
        private readonly Connection $connection,
        private readonly ViewCatalog $catalog,
    ) {
    }

    /**
     * A syncable view's columns, with their catalog types, and its rows in
     * that column order, read lazily in batches: the view is queried when the
     * rows are first read.
     *
     * @return array{fields: list<array{name: string, type: string}>, rows: iterable<list<mixed>>}
     *
     * @throws ViewSyncException when the view is not syncable or cannot be read
     */
    public function read(string $view): array
    {
        if (!ViewCatalog::isKnown($view)) {
            throw new ViewSyncException($view.' is not a reporting view that can be synced.');
        }
        $sql = 'SELECT * FROM '.$this->connection->getDatabasePlatform()->quoteSingleIdentifier($view);

        return ['fields' => $this->fields($view, $sql), 'rows' => $this->rows($sql)];
    }

    /** @return list<array{name: string, type: string}> */
    private function fields(string $view, string $sql): array
    {
        try {
            $result = $this->connection->executeQuery($sql.' WHERE 1 = 0');
            $fields = [];
            for ($index = 0, $count = $result->columnCount(); $index < $count; ++$index) {
                $name = $result->getColumnName($index);
                $fields[] = ['name' => $name, 'type' => $this->catalog->columnType($view, $name)];
            }
            $result->free();
        } catch (\Doctrine\DBAL\Exception $e) {
            throw new ViewSyncException('The view '.$view.' cannot be read in this database. Run the database migrations'.(str_starts_with($view, 'analytics_custom_') ? ' and regenerate the reporting views' : '').($view === 'bi_anonymous_geo_events_v1' ? ' (this view needs optional geography)' : '').'.', previous: $e);
        }
        if ($fields === []) {
            throw new ViewSyncException('The view '.$view.' has no columns.');
        }

        return $fields;
    }

    /** @return iterable<list<mixed>> */
    private function rows(string $sql): iterable
    {
        $platform = $this->connection->getDatabasePlatform();
        if ($platform instanceof PostgreSQLPlatform) {
            // pdo_pgsql reads a whole result into memory; a cursor reads it in parts.
            // A finally block also runs when the caller stops reading early.
            $this->connection->beginTransaction();
            $completed = false;
            try {
                $this->connection->executeStatement('SET TRANSACTION READ ONLY');
                $this->connection->executeStatement('DECLARE aggregate_view_sync NO SCROLL CURSOR FOR '.$sql);
                do {
                    $batch = $this->connection->fetchAllNumeric('FETCH FORWARD '.self::FETCH_ROWS.' FROM aggregate_view_sync');
                    yield from $batch;
                } while (count($batch) === self::FETCH_ROWS);
                $completed = true;
            } finally {
                if ($this->connection->isTransactionActive()) {
                    $completed ? $this->connection->commit() : $this->connection->rollBack();
                }
            }

            return;
        }

        $native = $platform instanceof AbstractMySQLPlatform ? $this->connection->getNativeConnection() : null;
        $unbuffered = $native instanceof \PDO && $native->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql';
        if ($unbuffered) {
            // pdo_mysql buffers results by default; stream this one instead.
            $native->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        }
        try {
            $result = $this->connection->executeQuery($sql);
            try {
                yield from $result->iterateNumeric();
            } finally {
                $result->free();
            }
        } finally {
            if ($unbuffered) {
                $native->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
            }
        }
    }

    /**
     * Converts one database value to the JSON representation of its catalog
     * type, failing closed for a value that does not fit. BigQuery receives
     * the result as it is; ReportingTableWriter converts it again for its
     * database and replaces the message with its own.
     *
     * @throws ViewSyncException naming the view and column, never the value
     */
    public static function convert(mixed $value, string $type, string $view, string $column): mixed
    {
        if (is_resource($value)) {
            $value = stream_get_contents($value);
        }
        if ($value === null) {
            return null;
        }
        $invalid = static fn (): ViewSyncException => new ViewSyncException(sprintf('The view %s has a value in %s that is not a valid %s. Nothing was uploaded for this view.', $view, $column, $type));

        switch ($type) {
            case 'INT64':
                if (is_int($value)) {
                    return (string) $value;
                }
                if (is_float($value) && is_finite($value) && floor($value) === $value && abs($value) < 9.2e18) {
                    return sprintf('%.0f', $value);
                }
                if (is_string($value) && preg_match('/^(-?)0*([0-9]{1,19})(?:\.0+)?$/D', trim($value), $matches) === 1) {
                    return ($matches[2] === '0' ? '' : $matches[1]).$matches[2];
                }
                throw $invalid();
            case 'FLOAT64':
                if (is_string($value) && is_numeric(trim($value))) {
                    $value = (float) trim($value);
                }
                if ((is_int($value) || is_float($value)) && is_finite((float) $value)) {
                    return (float) $value;
                }
                throw $invalid();
            case 'BOOL':
                return match (true) {
                    is_bool($value) => $value,
                    $value === 1, $value === '1', $value === 't', $value === 'true' => true,
                    $value === 0, $value === '0', $value === 'f', $value === 'false' => false,
                    default => throw $invalid(),
                };
            case 'DATE':
                if (is_string($value) && preg_match('/^([0-9]{4}-[0-9]{2}-[0-9]{2})(?:[ T]00:00(?::00(?:\.0+)?)?)?$/D', trim($value), $matches) === 1) {
                    return $matches[1];
                }
                throw $invalid();
            case 'TIMESTAMP':
                if ($value instanceof \DateTimeInterface) {
                    return \DateTimeImmutable::createFromInterface($value)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
                }
                if (is_string($value) && preg_match('/^([0-9]{4}-[0-9]{2}-[0-9]{2})[ T]([0-9]{2}:[0-9]{2}(?::[0-9]{2})?)(?:\.([0-9]{1,9}))?(Z|[+-][0-9]{2}(?::?[0-9]{2})?)?$/D', trim($value), $matches) === 1) {
                    // Aggregate stores UTC; an explicit offset is honored.
                    $text = $matches[1].' '.$matches[2].(strlen($matches[2]) === 5 ? ':00' : '').'.'.str_pad(substr($matches[3] ?? '', 0, 6), 6, '0')
                        .(($matches[4] ?? '') === '' ? '' : ($matches[4] === 'Z' ? '+00:00' : (strlen($matches[4]) === 3 ? $matches[4].':00' : $matches[4])));
                    try {
                        return (new \DateTimeImmutable($text, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
                    } catch (\Exception) {
                        throw $invalid();
                    }
                }
                throw $invalid();
            default:
                if (is_bool($value)) {
                    return $value ? 'true' : 'false';
                }
                if (is_scalar($value)) {
                    return (string) $value;
                }
                throw $invalid();
        }
    }
}
