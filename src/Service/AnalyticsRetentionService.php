<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

final class AnalyticsRetentionService
{
    private const ID_CHUNK_SIZE = 500;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @param callable(): void $heartbeat
     */
    public function deleteRawEventsBefore(
        \DateTimeImmutable $anonymousCutoff,
        \DateTimeImmutable $enhancedCutoff,
        int $batchSize,
        bool $onlyArchived,
        callable $heartbeat,
    ): int {
        $deleted = 0;

        do {
            $batchCount = $this->connection->transactional(function () use (
                $anonymousCutoff,
                $enhancedCutoff,
                $batchSize,
                $onlyArchived,
                $heartbeat,
            ): int {
                $heartbeat();
                $query = $this->connection->createQueryBuilder()
                    ->select('id')
                    ->from('events')
                    ->where(<<<'SQL'
(
    (privacy_mode = :anonymous_mode AND created_at < :anonymous_cutoff)
    OR
    (privacy_mode = :enhanced_mode AND created_at < :enhanced_cutoff)
)
SQL)
                    ->orderBy('id', 'ASC')
                    ->setMaxResults($batchSize)
                    ->setParameter('anonymous_mode', 'anonymous', Types::STRING)
                    ->setParameter('enhanced_mode', 'enhanced', Types::STRING)
                    ->setParameter('anonymous_cutoff', $anonymousCutoff, Types::DATETIME_IMMUTABLE)
                    ->setParameter('enhanced_cutoff', $enhancedCutoff, Types::DATETIME_IMMUTABLE);

                if ($onlyArchived) {
                    $query->andWhere('archived_at IS NOT NULL');
                }

                $ids = array_map(
                    'intval',
                    $query->executeQuery()->fetchFirstColumn(),
                );
                if ($ids === []) {
                    return 0;
                }

                $heartbeat();
                $affected = 0;
                foreach (array_chunk($ids, self::ID_CHUNK_SIZE) as $idChunk) {
                    $affected += (int) $this->connection->executeStatement(
                        'DELETE FROM events WHERE id IN (:ids)',
                        ['ids' => $idChunk],
                        ['ids' => ArrayParameterType::INTEGER],
                    );
                }

                if ($affected !== count($ids)) {
                    throw new \RuntimeException('An analytics retention batch changed concurrently.');
                }

                return $affected;
            });

            $deleted += $batchCount;
        } while ($batchCount > 0);

        return $deleted;
    }

    /**
     * @param callable(): void $heartbeat
     */
    public function deleteArchiveCellsBefore(
        \DateTimeImmutable $cutoff,
        int $batchSize,
        callable $heartbeat,
    ): int {
        $deleted = 0;
        $dayCutoff = $cutoff->setTime(0, 0, 0);

        foreach ([
            ['table' => 'analytics_archive_events', 'column' => 'event_hour', 'type' => Types::DATETIME_IMMUTABLE],
            ['table' => 'analytics_archive_goals', 'column' => 'event_day', 'type' => Types::DATE_IMMUTABLE],
            ['table' => 'analytics_archive_geo_events', 'column' => 'event_day', 'type' => Types::DATE_IMMUTABLE],
        ] as $archive) {
            do {
                $batchCount = $this->connection->transactional(function () use (
                    $archive,
                    $dayCutoff,
                    $batchSize,
                    $heartbeat,
                ): int {
                    $heartbeat();
                    $keys = $this->connection->createQueryBuilder()
                        ->select('cell_key')
                        ->from($archive['table'])
                        ->where($archive['column'].' < :cutoff')
                        ->orderBy($archive['column'], 'ASC')
                        ->addOrderBy('cell_key', 'ASC')
                        ->setMaxResults($batchSize)
                        ->setParameter('cutoff', $dayCutoff, $archive['type'])
                        ->executeQuery()
                        ->fetchFirstColumn();

                    if ($keys === []) {
                        return 0;
                    }

                    $heartbeat();
                    $affected = 0;
                    foreach (array_chunk($keys, self::ID_CHUNK_SIZE) as $keyChunk) {
                        $affected += (int) $this->connection->executeStatement(
                            sprintf('DELETE FROM %s WHERE cell_key IN (:keys)', $archive['table']),
                            ['keys' => $keyChunk],
                            ['keys' => ArrayParameterType::STRING],
                        );
                    }

                    if ($affected !== count($keys)) {
                        throw new \RuntimeException('An analytics archive-retention batch changed concurrently.');
                    }

                    return $affected;
                });

                $deleted += $batchCount;
            } while ($batchCount > 0);
        }

        return $deleted;
    }

    public function countRawEventsBefore(
        \DateTimeImmutable $anonymousCutoff,
        \DateTimeImmutable $enhancedCutoff,
    ): int {
        return (int) $this->connection->fetchOne(
            <<<'SQL'
SELECT COUNT(*)
FROM events
WHERE (privacy_mode = :anonymous_mode AND created_at < :anonymous_cutoff)
   OR (privacy_mode = :enhanced_mode AND created_at < :enhanced_cutoff)
SQL,
            [
                'anonymous_mode' => 'anonymous',
                'enhanced_mode' => 'enhanced',
                'anonymous_cutoff' => $anonymousCutoff,
                'enhanced_cutoff' => $enhancedCutoff,
            ],
            [
                'anonymous_mode' => Types::STRING,
                'enhanced_mode' => Types::STRING,
                'anonymous_cutoff' => Types::DATETIME_IMMUTABLE,
                'enhanced_cutoff' => Types::DATETIME_IMMUTABLE,
            ],
        );
    }

    public function countArchiveCellsBefore(\DateTimeImmutable $cutoff): int
    {
        $dayCutoff = $cutoff->setTime(0, 0, 0);

        return (int) $this->connection->fetchOne(
            <<<'SQL'
SELECT
    (SELECT COUNT(*) FROM analytics_archive_events WHERE event_hour < :event_cutoff)
    + (SELECT COUNT(*) FROM analytics_archive_goals WHERE event_day < :day_cutoff)
    + (SELECT COUNT(*) FROM analytics_archive_geo_events WHERE event_day < :day_cutoff)
SQL,
            ['event_cutoff' => $dayCutoff, 'day_cutoff' => $dayCutoff],
            ['event_cutoff' => Types::DATETIME_IMMUTABLE, 'day_cutoff' => Types::DATE_IMMUTABLE],
        );
    }
}
