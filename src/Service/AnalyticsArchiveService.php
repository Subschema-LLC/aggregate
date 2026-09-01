<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;

/**
 * Converts individual event rows into private, unsuppressed aggregate cells.
 *
 * Each source batch and all of its event/goal/geography cells are committed in
 * one transaction. The archived_at marker makes retries and late queue arrivals
 * idempotent while allowing the supported BI views to avoid double counting.
 */
final class AnalyticsArchiveService
{
    private const ID_CHUNK_SIZE = 500;
    private const UTC = 'UTC';

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /** Fail before any retention mutation if the lifecycle migration is incomplete. */
    public function assertSchemaReady(): void
    {
        $this->connection->executeQuery(
            'SELECT archived_at FROM events WHERE 1 = 0',
        )->free();

        foreach ([
            'analytics_archive_events',
            'analytics_archive_goals',
            'analytics_archive_geo_events',
        ] as $table) {
            $this->connection->executeQuery(
                sprintf('SELECT cell_key FROM %s WHERE 1 = 0', $table),
            )->free();
        }

        foreach ([
            'analytics_archived_events_v1',
            'analytics_archived_pageviews_v1',
            'analytics_archived_goals_v1',
            'bi_anonymous_events_v1',
            'bi_anonymous_goals_v1',
            'bi_anonymous_geo_events_v1',
        ] as $view) {
            $this->connection->executeQuery(
                sprintf('SELECT event_count FROM %s WHERE 1 = 0', $view),
            )->free();
        }
    }

    /**
     * @param callable(): void $heartbeat
     */
    public function archiveBefore(
        \DateTimeImmutable $cutoff,
        \DateTimeImmutable $archivedAt,
        int $batchSize,
        callable $heartbeat,
    ): int {
        $cutoff = $cutoff->setTimezone(new \DateTimeZone(self::UTC));
        $highWater = $this->connection->fetchOne(
            'SELECT MAX(id) FROM events WHERE archived_at IS NULL AND created_at < :cutoff',
            ['cutoff' => $cutoff],
            ['cutoff' => Types::DATETIME_IMMUTABLE],
        );

        if ($highWater === false || $highWater === null) {
            return 0;
        }

        $highWater = (int) $highWater;
        $archived = 0;

        do {
            $batchCount = $this->connection->transactional(function () use (
                $cutoff,
                $archivedAt,
                $batchSize,
                $highWater,
                $heartbeat,
            ): int {
                // Refresh inside the transaction so the lease row stays locked
                // while a large aggregate batch is being built.
                $heartbeat();
                $rows = $this->nextBatch($cutoff, $highWater, $batchSize);
                if ($rows === []) {
                    return 0;
                }

                [$eventCells, $goalCells, $geoCells, $ids] = $this->aggregate($rows);
                $this->upsertEventCells($eventCells);
                $this->upsertGoalCells($goalCells);
                $this->upsertGeoCells($geoCells);
                $heartbeat();

                $marked = 0;
                foreach (array_chunk($ids, self::ID_CHUNK_SIZE) as $idChunk) {
                    $marked += (int) $this->connection->executeStatement(
                        'UPDATE events SET archived_at = :archived_at WHERE archived_at IS NULL AND id IN (:ids)',
                        ['archived_at' => $archivedAt, 'ids' => $idChunk],
                        ['archived_at' => Types::DATETIME_IMMUTABLE, 'ids' => ArrayParameterType::INTEGER],
                    );
                }

                if ($marked !== count($ids)) {
                    throw new \RuntimeException('An analytics archive batch changed concurrently.');
                }

                return count($ids);
            });

            $archived += $batchCount;
        } while ($batchCount > 0);

        return $archived;
    }

