<?php

declare(strict_types=1);

namespace App\Service\Operations;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;

/**
 * The processing_tasks table: one row per run of a background job, shared by
 * every feature that runs jobs (BigQuery sync, analytics maintenance). A run
 * starts as "running" and ends as "succeeded" or "failed" with details, and
 * each finished run is also written to the audit trail.
 *
 * An exclusive run holds a lock key (type:subject) that a unique index keeps
 * to one running row, so two processes on one server or several never run the
 * same job at once. A run still holding its lock after its allowed time is
 * treated as abandoned: the next start marks it failed and takes over.
 *
 * @phpstan-type TaskRow array{id: int, task_type: string, subject: ?string, status: string, triggered_by: string, requested_by: ?string, started_at: \DateTimeImmutable, finished_at: ?\DateTimeImmutable, row_count: ?int, external_id: ?string, details: ?string}
 */
class ProcessingTasks
{
    public const RUNNING = 'running';
    public const SUCCEEDED = 'succeeded';
    public const FAILED = 'failed';

    private const TABLE = 'processing_tasks';
    private const COLUMNS = 'id, task_type, subject, status, triggered_by, requested_by, started_at, finished_at, row_count, external_id, details';
    private const ID_CHUNK_SIZE = 500;

    public function __construct(
        private readonly Connection $connection,
        private readonly AuditTrail $audit,
    ) {
    }

