<?php

declare(strict_types=1);

namespace App\Service\ReportingDatabase;

use App\Service\ViewSync\ViewReader;
use App\Service\ViewSync\ViewSyncException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;

/**
 * Replaces one table in the reporting database with a complete copy of a
 * reporting view. The rows go into a staging table first (VIEW_sync_new);
 * only when every row is in and counted does the staging table take the
 * view's name, in one step, so BI users always read a complete copy, either
 * the previous one or the new one.
 *
 * Column types come from the view catalog, as for BigQuery, so a table has
 * the same columns and types whichever engine the application database uses.
 */
final class ReportingTableWriter
{
    /** Bound values per INSERT, below SQLite's oldest limit (999) and SQL Server's (2,100). */
    private const VALUES_PER_INSERT = 900;
    private const ROWS_PER_INSERT = 500;
    /** Rows per transaction while filling the staging table. */
    private const ROWS_PER_TRANSACTION = 10000;
    /** How long the swap waits for BI queries that are reading the table. */
    private const LOCK_TIMEOUT_SECONDS = 60;

    public const STAGING_SUFFIX = '_sync_new';
    public const PREVIOUS_SUFFIX = '_sync_old';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param list<array{name: string, type: string}> $fields   Catalog columns, in row order
     * @param iterable<list<mixed>>                    $rows
     * @param int|null                                 $expected the number of rows $rows must yield, checked before the table is replaced
     *
     * @return int the number of rows in the new table
     *
     * @throws ReportingDatabaseException with a message that names the view and column, never a value
     * @throws \Doctrine\DBAL\Exception    when the reporting database refuses a statement
     */
    public function replace(string $table, array $fields, iterable $rows, ?int $expected = null): int
    {
        if ($fields === []) {
            throw new ReportingDatabaseException('The view '.$table.' has no columns.');
        }
        $platform = $this->connection->getDatabasePlatform();
        $staging = $table.self::STAGING_SUFFIX;

        $this->connection->executeStatement('DROP TABLE IF EXISTS '.$this->quote($staging));
        $this->connection->executeStatement($this->createTableSql($staging, $fields, $platform));
        try {
            $written = $this->fill($staging, $table, $fields, $rows, $platform);
            if ($expected !== null && $written !== $expected) {
                throw new ReportingDatabaseException(sprintf('%d of the %d rows of %s were read; the table was not replaced.', $written, $expected, $table));
            }
            $counted = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM '.$this->quote($staging));
            if ($counted !== $written) {
                throw new ReportingDatabaseException(sprintf('The reporting database holds %d of the %d rows copied from %s; the table was not replaced.', $counted, $written, $table));
            }
            $this->swap($staging, $table, $platform);
        } catch (\Throwable $e) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            try {
                $this->connection->executeStatement('DROP TABLE IF EXISTS '.$this->quote($staging));
            } catch (\Throwable) {
                // The next sync drops it before it starts.
            }
            throw $e;
        }

