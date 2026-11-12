<?php

declare(strict_types=1);

namespace App\Service\Operations;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

/**
 * Bounds how long enhanced events wait in messenger_messages, the Doctrine
 * queue and its failed transport. Their payloads carry the enhanced detail
 * the events retention rules govern, outside those rules, so daily
 * maintenance on the administration server deletes every message older than
 * queue_retention_days (7 by default, never off), in batches, each in its
 * own transaction, by primary key.
 *
 * A message deleted here was never written to events: its event is lost.
 * That is the bound's purpose when workers stay down, or a failure keeps a
 * message from ever being written; the tracking-failure alert reports such
 * failures before then.
 */
class QueueRetention
{
    /** Symfony's Doctrine transport table, which config/packages/messenger.yaml keeps. */
    public const TABLE = 'messenger_messages';
    private const ID_CHUNK_SIZE = 500;

    public function __construct(private readonly Connection $connection)
    {
    }

    /** Whether the table exists: Symfony creates it the first time a message is queued or fails. */
    public function exists(): bool
    {
        try {
            return $this->connection->createSchemaManager()->tablesExist([self::TABLE]);
        } catch (\Throwable) {
            return false;
        }
    }

    public function countBefore(\DateTimeImmutable $cutoff): int
    {
        if (!$this->exists()) {
            return 0;
        }

        return (int) $this->connection->createQueryBuilder()
            ->select('COUNT(*)')
            ->from(self::TABLE)
            ->where('created_at < :cutoff')
            ->setParameter('cutoff', AuditTrail::utc($cutoff), Types::DATETIME_IMMUTABLE)
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * Deletes messages created before the cutoff, $batchSize at a time.
     *
     * @param (callable(): void)|null $heartbeat called before each batch
     */
    public function purgeBefore(\DateTimeImmutable $cutoff, int $batchSize, ?callable $heartbeat = null): int
    {
        if (!$this->exists()) {
            return 0;
        }
        $cutoff = AuditTrail::utc($cutoff);
        $deleted = 0;
        do {
            if ($heartbeat !== null) {
                $heartbeat();
            }
            $batch = $this->connection->transactional(function () use ($cutoff, $batchSize): int {
                $ids = array_map('intval', $this->connection->createQueryBuilder()
                    ->select('id')
                    ->from(self::TABLE)
                    ->where('created_at < :cutoff')
                    ->orderBy('id', 'ASC')
                    ->setMaxResults($batchSize)
                    ->setParameter('cutoff', $cutoff, Types::DATETIME_IMMUTABLE)
                    ->executeQuery()
                    ->fetchFirstColumn());
                $removed = 0;
                foreach (array_chunk($ids, self::ID_CHUNK_SIZE) as $chunk) {
                    // A worker may have just taken and removed one; that is fine.
                    $removed += (int) $this->connection->executeStatement(
                        'DELETE FROM '.self::TABLE.' WHERE id IN (:ids)',
                        ['ids' => $chunk],
                        ['ids' => ArrayParameterType::INTEGER],
                    );
                }

                return count($ids) === 0 ? -1 : $removed;
            });
            if ($batch < 0) {
                break;
            }
            $deleted += $batch;
        } while (true);

        return $deleted;
    }

    /**
     * Messages waiting in each queue, and when the oldest was queued (UTC).
     *
     * @return array<string, array{messages: int, oldest: ?\DateTimeImmutable}> by queue name
     */
    public function summary(): array
    {
        if (!$this->exists()) {
            return [];
        }
        $summary = [];
        $rows = $this->connection->createQueryBuilder()
            ->select('queue_name', 'COUNT(*) AS messages', 'MIN(created_at) AS oldest')
            ->from(self::TABLE)
            ->groupBy('queue_name')
            ->orderBy('queue_name')
            ->executeQuery()
            ->fetchAllAssociative();
        foreach ($rows as $row) {
            $oldest = is_string($row['oldest'] ?? null) ? new \DateTimeImmutable($row['oldest'], new \DateTimeZone('UTC')) : null;
            $summary[(string) $row['queue_name']] = ['messages' => (int) $row['messages'], 'oldest' => $oldest];
        }

        return $summary;
    }
}
