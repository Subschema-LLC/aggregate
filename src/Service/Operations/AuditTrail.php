<?php

declare(strict_types=1);

namespace App\Service\Operations;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

/**
 * The append-only audit_trail table: what happened, to what, by whom and with
 * what outcome. ProcessingTasks writes one entry per finished task; the
 * "admin" category is reserved for administrator actions. Entries are only
 * removed by the retention purge (audit_trail_retention_days). Details must
 * never carry secrets or visitor data; callers pass summaries and redacted
 * error messages.
 */
class AuditTrail
{
    public const CATEGORY_TASK = 'task';
    public const CATEGORY_ADMIN = 'admin';
    public const DETAILS_MAX_LENGTH = 4000;

    private const TABLE = 'audit_trail';
    private const ID_CHUNK_SIZE = 500;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function record(
        string $category,
        string $operation,
        string $outcome,
        \DateTimeImmutable $occurredAt,
        ?string $subject = null,
        ?string $actor = null,
        ?int $processingTaskId = null,
        ?string $details = null,
    ): void {
        foreach (['category' => [$category, 32], 'operation' => [$operation, 64], 'outcome' => [$outcome, 16]] as $name => [$value, $length]) {
            if (preg_match('/^[a-z][a-z0-9_]*$/D', $value) !== 1 || strlen($value) > $length) {
                throw new \InvalidArgumentException(sprintf('The audit %s must be a short lowercase code.', $name));
            }
        }

        $this->connection->insert(self::TABLE, [
            'occurred_at' => self::utc($occurredAt),
            'category' => $category,
            'operation' => $operation,
            'subject' => self::clean($subject, 191),
            'outcome' => $outcome,
            'actor' => self::clean($actor, 191),
            'processing_task_id' => $processingTaskId,
            'details' => self::clean($details, self::DETAILS_MAX_LENGTH),
        ], [
            'occurred_at' => Types::DATETIME_IMMUTABLE,
            'processing_task_id' => Types::BIGINT,
        ]);
    }

    public function countBefore(\DateTimeImmutable $cutoff): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM '.self::TABLE.' WHERE occurred_at < :cutoff',
            ['cutoff' => self::utc($cutoff)],
            ['cutoff' => Types::DATETIME_IMMUTABLE],
        );
    }

    /** Deletes entries that occurred before the cutoff, in batches. */
    public function purgeBefore(\DateTimeImmutable $cutoff, int $batchSize): int
    {
        $deleted = 0;
        do {
            $ids = array_map('intval', $this->connection->createQueryBuilder()
                ->select('id')
                ->from(self::TABLE)
                ->where('occurred_at < :cutoff')
                ->orderBy('id', 'ASC')
                ->setMaxResults($batchSize)
                ->setParameter('cutoff', self::utc($cutoff), Types::DATETIME_IMMUTABLE)
                ->executeQuery()
                ->fetchFirstColumn());
            foreach (array_chunk($ids, self::ID_CHUNK_SIZE) as $chunk) {
                $deleted += (int) $this->connection->executeStatement(
                    'DELETE FROM '.self::TABLE.' WHERE id IN (:ids)',
                    ['ids' => $chunk],
                    ['ids' => ArrayParameterType::INTEGER],
                );
            }
        } while (count($ids) === $batchSize);

        return $deleted;
    }

    /**
     * One line of plain text for a stored field: control characters removed,
     * whitespace collapsed, cut to the column's length. Empty becomes null.
     */
    public static function clean(?string $text, int $maxLength): ?string
    {
        if ($text === null) {
            return null;
        }
        $text = trim((string) preg_replace('/[\x00-\x20\x7F]+/u', ' ', mb_scrub($text, 'UTF-8')));
        if ($text === '') {
            return null;
        }

        return mb_strlen($text) > $maxLength ? rtrim(mb_substr($text, 0, $maxLength - 1)).'…' : $text;
    }

    public static function utc(\DateTimeImmutable $time): \DateTimeImmutable
    {
        return $time->setTimezone(new \DateTimeZone('UTC'));
    }
}
