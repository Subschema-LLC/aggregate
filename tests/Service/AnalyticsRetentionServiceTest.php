<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AnalyticsRetentionService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\TestCase;

final class AnalyticsRetentionServiceTest extends TestCase
{
    public function testRawRetentionUsesModeSpecificCutoffsAndRequiresArchiveMarkersWhenRequested(): void
    {
        $anonymousCutoff = new \DateTimeImmutable('2025-09-01 00:00:00', new \DateTimeZone('UTC'));
        $enhancedCutoff = new \DateTimeImmutable('2026-06-01 00:00:00', new \DateTimeZone('UTC'));
        $firstResult = $this->firstColumnResult(['101', '102']);
        $emptyResult = $this->firstColumnResult([]);
        $queries = [];
        $delete = null;

        $connection = $this->queryBuilderConnection();
        $connection->expects(self::exactly(2))
            ->method('executeQuery')
            ->willReturnCallback(static function (string $sql, array $parameters, array $types) use (
                &$queries,
                $firstResult,
                $emptyResult,
            ): Result {
                $queries[] = [$sql, $parameters, $types];

                return count($queries) === 1 ? $firstResult : $emptyResult;
            });
        $connection->expects(self::exactly(2))
            ->method('transactional')
            ->willReturnCallback(static fn (\Closure $callback): mixed => $callback());
        $connection->expects(self::once())
            ->method('executeStatement')
            ->willReturnCallback(static function (string $sql, array $parameters, array $types) use (&$delete): int {
                $delete = [$sql, $parameters, $types];

                return 2;
            });

        $heartbeats = 0;
        $deleted = (new AnalyticsRetentionService($connection))->deleteRawEventsBefore(
            $anonymousCutoff,
            $enhancedCutoff,
            100,
            true,
            static function () use (&$heartbeats): void {
                ++$heartbeats;
            },
        );

        self::assertSame(2, $deleted);
        self::assertSame(3, $heartbeats);
        self::assertCount(2, $queries);
        self::assertStringContainsString('privacy_mode = :anonymous_mode', $queries[0][0]);
        self::assertStringContainsString('created_at < :anonymous_cutoff', $queries[0][0]);
        self::assertStringContainsString('privacy_mode = :enhanced_mode', $queries[0][0]);
        self::assertStringContainsString('created_at < :enhanced_cutoff', $queries[0][0]);
        self::assertStringContainsString('archived_at IS NOT NULL', $queries[0][0]);
        self::assertSame('anonymous', $queries[0][1]['anonymous_mode']);
        self::assertSame('enhanced', $queries[0][1]['enhanced_mode']);
        self::assertSame($anonymousCutoff, $queries[0][1]['anonymous_cutoff']);
        self::assertSame($enhancedCutoff, $queries[0][1]['enhanced_cutoff']);
        self::assertIsArray($delete);
        self::assertSame('DELETE FROM events WHERE id IN (:ids)', $delete[0]);
        self::assertSame([101, 102], $delete[1]['ids']);
    }

    public function testRawRetentionRollsBackTheBatchSignalWhenRowsChangedConcurrently(): void
    {
        $connection = $this->queryBuilderConnection();
        $connection->expects(self::once())
            ->method('executeQuery')
            ->willReturn($this->firstColumnResult(['7', '8']));
        $connection->expects(self::once())
            ->method('transactional')
            ->willReturnCallback(static fn (\Closure $callback): mixed => $callback());
        $connection->expects(self::once())->method('executeStatement')->willReturn(1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('retention batch changed concurrently');

        (new AnalyticsRetentionService($connection))->deleteRawEventsBefore(
            new \DateTimeImmutable('2025-09-01', new \DateTimeZone('UTC')),
            new \DateTimeImmutable('2026-06-01', new \DateTimeZone('UTC')),
            100,
            false,
            static function (): void {
            },
        );
    }

    public function testArchiveRetentionDeletesEveryArchiveDatasetInBoundedBatches(): void
    {
        $results = [
            $this->firstColumnResult(['event-cell']),
            $this->firstColumnResult([]),
            $this->firstColumnResult(['goal-cell']),
            $this->firstColumnResult([]),
            $this->firstColumnResult(['geo-cell']),
            $this->firstColumnResult([]),
        ];
        $queries = [];
        $deletes = [];
        $connection = $this->queryBuilderConnection();
        $connection->expects(self::exactly(6))
            ->method('executeQuery')
            ->willReturnCallback(static function (string $sql) use (&$queries, &$results): Result {
                $queries[] = $sql;
                $result = array_shift($results);
                self::assertInstanceOf(Result::class, $result);

                return $result;
            });
        $connection->expects(self::exactly(6))
            ->method('transactional')
            ->willReturnCallback(static fn (\Closure $callback): mixed => $callback());
        $connection->expects(self::exactly(3))
            ->method('executeStatement')
            ->willReturnCallback(static function (string $sql, array $parameters) use (&$deletes): int {
                $deletes[] = [$sql, $parameters];

                return 1;
            });

        $heartbeats = 0;
        $deleted = (new AnalyticsRetentionService($connection))->deleteArchiveCellsBefore(
            new \DateTimeImmutable('2025-09-01 17:45:00', new \DateTimeZone('UTC')),
            100,
            static function () use (&$heartbeats): void {
                ++$heartbeats;
            },
        );

        self::assertSame(3, $deleted);
        self::assertSame(9, $heartbeats);
        self::assertCount(6, $queries);
        self::assertStringContainsString('FROM analytics_archive_events', $queries[0]);
        self::assertStringContainsString('event_hour < :cutoff', $queries[0]);
        self::assertStringContainsString('ORDER BY event_hour ASC, cell_key ASC', $queries[0]);
        self::assertStringContainsString('FROM analytics_archive_goals', $queries[2]);
        self::assertStringContainsString('event_day < :cutoff', $queries[2]);
        self::assertStringContainsString('FROM analytics_archive_geo_events', $queries[4]);
        self::assertSame([
            ['keys' => ['event-cell']],
            ['keys' => ['goal-cell']],
            ['keys' => ['geo-cell']],
        ], array_column($deletes, 1));
    }

    private function queryBuilderConnection(): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQL80Platform());
        $connection->method('createQueryBuilder')
            ->willReturnCallback(static fn (): QueryBuilder => new QueryBuilder($connection));

        return $connection;
    }

    /** @param list<mixed> $values */
    private function firstColumnResult(array $values): Result
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchFirstColumn')->willReturn($values);

        return $result;
    }
}
