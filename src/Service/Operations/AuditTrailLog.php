<?php

declare(strict_types=1);

namespace App\Service\Operations;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Types;

/**
 * Reads the audit trail for the Audit trail page, its CSV export and
 * app:audit:list. AuditTrail writes it; nothing here changes an entry.
 *
 * @phpstan-type Entry array{
 *     id: int,
 *     occurred_at: \DateTimeImmutable,
 *     area: string,
 *     action: string,
 *     outcome: string,
 *     subject: ?string,
 *     user: ?string,
 *     task: ?int,
 *     details: ?string
 * }
 */
class AuditTrailLog
{
    /** The most entries one export or command lists. */
    public const MAX_EXPORT = 100_000;
    /** The most distinct actions or users offered as choices. */
    private const MAX_CHOICES = 500;
    private const TABLE = 'audit_trail';
    private const BATCH = 1000;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * One page of matching entries and how many match in all. A page past the
     * last one shows the last.
     *
     * @return array{entries: list<Entry>, total: int, page: int, pages: int}
     */
    public function search(AuditTrailFilter $filter): array
    {
        $total = (int) $this->filtered($filter)->select('COUNT(*)')->executeQuery()->fetchOne();
        $pages = max(1, (int) ceil($total / $filter->pageSize));
        $page = min($filter->page, $pages);
        $rows = $this->ordered($this->filtered($filter), $filter->oldestFirst)
            ->setFirstResult(($page - 1) * $filter->pageSize)
            ->setMaxResults($filter->pageSize)
            ->executeQuery()
            ->fetchAllAssociative();

        return ['entries' => array_map($this->entry(...), $rows), 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /**
     * Every matching entry in the filter's order, read in batches, up to $limit.
     * The page and its size are ignored.
     *
     * @return \Generator<int, Entry>
     */
    public function each(AuditTrailFilter $filter, int $limit = self::MAX_EXPORT): \Generator
    {
        $limit = max(0, min($limit, self::MAX_EXPORT));
        $listed = 0;
        $last = null;
        $beyond = $filter->oldestFirst ? '>' : '<';
        while ($listed < $limit) {
            // Keyset paging: each batch continues after the last entry listed,
            // so entries recorded meanwhile cannot shift or repeat a batch.
            $query = $this->ordered($this->filtered($filter), $filter->oldestFirst)->setMaxResults(min(self::BATCH, $limit - $listed));
            if ($last !== null) {
                $query->andWhere(sprintf('(occurred_at %1$s :after_time OR (occurred_at = :after_time AND id %1$s :after_id))', $beyond))
                    ->setParameter('after_time', $last['occurred_at'], Types::DATETIME_IMMUTABLE)
                    ->setParameter('after_id', $last['id'], Types::BIGINT);
            }
            $rows = $query->executeQuery()->fetchAllAssociative();
            foreach ($rows as $row) {
                $last = $this->entry($row);
                ++$listed;
                yield $last;
            }
            if (count($rows) < self::BATCH) {
                return;
            }
        }
    }

    /**
     * The values recorded so far, for the filter's choices.
     *
     * @return array{areas: list<string>, actions: list<string>, outcomes: list<string>, users: list<string>, without_user: bool}
     */
    public function choices(): array
    {
        $distinct = fn (string $column): array => array_map('strval', $this->connection->createQueryBuilder()
            ->select('DISTINCT '.$column)
            ->from(self::TABLE)
            ->where($column.' IS NOT NULL')
            ->orderBy($column, 'ASC')
            ->setMaxResults(self::MAX_CHOICES)
            ->executeQuery()
            ->fetchFirstColumn());

        return [
            'areas' => $distinct('category'),
            'actions' => $distinct('operation'),
            'outcomes' => $distinct('outcome'),
            'users' => $distinct('actor'),
            'without_user' => $this->connection->createQueryBuilder()->select('id')->from(self::TABLE)->where('actor IS NULL')
                ->setMaxResults(1)->executeQuery()->fetchOne() !== false,
        ];
    }

    /**
     * How many entries there are, and the oldest and newest, by area.
     *
     * @return array<string, array{count: int, oldest: \DateTimeImmutable, newest: \DateTimeImmutable}>
     */
    public function summary(): array
    {
        $summary = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT category, COUNT(*) AS entries, MIN(occurred_at) AS oldest, MAX(occurred_at) AS newest FROM '.self::TABLE.' GROUP BY category ORDER BY category',
        ) as $row) {
            $summary[(string) $row['category']] = [
                'count' => (int) $row['entries'],
                'oldest' => $this->time($row['oldest']),
                'newest' => $this->time($row['newest']),
            ];
        }

        return $summary;
    }

