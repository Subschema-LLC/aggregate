<?php

declare(strict_types=1);

namespace App\Tests\Service\Alerts;

use App\Tests\Service\Operations\OperationsDatabase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\Type;

/**
 * Provides an in-memory database connection for processing_tasks and
 * audit_trail: uses SQLite via OperationsDatabase when pdo_sqlite is loaded,
 * and falls back to an in-memory Connection double when pdo_sqlite is absent.
 */
final class AlertsTestDatabase
{
    public static function connection(): Connection
    {
        if (in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            return OperationsDatabase::connection();
        }

        return new InMemoryOperationsConnection();
    }
}

/**
 * @internal
 */
final class InMemoryOperationsConnection extends Connection
{
    private int $nextId = 0;
    /** @var array<string, list<array<string, mixed>>> */
    private array $tables = [
        'processing_tasks' => [],
        'audit_trail' => [],
    ];
    private AbstractPlatform $sqlitePlatform;

    public function __construct()
    {
        $this->sqlitePlatform = new SQLitePlatform();
        $driver = new class($this->sqlitePlatform) implements Driver {
            public function __construct(private readonly AbstractPlatform $platform)
            {
            }

            public function connect(array $params): Driver\Connection
            {
                throw new \LogicException('In-memory connection does not open sockets.');
            }

            public function getDatabasePlatform(Driver\API\ExceptionConverter|\Doctrine\DBAL\ServerVersionProvider $versionProvider): AbstractPlatform
            {
                return $this->platform;
            }

            public function getExceptionConverter(): Driver\API\ExceptionConverter
            {
                throw new \LogicException('Not used.');
            }
        };
        parent::__construct([], $driver);
    }

    public function getDatabasePlatform(): AbstractPlatform
    {
        return $this->sqlitePlatform;
    }

    public function insert(string $table, array $data, array $types = []): int|string
    {
        if ($table === 'processing_tasks' && isset($data['lock_key']) && $data['lock_key'] !== null) {
            foreach ($this->tables['processing_tasks'] as $existing) {
                if (($existing['lock_key'] ?? null) === $data['lock_key']) {
                    throw new \RuntimeException('Duplicate lock_key');
                }
            }
        }

        $row = [
            'id' => ++$this->nextId,
            'finished_at' => null,
            'row_count' => null,
            'external_id' => null,
            'details' => null,
            'fingerprint' => null,
        ];
        foreach ($data as $column => $value) {
            $row[$column] = $this->normalizeValue($value, $types[$column] ?? null);
        }
        $this->tables[$table][] = $row;

        return 1;
    }

    public function lastInsertId(): int|string
    {
        return (string) $this->nextId;
    }

    public function executeStatement(string $sql, array $params = [], array $types = []): int|string
    {
        if (str_starts_with(trim($sql), 'DELETE FROM audit_trail')) {
            $count = count($this->tables['audit_trail']);
            $this->tables['audit_trail'] = [];

            return $count;
        }

        if (str_starts_with(trim($sql), 'UPDATE processing_tasks SET status = :status')) {
            $id = (int) ($params['id'] ?? 0);
            $running = (string) ($params['running'] ?? 'running');
            foreach ($this->tables['processing_tasks'] as &$row) {
                if ((int) $row['id'] === $id && (string) $row['status'] === $running) {
                    $row['status'] = $params['status'];
                    $row['lock_key'] = null;
                    $row['finished_at'] = $this->normalizeValue($params['now'] ?? null, $types['now'] ?? null);
                    $row['row_count'] = $params['rows'] ?? null;
                    $row['external_id'] = $params['external'] ?? null;
                    $row['details'] = $params['details'] ?? null;
                    $row['fingerprint'] = $params['fingerprint'] ?? null;

                    return 1;
                }
            }

            return 0;
        }

        return 0;
    }

    public function fetchAssociative(string $sql, array $params = [], array $types = []): array|false
    {
        if (str_contains($sql, 'FROM processing_tasks WHERE id = :id')) {
            $id = (int) ($params['id'] ?? 0);
            foreach ($this->tables['processing_tasks'] as $row) {
                if ((int) $row['id'] === $id) {
                    return $row;
                }
            }

            return false;
        }

        $all = $this->fetchAllAssociative($sql, $params, $types);

        return $all[0] ?? false;
    }

    public function fetchFirstColumn(string $sql, array $params = [], array $types = []): array
    {
        if (str_contains($sql, 'WHERE lock_key = :lock AND started_at < :before')) {
            $lock = (string) ($params['lock'] ?? '');
            $before = $this->normalizeValue($params['before'] ?? null, $types['before'] ?? null);
            $ids = [];
            foreach ($this->tables['processing_tasks'] as $row) {
                if (($row['lock_key'] ?? null) === $lock && is_string($row['started_at'] ?? null) && $row['started_at'] < (string) $before) {
                    $ids[] = $row['id'];
                }
            }

            return $ids;
        }

        return [];
    }