    public function countBefore(\DateTimeImmutable $cutoff): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM events WHERE archived_at IS NULL AND created_at < :cutoff',
            ['cutoff' => $cutoff->setTimezone(new \DateTimeZone(self::UTC))],
            ['cutoff' => Types::DATETIME_IMMUTABLE],
        );
    }

    /** @return list<array<string, mixed>> */
    private function nextBatch(\DateTimeImmutable $cutoff, int $highWater, int $batchSize): array
    {
        $query = $this->connection->createQueryBuilder()
            ->select(
                'id',
                'website_token',
                'event_name',
                'url',
                'referrer',
                'privacy_mode',
                'device_class',
                'viewport_bucket',
                'geo_area',
                'goal_event',
                'created_at',
            )
            ->from('events')
            ->where('archived_at IS NULL')
            ->andWhere('created_at < :cutoff')
            ->andWhere('id <= :high_water')
            ->orderBy('id', 'ASC')
            ->setMaxResults($batchSize)
            ->setParameter('cutoff', $cutoff, Types::DATETIME_IMMUTABLE)
            ->setParameter('high_water', $highWater, Types::INTEGER);

        return $query->executeQuery()->fetchAllAssociative();
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{
     *     array<string, array{dimensions: array<string, mixed>, count: int}>,
     *     array<string, array{dimensions: array<string, mixed>, count: int}>,
     *     array<string, array{dimensions: array<string, mixed>, count: int}>,
     *     list<int>
     * }
     */
    private function aggregate(array $rows): array
    {
        $eventCells = [];
        $goalCells = [];
        $geoCells = [];
        $ids = [];

        foreach ($rows as $row) {
            $createdAt = $this->dateTime($row['created_at'] ?? null);
            $eventHour = $createdAt->setTime((int) $createdAt->format('G'), 0, 0);
            $eventDay = $createdAt->setTime(0, 0, 0);
            $ids[] = (int) $row['id'];

            $eventDimensions = [
                'website_token' => (string) $row['website_token'],
                'event_hour' => $eventHour,
                'privacy_mode' => (string) $row['privacy_mode'],
                'event_name' => (string) $row['event_name'],
                'page_path' => (string) $row['url'],
                'referrer_channel' => $row['referrer'] === null ? 'unknown' : (string) $row['referrer'],
                'device_class' => (string) $row['device_class'],
                'viewport_bucket' => (string) $row['viewport_bucket'],
            ];
            $this->incrementCell($eventCells, 'events', $eventDimensions);

            if ($row['goal_event'] !== null) {
                $goalDimensions = [
                    'website_token' => (string) $row['website_token'],
                    'event_day' => $eventDay,
                    'privacy_mode' => (string) $row['privacy_mode'],
                    'goal_event' => (string) $row['goal_event'],
                ];
                $this->incrementCell($goalCells, 'goals', $goalDimensions);
            }

            if ($row['geo_area'] !== null) {
                $geoDimensions = [
                    'website_token' => (string) $row['website_token'],
                    'event_day' => $eventDay,
                    'privacy_mode' => (string) $row['privacy_mode'],
                    'event_name' => (string) $row['event_name'],
                    'geo_area' => (string) $row['geo_area'],
                ];
                $this->incrementCell($geoCells, 'geo_events', $geoDimensions);
            }
        }

        return [$eventCells, $goalCells, $geoCells, $ids];
    }

    /**
     * @param array<string, array{dimensions: array<string, mixed>, count: int}> $cells
     * @param array<string, mixed> $dimensions
     */
    private function incrementCell(array &$cells, string $dataset, array $dimensions): void
    {
        $cellKey = $this->cellKey($dataset, $dimensions);
        if (!isset($cells[$cellKey])) {
            $cells[$cellKey] = ['dimensions' => $dimensions, 'count' => 0];
        }
        ++$cells[$cellKey]['count'];
    }

    /** @param array<string, mixed> $dimensions */
    private function cellKey(string $dataset, array $dimensions): string
    {
        $canonical = [];
        foreach ($dimensions as $name => $value) {
            $canonical[$name] = $value instanceof \DateTimeInterface
                ? \DateTimeImmutable::createFromInterface($value)
                    ->setTimezone(new \DateTimeZone(self::UTC))
                    ->format('Y-m-d H:i:s')
                : $value;
        }

        return hash('sha256', $dataset."\0".json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string, array{dimensions: array<string, mixed>, count: int}> $cells */
    private function upsertEventCells(array $cells): void
    {
        foreach ($cells as $cellKey => $cell) {
            $dimensions = $cell['dimensions'];
            $parameters = ['cell_key' => $cellKey, 'increment' => $cell['count'], ...$dimensions];
            $types = [
                'cell_key' => Types::STRING,
                'increment' => Types::INTEGER,
                'website_token' => Types::STRING,
                'event_hour' => Types::DATETIME_IMMUTABLE,
                'privacy_mode' => Types::STRING,
                'event_name' => Types::STRING,
                'page_path' => Types::TEXT,
                'referrer_channel' => Types::TEXT,
                'device_class' => Types::STRING,
                'viewport_bucket' => Types::STRING,
            ];
            $updated = $this->connection->executeStatement(<<<'SQL'
UPDATE analytics_archive_events
SET event_count = event_count + :increment
WHERE cell_key = :cell_key
  AND website_token = :website_token
  AND event_hour = :event_hour
  AND privacy_mode = :privacy_mode
  AND event_name = :event_name
  AND page_path = :page_path
  AND referrer_channel = :referrer_channel
  AND device_class = :device_class
  AND viewport_bucket = :viewport_bucket
SQL, $parameters, $types);

            if ((int) $updated === 0) {
                $this->insertCell(
                    'analytics_archive_events',
                    $cellKey,
                    $dimensions,
                    $cell['count'],
                    $types,
                );
            }
        }
    }

    /** @param array<string, array{dimensions: array<string, mixed>, count: int}> $cells */
    private function upsertGoalCells(array $cells): void
    {
        foreach ($cells as $cellKey => $cell) {
            $dimensions = $cell['dimensions'];
            $parameters = ['cell_key' => $cellKey, 'increment' => $cell['count'], ...$dimensions];
            $types = [
                'cell_key' => Types::STRING,
                'increment' => Types::INTEGER,
                'website_token' => Types::STRING,
                'event_day' => Types::DATE_IMMUTABLE,
                'privacy_mode' => Types::STRING,
                'goal_event' => Types::STRING,
            ];
            $updated = $this->connection->executeStatement(<<<'SQL'
UPDATE analytics_archive_goals
SET event_count = event_count + :increment
WHERE cell_key = :cell_key
  AND website_token = :website_token
  AND event_day = :event_day
  AND privacy_mode = :privacy_mode
  AND goal_event = :goal_event
SQL, $parameters, $types);

            if ((int) $updated === 0) {
                $this->insertCell(
                    'analytics_archive_goals',
                    $cellKey,
                    $dimensions,
                    $cell['count'],
                    $types,
                );
            }
        }
    }

    /** @param array<string, array{dimensions: array<string, mixed>, count: int}> $cells */
    private function upsertGeoCells(array $cells): void
    {
        foreach ($cells as $cellKey => $cell) {
            $dimensions = $cell['dimensions'];
            $parameters = ['cell_key' => $cellKey, 'increment' => $cell['count'], ...$dimensions];
            $types = [
                'cell_key' => Types::STRING,
                'increment' => Types::INTEGER,
                'website_token' => Types::STRING,
                'event_day' => Types::DATE_IMMUTABLE,
                'privacy_mode' => Types::STRING,
                'event_name' => Types::STRING,
                'geo_area' => Types::STRING,
            ];
            $updated = $this->connection->executeStatement(<<<'SQL'
UPDATE analytics_archive_geo_events
SET event_count = event_count + :increment
WHERE cell_key = :cell_key
  AND website_token = :website_token
  AND event_day = :event_day
  AND privacy_mode = :privacy_mode
  AND event_name = :event_name
  AND geo_area = :geo_area
SQL, $parameters, $types);

            if ((int) $updated === 0) {
                $this->insertCell(
                    'analytics_archive_geo_events',
                    $cellKey,
                    $dimensions,
                    $cell['count'],
                    $types,
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $dimensions
     * @param array<string, string> $types
     */
    private function insertCell(
        string $table,
        string $cellKey,
        array $dimensions,
        int $count,
        array $types,
    ): void {
        $parameters = ['cell_key' => $cellKey, ...$dimensions, 'event_count' => $count];
        unset($types['increment']);
        $types['event_count'] = Types::INTEGER;

        try {
            $this->connection->insert($table, $parameters, $types);
        } catch (UniqueConstraintViolationException $e) {
            throw new \RuntimeException(sprintf(
                'An analytics archive cell-key collision was detected in %s.',
                $table,
            ), previous: $e);
        }
    }

    private function dateTime(mixed $value): \DateTimeImmutable
    {
        if ($value instanceof \DateTimeImmutable) {
            return $value->setTimezone(new \DateTimeZone(self::UTC));
        }
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value)->setTimezone(new \DateTimeZone(self::UTC));
        }
        if (!is_string($value) || $value === '') {
            throw new \RuntimeException('An event has an invalid archive timestamp.');
        }

        return new \DateTimeImmutable($value, new \DateTimeZone(self::UTC));
    }
}