    private function filtered(AuditTrailFilter $filter): QueryBuilder
    {
        $query = $this->connection->createQueryBuilder()->from(self::TABLE);
        if ($filter->from !== null) {
            $query->andWhere('occurred_at >= :from')->setParameter('from', AuditTrail::utc($filter->from), Types::DATETIME_IMMUTABLE);
        }
        if ($filter->to !== null) {
            $query->andWhere('occurred_at < :until')->setParameter('until', AuditTrail::utc($filter->to)->modify('+1 day'), Types::DATETIME_IMMUTABLE);
        }
        foreach (['category' => $filter->areas, 'operation' => $filter->actions, 'outcome' => $filter->outcomes] as $column => $values) {
            if ($values !== []) {
                $query->andWhere($column.' IN (:'.$column.')')->setParameter($column, $values, ArrayParameterType::STRING);
            }
        }
        if ($filter->user === AuditTrailFilter::NO_USER) {
            $query->andWhere('actor IS NULL');
        } elseif ($filter->user !== null) {
            $query->andWhere('actor = :actor')->setParameter('actor', $filter->user);
        }
        if ($filter->task !== null) {
            $query->andWhere('processing_task_id = :task')->setParameter('task', $filter->task, Types::BIGINT);
        }
        if ($filter->subject !== null) {
            $query->andWhere("LOWER(subject) LIKE :subject ESCAPE '!'")->setParameter('subject', self::contains($filter->subject));
        }
        if ($filter->text !== null) {
            // Actions and setting names are codes such as user_created, so "user created" finds them too.
            $query->andWhere("(LOWER(operation) LIKE :text ESCAPE '!' OR LOWER(operation) LIKE :code ESCAPE '!'"
                ." OR LOWER(subject) LIKE :text ESCAPE '!' OR LOWER(actor) LIKE :text ESCAPE '!'"
                ." OR LOWER(details) LIKE :text ESCAPE '!' OR LOWER(details) LIKE :code ESCAPE '!')")
                ->setParameter('text', self::contains($filter->text))
                ->setParameter('code', self::contains((string) preg_replace('/[\s-]+/', '_', $filter->text)));
        }

        return $query;
    }

    private function ordered(QueryBuilder $query, bool $oldestFirst): QueryBuilder
    {
        $direction = $oldestFirst ? 'ASC' : 'DESC';

        return $query
            ->select('id', 'occurred_at', 'category', 'operation', 'subject', 'outcome', 'actor', 'processing_task_id', 'details')
            ->orderBy('occurred_at', $direction)
            ->addOrderBy('id', $direction);
    }

    /** A LIKE pattern for text anywhere in a value, with ! escaping the wildcards. */
    private static function contains(string $text): string
    {
        return '%'.strtr(mb_strtolower($text), ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return Entry
     */
    private function entry(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'occurred_at' => $this->time($row['occurred_at']),
            'area' => (string) $row['category'],
            'action' => (string) $row['operation'],
            'outcome' => (string) $row['outcome'],
            'subject' => $row['subject'] !== null ? (string) $row['subject'] : null,
            'user' => $row['actor'] !== null ? (string) $row['actor'] : null,
            'task' => $row['processing_task_id'] !== null ? (int) $row['processing_task_id'] : null,
            'details' => $row['details'] !== null ? (string) $row['details'] : null,
        ];
    }

    private function time(mixed $value): \DateTimeImmutable
    {
        $time = $this->connection->convertToPHPValue($value, Types::DATETIME_IMMUTABLE);
        if (!$time instanceof \DateTimeImmutable) {
            throw new \UnexpectedValueException('An audit trail entry has no time.');
        }

        // Stored in UTC without a zone; read back as UTC whatever PHP's default is.
        return new \DateTimeImmutable($time->format('Y-m-d H:i:s'), new \DateTimeZone('UTC'));
    }
}