    public function fetchAllAssociative(string $sql, array $params = [], array $types = []): array
    {
        if (str_contains($sql, 'SELECT MAX(id) FROM processing_tasks WHERE task_type = :type GROUP BY subject, status')) {
            $type = (string) ($params['type'] ?? '');
            $maxByGroup = [];
            foreach ($this->tables['processing_tasks'] as $row) {
                if (($row['task_type'] ?? '') !== $type) {
                    continue;
                }
                $key = ($row['subject'] ?? '')."\0".($row['status'] ?? '');
                $maxByGroup[$key] = $row;
            }
            $rows = array_values($maxByGroup);
            usort($rows, static fn (array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);

            return $rows;
        }

        if (str_contains($sql, 'FROM audit_trail WHERE operation IN (:ops)')) {
            /** @var list<string> $ops */
            $ops = $params['ops'] ?? [];
            $matched = array_values(array_filter(
                $this->tables['audit_trail'],
                static fn (array $r): bool => in_array((string) ($r['operation'] ?? ''), $ops, true),
            ));
            usort($matched, static function (array $a, array $b): int {
                $cmp = strcmp((string) ($b['occurred_at'] ?? ''), (string) ($a['occurred_at'] ?? ''));

                return $cmp !== 0 ? $cmp : ((int) $b['id'] <=> (int) $a['id']);
            });
            if (preg_match('/LIMIT\s+(\d+)/i', $sql, $m) === 1) {
                $matched = array_slice($matched, 0, (int) $m[1]);
            }

            return $matched;
        }

        if (trim($sql) === 'SELECT * FROM processing_tasks') {
            return $this->tables['processing_tasks'];
        }
        if (trim($sql) === 'SELECT * FROM audit_trail') {
            return $this->tables['audit_trail'];
        }

        return [];
    }

    public function fetchOne(string $sql, array $params = [], array $types = []): mixed
    {
        if (str_contains($sql, 'SELECT COUNT(*) FROM (SELECT 1 AS c FROM processing_tasks')) {
            [$t1, $t2, $status, $s1, $s2, $s3, $from, $to] = $params;
            $cap = 1000;
            if (preg_match('/LIMIT\s+(\d+)/i', $sql, $m) === 1) {
                $cap = (int) $m[1];
            }
            $count = 0;
            foreach ($this->tables['processing_tasks'] as $row) {
                if (!in_array($row['task_type'] ?? '', [$t1, $t2], true)) {
                    continue;
                }
                if (($row['status'] ?? '') !== $status) {
                    continue;
                }
                if (!in_array($row['subject'] ?? '', [$s1, $s2, $s3], true)) {
                    continue;
                }
                $startedAt = (string) ($row['started_at'] ?? '');
                if ($startedAt < (string) $from || $startedAt >= (string) $to) {
                    continue;
                }
                ++$count;
                if ($count >= $cap) {
                    return $cap;
                }
            }

            return $count;
        }

        if (str_contains($sql, 'SELECT COUNT(*) FROM audit_trail WHERE operation IN (:ops)')) {
            /** @var list<string> $ops */
            $ops = $params['ops'] ?? [];
            $count = 0;
            foreach ($this->tables['audit_trail'] as $row) {
                if (in_array((string) ($row['operation'] ?? ''), $ops, true)) {
                    ++$count;
                }
            }

            return $count;
        }

        if (str_contains($sql, 'SELECT id FROM audit_trail WHERE operation = :op')) {
            $op = (string) ($params['op'] ?? '');
            $sub = (string) ($params['sub'] ?? '');
            $out = (string) ($params['out'] ?? '');
            $needle = trim((string) ($params['id'] ?? ''), '%');
            foreach ($this->tables['audit_trail'] as $row) {
                if (($row['operation'] ?? '') === $op
                    && ($row['subject'] ?? '') === $sub
                    && ($row['outcome'] ?? '') === $out
                    && $needle !== ''
                    && str_contains((string) ($row['details'] ?? ''), $needle)) {
                    return $row['id'];
                }
            }

            return false;
        }

        return 0;
    }

    private function normalizeValue(mixed $value, mixed $type): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        if (is_string($type) && Type::hasType($type) && $value !== null) {
            return Type::getType($type)->convertToDatabaseValue($value, $this->sqlitePlatform);
        }

        return $value;
    }
}