        return $written;
    }

    /** @param list<array{name: string, type: string}> $fields */
    private function createTableSql(string $table, array $fields, AbstractPlatform $platform): string
    {
        $columns = [];
        foreach ($fields as $field) {
            $columns[] = $this->quote($field['name']).' '.self::declaration($field['type'], $platform).' NULL';
        }
        $sql = 'CREATE TABLE '.$this->quote($table).' ('.implode(', ', $columns).')';

        return $platform instanceof AbstractMySQLPlatform ? $sql.' DEFAULT CHARACTER SET utf8mb4' : $sql;
    }

    /** The column type for a catalog type: nullable, with no length limit on text. */
    private static function declaration(string $type, AbstractPlatform $platform): string
    {
        return match ($type) {
            'INT64' => $platform->getBigIntTypeDeclarationSQL([]),
            'FLOAT64' => $platform->getFloatDeclarationSQL([]),
            // BI connectors read MySQL's TINYINT(1) as a boolean.
            'BOOL' => $platform instanceof AbstractMySQLPlatform ? 'TINYINT(1)' : $platform->getBooleanTypeDeclarationSQL([]),
            'DATE' => $platform->getDateTypeDeclarationSQL([]),
            'TIMESTAMP' => $platform->getDateTimeTypeDeclarationSQL([]),
            // SQL Server's VARCHAR(MAX) is not Unicode; labels can be in any language.
            default => $platform instanceof SQLServerPlatform ? 'NVARCHAR(MAX)' : $platform->getClobTypeDeclarationSQL([]),
        };
    }

    /**
     * @param list<array{name: string, type: string}> $fields
     * @param iterable<list<mixed>>                    $rows
     */
    private function fill(string $staging, string $view, array $fields, iterable $rows, AbstractPlatform $platform): int
    {
        $types = [];
        foreach ($fields as $field) {
            $types[] = Type::getType(match ($field['type']) {
                'INT64' => Types::BIGINT,
                'FLOAT64' => Types::FLOAT,
                'BOOL' => Types::BOOLEAN,
                'DATE' => Types::DATE_IMMUTABLE,
                'TIMESTAMP' => Types::DATETIME_IMMUTABLE,
                default => Types::TEXT,
            });
        }
        $rowsPerInsert = max(1, min(self::ROWS_PER_INSERT, intdiv(self::VALUES_PER_INSERT, count($fields))));
        $prefix = 'INSERT INTO '.$this->quote($staging).' ('.implode(', ', array_map($this->quote(...), array_column($fields, 'name'))).') VALUES ';
        $placeholders = '('.implode(', ', array_fill(0, count($fields), '?')).')';

        $written = 0;
        $uncommitted = 0;
        $values = [];
        $bindingTypes = [];
        $buffered = 0;
        $this->connection->beginTransaction();
        foreach ($rows as $row) {
            foreach ($fields as $index => $field) {
                $value = self::phpValue($row[$index] ?? null, $field['type'], $view, $field['name']);
                if (is_float($value)) {
                    // A float bound as a string would keep only 14 significant
                    // digits (PHP's precision setting); this keeps all 17.
                    $values[] = var_export($value, true);
                    $bindingTypes[] = ParameterType::STRING;
                    continue;
                }
                $values[] = $types[$index]->convertToDatabaseValue($value, $platform);
                $bindingTypes[] = $types[$index]->getBindingType();
            }
            if (++$buffered === $rowsPerInsert) {
                $this->insert($prefix.implode(', ', array_fill(0, $buffered, $placeholders)), $values, $bindingTypes, $view);
                $written += $buffered;
                $uncommitted += $buffered;
                $values = $bindingTypes = [];
                $buffered = 0;
                if ($uncommitted >= self::ROWS_PER_TRANSACTION) {
                    $this->connection->commit();
                    $this->connection->beginTransaction();
                    $uncommitted = 0;
                }
            }
        }
        if ($buffered > 0) {
            $this->insert($prefix.implode(', ', array_fill(0, $buffered, $placeholders)), $values, $bindingTypes, $view);
            $written += $buffered;
        }
        $this->connection->commit();

        return $written;
    }

    /**
     * A database's own message about a refused row can quote a value, so the
     * administrator sees the view and SQLSTATE; the application log has the rest.
     *
     * @param list<mixed>                                 $values
     * @param list<\Doctrine\DBAL\ParameterType|string> $types
     */
    private function insert(string $sql, array $values, array $types, string $view): void
    {
        try {
            $this->connection->executeStatement($sql, $values, $types);
        } catch (DbalException $e) {
            $state = $e instanceof DriverException && $e->getSQLState() !== null ? ', SQLSTATE '.$e->getSQLState() : '';
            throw new ReportingDatabaseException(sprintf('The reporting database refused rows of %s (%s%s); see the application log.', $view, (new \ReflectionClass($e))->getShortName(), $state), previous: $e);
        }
    }

    /** One value as PHP, in the view's catalog type, through the same checks as a BigQuery export. */
    private static function phpValue(mixed $value, string $type, string $view, string $column): mixed
    {
        $invalid = static fn (): ReportingDatabaseException => new ReportingDatabaseException(sprintf('The view %s has a value in %s that is not a valid %s; the table was not replaced.', $view, $column, $type));
        try {
            $value = ViewReader::convert($value, $type, $view, $column);
        } catch (ViewSyncException) {
            throw $invalid();
        }
        if ($value === null) {
            return null;
        }

        switch ($type) {
            case 'INT64':
                if ((string) (int) $value !== $value) {
                    throw $invalid();
                }

                return (int) $value;
            case 'DATE':
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));

                return $date !== false ? $date : throw $invalid();
            case 'TIMESTAMP':
                $time = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $value, new \DateTimeZone('UTC'));

                return $time !== false ? $time : throw $invalid();
            default:
                return $value;
        }
    }

    /**
     * Gives the staging table the view's name. MySQL renames both tables in
     * one statement; the other engines drop and rename in one transaction.
     * Either way a reader sees the old table or the new one, never neither.
     */
    private function swap(string $staging, string $table, AbstractPlatform $platform): void
    {
        $exists = $this->tableExists($table);
        if ($platform instanceof AbstractMySQLPlatform) {
            $previous = $table.self::PREVIOUS_SUFFIX;
            $this->connection->executeStatement('SET SESSION lock_wait_timeout = '.self::LOCK_TIMEOUT_SECONDS);
            $this->connection->executeStatement('DROP TABLE IF EXISTS '.$this->quote($previous));
            $this->connection->executeStatement($exists
                ? sprintf('RENAME TABLE %s TO %s, %s TO %s', $this->quote($table), $this->quote($previous), $this->quote($staging), $this->quote($table))
                : sprintf('RENAME TABLE %s TO %s', $this->quote($staging), $this->quote($table)));
            $this->connection->executeStatement('DROP TABLE IF EXISTS '.$this->quote($previous));

            return;
        }

        if ($platform instanceof SQLServerPlatform) {
            $this->connection->executeStatement('SET LOCK_TIMEOUT '.(self::LOCK_TIMEOUT_SECONDS * 1000));
        }
        $this->connection->beginTransaction();
        if ($platform instanceof PostgreSQLPlatform) {
            $this->connection->executeStatement("SET LOCAL lock_timeout = '".self::LOCK_TIMEOUT_SECONDS."s'");
        }
        if ($exists) {
            $this->connection->executeStatement('DROP TABLE '.$this->quote($table));
        }
        $this->connection->executeStatement($platform instanceof SQLServerPlatform
            ? $platform->getRenameTableSQL($staging, $table) // sp_rename takes the new name unquoted
            : 'ALTER TABLE '.$this->quote($staging).' RENAME TO '.$this->quote($table));
        $this->connection->commit();
    }

    /** How many rows the table holds, or null when there is no such table. */
    public function rowCount(string $table): ?int
    {
        return $this->tableExists($table) ? (int) $this->connection->fetchOne('SELECT COUNT(*) FROM '.$this->quote($table)) : null;
    }

    /**
     * Whether the table (or a view of that name) exists where an unqualified
     * name points: the connection's default schema or search path.
     */
    public function tableExists(string $table): bool
    {
        try {
            $this->connection->executeQuery('SELECT 1 FROM '.$this->quote($table).' WHERE 1 = 0')->free();

            return true;
        } catch (TableNotFoundException) {
            return false;
        }
    }

    private function quote(string $identifier): string
    {
        return $this->connection->getDatabasePlatform()->quoteSingleIdentifier($identifier);
    }
}