    /**
     * Records the start of a run. With $exclusiveForSeconds, only one run of
     * this type and subject may be running, and a run older than that is
     * marked failed as abandoned.
     *
     * @return int|null The task's ID, or null when another run holds the lock.
     */
    public function start(string $type, ?string $subject, TaskTrigger $trigger, \DateTimeImmutable $now, ?int $exclusiveForSeconds = null): ?int
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $type) !== 1) {
            throw new \InvalidArgumentException('A task type must be a short lowercase code.');
        }
        $subject = AuditTrail::clean($subject, 191);
        $now = AuditTrail::utc($now);
        $lockKey = null;
        if ($exclusiveForSeconds !== null) {
            $lockKey = $type.':'.($subject ?? '');
            $this->expireAbandoned($lockKey, $now->modify('-'.max(1, $exclusiveForSeconds).' seconds'), $now);
        }

        try {
            $this->connection->insert(self::TABLE, [
                'task_type' => $type,
                'subject' => $subject,
                'status' => self::RUNNING,
                'triggered_by' => $trigger->source,
                'requested_by' => $trigger->requestedBy,
                'lock_key' => $lockKey,
                'started_at' => $now,
            ], ['started_at' => Types::DATETIME_IMMUTABLE]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        return (int) $this->connection->lastInsertId();
    }

    public function succeed(int $id, \DateTimeImmutable $now, ?int $rowCount = null, ?string $details = null, ?string $externalId = null): void
    {
        $this->finish($id, self::SUCCEEDED, $now, $rowCount, $details, $externalId);
    }

    public function fail(int $id, \DateTimeImmutable $now, string $details, ?string $externalId = null): void
    {
        $this->finish($id, self::FAILED, $now, null, $details, $externalId);
    }

    /**
     * The most recent run of each subject of a task type, and its most recent
     * success. Keyed by subject ('' for none).
     *
     * @return array<string, array{attempt: TaskRow, success: ?TaskRow}>
     */
    public function latest(string $type): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.self::COLUMNS.' FROM '.self::TABLE.' WHERE id IN ('
            .'SELECT MAX(id) FROM '.self::TABLE.' WHERE task_type = :type GROUP BY subject, status'
            .') ORDER BY id ASC',
            ['type' => $type],
        );
        $latest = [];
        foreach ($rows as $row) {
            $task = $this->task($row);
            $key = $task['subject'] ?? '';
            $latest[$key] ??= ['attempt' => $task, 'success' => null];
            $latest[$key]['attempt'] = $task;
            if ($task['status'] === self::SUCCEEDED) {
                $latest[$key]['success'] = $task;
            }
        }

        return $latest;
    }

    /** Runs the purge would delete; see purgeBefore(). */
    public function countBefore(\DateTimeImmutable $cutoff): int
    {
        return $this->eachPurgeableBatch($cutoff, 1000, static fn (array $ids): int => count($ids));
    }

    /**
     * Deletes runs that started and ended before the cutoff, in batches.
     * The latest success and failure of each type and subject are kept, so
     * schedules and status pages still know when a job last ran.
     */
    public function purgeBefore(\DateTimeImmutable $cutoff, int $batchSize): int
    {
        return $this->eachPurgeableBatch($cutoff, $batchSize, function (array $ids): int {
            $deleted = 0;
            foreach (array_chunk($ids, self::ID_CHUNK_SIZE) as $chunk) {
                $deleted += (int) $this->connection->executeStatement(
                    'DELETE FROM '.self::TABLE.' WHERE id IN (:ids)',
                    ['ids' => $chunk],
                    ['ids' => ArrayParameterType::INTEGER],
                );
            }

            return $deleted;
        });
    }

    /** @param \Closure(list<int>): int $handle */
    private function eachPurgeableBatch(\DateTimeImmutable $cutoff, int $batchSize, \Closure $handle): int
    {
        $keep = array_flip(array_map('intval', $this->connection->fetchFirstColumn(
            'SELECT MAX(id) FROM '.self::TABLE.' WHERE status <> :running GROUP BY task_type, subject, status',
            ['running' => self::RUNNING],
        )));
        $cutoff = AuditTrail::utc($cutoff);
        $after = 0;
        $total = 0;
        do {
            $ids = array_map('intval', $this->connection->createQueryBuilder()
                ->select('id')
                ->from(self::TABLE)
                ->where('id > :after')
                ->andWhere('started_at < :cutoff')
                ->andWhere('(finished_at IS NULL OR finished_at < :cutoff)')
                ->orderBy('id', 'ASC')
                ->setMaxResults($batchSize)
                ->setParameter('after', $after, Types::BIGINT)
                ->setParameter('cutoff', $cutoff, Types::DATETIME_IMMUTABLE)
                ->executeQuery()
                ->fetchFirstColumn());
            if ($ids === []) {
                break;
            }
            $after = max($ids);
            $removable = array_values(array_filter($ids, static fn (int $id): bool => !isset($keep[$id])));
            if ($removable !== []) {
                $total += $handle($removable);
            }
        } while (count($ids) === $batchSize);

        return $total;
    }

    private function expireAbandoned(string $lockKey, \DateTimeImmutable $startedBefore, \DateTimeImmutable $now): void
    {
        $ids = $this->connection->fetchFirstColumn(
            'SELECT id FROM '.self::TABLE.' WHERE lock_key = :lock AND started_at < :before',
            ['lock' => $lockKey, 'before' => $startedBefore],
            ['before' => Types::DATETIME_IMMUTABLE],
        );
        foreach ($ids as $id) {
            $this->finish((int) $id, self::FAILED, $now, null, sprintf(
                'Stopped without a result: the run was still marked running after %d minutes, so its process probably ended. The next run started instead.',
                intdiv($now->getTimestamp() - $startedBefore->getTimestamp(), 60),
            ), null);
        }
    }

    /**
     * Ends a running task and writes its audit entry. A task that already
     * ended (for example, expired by another process) is left unchanged.
     */
    private function finish(int $id, string $status, \DateTimeImmutable $now, ?int $rowCount, ?string $details, ?string $externalId): void
    {
        $now = AuditTrail::utc($now);
        $details = AuditTrail::clean($details, AuditTrail::DETAILS_MAX_LENGTH);
        $affected = $this->connection->executeStatement(
            'UPDATE '.self::TABLE.' SET status = :status, lock_key = NULL, finished_at = :now, row_count = :rows, external_id = :external, details = :details'
            .' WHERE id = :id AND status = :running',
            [
                'status' => $status,
                'now' => $now,
                'rows' => $rowCount,
                'external' => AuditTrail::clean($externalId, 191),
                'details' => $details,
                'id' => $id,
                'running' => self::RUNNING,
            ],
            ['now' => Types::DATETIME_IMMUTABLE, 'rows' => Types::BIGINT, 'id' => Types::BIGINT],
        );
        if ((int) $affected !== 1) {
            return;
        }

        $task = $this->connection->fetchAssociative(
            'SELECT task_type, subject, requested_by FROM '.self::TABLE.' WHERE id = :id',
            ['id' => $id],
            ['id' => Types::BIGINT],
        );
        if ($task === false) {
            return;
        }
        $this->audit->record(
            AuditTrail::CATEGORY_TASK,
            (string) $task['task_type'],
            $status,
            $now,
            $task['subject'] === null ? null : (string) $task['subject'],
            $task['requested_by'] === null ? null : (string) $task['requested_by'],
            $id,
            $details,
        );
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return TaskRow
     */
    private function task(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'task_type' => (string) $row['task_type'],
            'subject' => $row['subject'] === null ? null : (string) $row['subject'],
            'status' => (string) $row['status'],
            'triggered_by' => (string) $row['triggered_by'],
            'requested_by' => $row['requested_by'] === null ? null : (string) $row['requested_by'],
            'started_at' => $this->date($row['started_at']) ?? new \DateTimeImmutable('@0'),
            'finished_at' => $this->date($row['finished_at']),
            'row_count' => $row['row_count'] === null ? null : (int) $row['row_count'],
            'external_id' => $row['external_id'] === null ? null : (string) $row['external_id'],
            'details' => $row['details'] === null ? null : (string) $row['details'],
        ];
    }

    private function date(mixed $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        $date = $value instanceof \DateTimeInterface
            ? \DateTimeImmutable::createFromInterface($value)
            : Type::getType(Types::DATETIME_IMMUTABLE)->convertToPHPValue($value, $this->connection->getDatabasePlatform());

        return $date === null ? null : new \DateTimeImmutable($date->format('Y-m-d H:i:s.u'), new \DateTimeZone('UTC'));
    }
}
