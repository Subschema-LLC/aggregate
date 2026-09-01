<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AnalyticsArchiveService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Types\Types;
use PHPUnit\Framework\TestCase;

final class AnalyticsArchiveServiceTest extends TestCase
{
    public function testSchemaReadinessProbesEveryMutationAndReportingDependency(): void
    {
        $queries = [];
        $result = $this->createMock(Result::class);
        $result->expects(self::exactly(10))->method('free');
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(10))
            ->method('executeQuery')
            ->willReturnCallback(static function (string $sql) use (&$queries, $result): Result {
                $queries[] = $sql;

                return $result;
            });

        (new AnalyticsArchiveService($connection))->assertSchemaReady();

        self::assertSame([
            'SELECT archived_at FROM events WHERE 1 = 0',
            'SELECT cell_key FROM analytics_archive_events WHERE 1 = 0',
            'SELECT cell_key FROM analytics_archive_goals WHERE 1 = 0',
            'SELECT cell_key FROM analytics_archive_geo_events WHERE 1 = 0',
            'SELECT event_count FROM analytics_archived_events_v1 WHERE 1 = 0',
            'SELECT event_count FROM analytics_archived_pageviews_v1 WHERE 1 = 0',
            'SELECT event_count FROM analytics_archived_goals_v1 WHERE 1 = 0',
            'SELECT event_count FROM bi_anonymous_events_v1 WHERE 1 = 0',
            'SELECT event_count FROM bi_anonymous_goals_v1 WHERE 1 = 0',
            'SELECT event_count FROM bi_anonymous_geo_events_v1 WHERE 1 = 0',
        ], $queries);
    }

    public function testArchivesDuplicateRowsIntoOneEventGoalAndGeoCellAtomically(): void
    {
        $cutoff = new \DateTimeImmutable('2026-04-01 00:00:00', new \DateTimeZone('America/Chicago'));
        $expectedCutoff = $cutoff->setTimezone(new \DateTimeZone('UTC'));
        $archivedAt = new \DateTimeImmutable('2026-09-01 18:00:00', new \DateTimeZone('UTC'));
        $rows = [
            $this->eventRow(11),
            $this->eventRow(12),
        ];

        $firstResult = $this->createStub(Result::class);
        $firstResult->method('fetchAllAssociative')->willReturn($rows);
        $emptyResult = $this->createStub(Result::class);
        $emptyResult->method('fetchAllAssociative')->willReturn([]);

        $batchQueries = [];
        $writes = [];
        $inserts = [];
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQL80Platform());
        $connection->expects(self::once())
            ->method('fetchOne')
            ->with(
                'SELECT MAX(id) FROM events WHERE archived_at IS NULL AND created_at < :cutoff',
                ['cutoff' => $expectedCutoff],
                ['cutoff' => Types::DATETIME_IMMUTABLE],
            )
            ->willReturn('12');
        $connection->method('createQueryBuilder')
            ->willReturnCallback(static fn (): QueryBuilder => new QueryBuilder($connection));
        $connection->expects(self::exactly(2))
            ->method('executeQuery')
            ->willReturnCallback(static function (string $sql, array $parameters, array $types) use (
                &$batchQueries,
                $firstResult,
                $emptyResult,
            ): Result {
                $batchQueries[] = [$sql, $parameters, $types];

                return count($batchQueries) === 1 ? $firstResult : $emptyResult;
            });
        $connection->expects(self::exactly(2))
            ->method('transactional')
            ->willReturnCallback(static fn (\Closure $callback): mixed => $callback());
        $connection->expects(self::exactly(4))
            ->method('executeStatement')
            ->willReturnCallback(static function (string $sql, array $parameters, array $types) use (&$writes): int {
                $writes[] = [$sql, $parameters, $types];

                return str_contains($sql, 'UPDATE events SET archived_at') ? 2 : 0;
            });
        $connection->expects(self::exactly(3))
            ->method('insert')
            ->willReturnCallback(static function (
                string $table,
                array $data,
                array $types,
            ) use (&$inserts): int {
                $inserts[$table] = [$data, $types];

                return 1;
            });

        $heartbeats = 0;
        $archived = (new AnalyticsArchiveService($connection))->archiveBefore(
            $cutoff,
            $archivedAt,
            100,
            static function () use (&$heartbeats): void {
                ++$heartbeats;
            },
        );

        self::assertSame(2, $archived);
        self::assertSame(3, $heartbeats, 'The lease is refreshed at both ends of a write batch and before the terminating read.');
        self::assertCount(2, $batchQueries);
        self::assertStringContainsString('archived_at IS NULL', $batchQueries[0][0]);
        self::assertStringContainsString('created_at < :cutoff', $batchQueries[0][0]);
        self::assertStringContainsString('id <= :high_water', $batchQueries[0][0]);
        self::assertStringContainsString('ORDER BY id ASC', $batchQueries[0][0]);
        self::assertEquals($expectedCutoff, $batchQueries[0][1]['cutoff']);
        self::assertSame(12, $batchQueries[0][1]['high_water']);

        self::assertSame([
            'analytics_archive_events',
            'analytics_archive_goals',
            'analytics_archive_geo_events',
        ], array_keys($inserts));
        foreach ($inserts as [$data]) {
            self::assertSame(2, $data['event_count']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $data['cell_key']);
            self::assertArrayNotHasKey('visitor_id', $data);
            self::assertArrayNotHasKey('session_id', $data);
            self::assertArrayNotHasKey('custom_data', $data);
        }
        self::assertSame(
            '2026-01-02 03:00:00',
            $inserts['analytics_archive_events'][0]['event_hour']->format('Y-m-d H:i:s'),
        );
        self::assertSame(
            '2026-01-02',
            $inserts['analytics_archive_goals'][0]['event_day']->format('Y-m-d'),
        );

        $markerWrites = array_values(array_filter(
            $writes,
            static fn (array $write): bool => str_contains($write[0], 'UPDATE events SET archived_at'),
        ));
        self::assertCount(1, $markerWrites);
        self::assertStringContainsString('WHERE archived_at IS NULL', $markerWrites[0][0]);
        self::assertSame($archivedAt, $markerWrites[0][1]['archived_at']);
        self::assertSame([11, 12], $markerWrites[0][1]['ids']);
    }

    public function testNoEligibleHighWaterPerformsNoHeartbeatOrWrite(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchOne')->willReturn(null);
        $connection->expects(self::never())->method('createQueryBuilder');
        $connection->expects(self::never())->method('transactional');
        $connection->expects(self::never())->method('executeStatement');
        $connection->expects(self::never())->method('insert');

        $heartbeatCalled = false;
        $archived = (new AnalyticsArchiveService($connection))->archiveBefore(
            new \DateTimeImmutable('2026-01-01', new \DateTimeZone('UTC')),
            new \DateTimeImmutable('2026-09-01', new \DateTimeZone('UTC')),
            100,
            static function () use (&$heartbeatCalled): void {
                $heartbeatCalled = true;
            },
        );

        self::assertSame(0, $archived);
        self::assertFalse($heartbeatCalled);
    }

    public function testExistingArchiveCellIsIncrementedWithoutAnInsert(): void
    {
        $row = $this->eventRow(21);
        $row['goal_event'] = null;
        $row['geo_area'] = null;
        $firstResult = $this->createStub(Result::class);
        $firstResult->method('fetchAllAssociative')->willReturn([$row]);
        $emptyResult = $this->createStub(Result::class);
        $emptyResult->method('fetchAllAssociative')->willReturn([]);
        $query = 0;
        $writes = [];

        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQL80Platform());
        $connection->expects(self::once())->method('fetchOne')->willReturn(21);
        $connection->method('createQueryBuilder')
            ->willReturnCallback(static fn (): QueryBuilder => new QueryBuilder($connection));
        $connection->expects(self::exactly(2))
            ->method('executeQuery')
            ->willReturnCallback(static function () use (&$query, $firstResult, $emptyResult): Result {
                ++$query;

                return $query === 1 ? $firstResult : $emptyResult;
            });
        $connection->expects(self::exactly(2))
            ->method('transactional')
            ->willReturnCallback(static fn (\Closure $callback): mixed => $callback());
        $connection->expects(self::exactly(2))
            ->method('executeStatement')
            ->willReturnCallback(static function (string $sql, array $parameters) use (&$writes): int {
                $writes[] = [$sql, $parameters];

                return 1;
            });
        $connection->expects(self::never())->method('insert');

        $archived = (new AnalyticsArchiveService($connection))->archiveBefore(
            new \DateTimeImmutable('2026-04-01', new \DateTimeZone('UTC')),
            new \DateTimeImmutable('2026-09-01', new \DateTimeZone('UTC')),
            100,
            static function (): void {
            },
        );

        self::assertSame(1, $archived);
        self::assertStringContainsString('UPDATE analytics_archive_events', $writes[0][0]);
        self::assertStringContainsString('event_count = event_count + :increment', $writes[0][0]);
        self::assertSame(1, $writes[0][1]['increment']);
        self::assertStringContainsString('UPDATE events SET archived_at', $writes[1][0]);
        self::assertSame([21], $writes[1][1]['ids']);
    }

    /** @return array<string, mixed> */
    private function eventRow(int $id): array
    {
        return [
            'id' => (string) $id,
            'website_token' => 'site-token',
            'event_name' => 'view',
            'url' => '/pricing',
            'referrer' => 'search',
            'privacy_mode' => 'anonymous',
            'device_class' => 'desktop',
            'viewport_bucket' => 'large',
            'geo_area' => 'country:US',
            'goal_event' => 'purchase',
            'created_at' => '2026-01-02 03:47:59',
        ];
    }
}
