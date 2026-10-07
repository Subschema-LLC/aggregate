<?php

declare(strict_types=1);

namespace App\Service\BigQuery;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;

/**
 * Sync status in analytics_bigquery_sync. Claiming a view sets it to running
 * with a random owner token in one conditional UPDATE, so two scheduled runs
 * (on one server or several) never sync the same view at once. A claim older
 * than the lease is treated as abandoned.
 */
class BigQuerySyncStateStore
{
    public const RUNNER = '__runner__';
    public const LEASE_SECONDS = 7200;
    private const TABLE = 'analytics_bigquery_sync';

    public function __construct(private readonly Connection $connection)
    {
    }

    /** @return array<string, array{status: string, started_at: ?\DateTimeImmutable, finished_at: ?\DateTimeImmutable, succeeded_at: ?\DateTimeImmutable, row_count: ?int, message: ?string, job_id: ?string}> */
    public function all(): array
    {
        $states = [];
        foreach ($this->connection->fetchAllAssociative('SELECT name, status, started_at, finished_at, succeeded_at, row_count, message, job_id FROM '.self::TABLE) as $row) {
            $states[(string) $row['name']] = [
                'status' => (string) $row['status'],
                'started_at' => $this->date($row['started_at']),
                'finished_at' => $this->date($row['finished_at']),
                'succeeded_at' => $this->date($row['succeeded_at']),
                'row_count' => $row['row_count'] === null ? null : (int) $row['row_count'],
                'message' => $row['message'] === null ? null : (string) $row['message'],
                'job_id' => $row['job_id'] === null ? null : (string) $row['job_id'],
            ];
        }

        return $states;
    }

    /** Records that the scheduled command ran, whether or not anything was due. */
    public function recordRunner(\DateTimeImmutable $now, string $message): void
    {
        $this->ensureRow(self::RUNNER, $now);
        $this->connection->executeStatement(
            'UPDATE '.self::TABLE.' SET status = :status, started_at = :now, finished_at = :now, message = :message, updated_at = :now WHERE name = :name',
            ['status' => 'success', 'now' => $now, 'message' => TokenCache::clean($message, 1000), 'name' => self::RUNNER],
            ['now' => Types::DATETIME_IMMUTABLE],
        );
    }

    /** Claims a view for this run. @return string|null The owner token, or null when another run holds it. */
    public function claim(string $view, \DateTimeImmutable $now): ?string
    {
        $this->ensureRow($view, $now);
        $token = bin2hex(random_bytes(16));
        $affected = $this->connection->executeStatement(
            'UPDATE '.self::TABLE.' SET status = :running, owner_token = :token, started_at = :now, updated_at = :now'
            .' WHERE name = :name AND (status <> :running OR owner_token IS NULL OR started_at IS NULL OR started_at < :stale)',
            [
                'running' => 'running',
                'token' => $token,
                'now' => $now,
                'stale' => $now->modify('-'.self::LEASE_SECONDS.' seconds'),
                'name' => $view,
            ],
            ['now' => Types::DATETIME_IMMUTABLE, 'stale' => Types::DATETIME_IMMUTABLE],
        );

        return (int) $affected === 1 ? $token : null;
    }

    public function finish(string $view, string $token, bool $success, ?int $rows, string $message, ?string $jobId, \DateTimeImmutable $now): void
    {
        $this->connection->executeStatement(
            'UPDATE '.self::TABLE.' SET status = :status, owner_token = NULL, finished_at = :now, updated_at = :now, message = :message, job_id = :job'
            .($success ? ', succeeded_at = :now, row_count = :rows' : '')
            .' WHERE name = :name AND owner_token = :token',
            [
                'status' => $success ? 'success' : 'failed',
                'now' => $now,
                'message' => TokenCache::clean($message, 1000),
                'job' => $jobId,
                'name' => $view,
                'token' => $token,
                ...($success ? ['rows' => $rows] : []),
            ],
            ['now' => Types::DATETIME_IMMUTABLE, ...($success ? ['rows' => Types::BIGINT] : [])],
        );
    }

    private function ensureRow(string $name, \DateTimeImmutable $now): void
    {
        if ($this->connection->fetchOne('SELECT name FROM '.self::TABLE.' WHERE name = :name', ['name' => $name]) !== false) {
            return;
        }
        try {
            $this->connection->insert(self::TABLE, ['name' => $name, 'status' => 'idle', 'updated_at' => $now], ['updated_at' => Types::DATETIME_IMMUTABLE]);
        } catch (UniqueConstraintViolationException) {
            // Another run created it first.
        }
    }

    private function date(mixed $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }

        return Type::getType(Types::DATETIME_IMMUTABLE)->convertToPHPValue($value, $this->connection->getDatabasePlatform());
    }
}
