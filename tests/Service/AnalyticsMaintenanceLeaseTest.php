<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AnalyticsMaintenanceAlreadyRunning;
use App\Service\AnalyticsMaintenanceLease;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class AnalyticsMaintenanceLeaseTest extends TestCase
{
    public function testZeroRowRefreshIsAcceptedWhenTheDatabaseStillHasTheSameOwner(): void
    {
        $now = new \DateTimeImmutable('2026-09-01 18:00:00', new \DateTimeZone('UTC'));
        $ownerToken = null;
        $write = 0;
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(3))
            ->method('executeStatement')
            ->willReturnCallback(static function (string $sql, array $parameters) use (&$ownerToken, &$write): int {
                ++$write;
                if ($write === 1) {
                    self::assertStringContainsString('UPDATE analytics_maintenance_lock', $sql);
                    $ownerToken = $parameters['owner_token'];

                    return 1;
                }

                return $write === 2 ? 0 : 1;
            });
        $connection->expects(self::once())
            ->method('fetchOne')
            ->with('SELECT owner_token FROM analytics_maintenance_lock WHERE id = 1')
            ->willReturnCallback(static function () use (&$ownerToken): string {
                self::assertIsString($ownerToken);

                return $ownerToken;
            });

        $lease = new AnalyticsMaintenanceLease($connection);
        $lease->acquire($now);
        $lease->refresh($now);
        $lease->release($now);

        self::assertIsString($ownerToken);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $ownerToken);
    }

    public function testZeroRowRefreshFailsWhenTheDatabaseOwnerChanged(): void
    {
        $now = new \DateTimeImmutable('2026-09-01 18:00:00', new \DateTimeZone('UTC'));
        $write = 0;
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(3))
            ->method('executeStatement')
            ->willReturnCallback(static function () use (&$write): int {
                ++$write;

                return $write === 1 || $write === 3 ? 1 : 0;
            });
        $connection->expects(self::once())
            ->method('fetchOne')
            ->with('SELECT owner_token FROM analytics_maintenance_lock WHERE id = 1')
            ->willReturn(str_repeat('0', 64));

        $lease = new AnalyticsMaintenanceLease($connection);
        $lease->acquire($now);

        try {
            $lease->refresh($now);
            self::fail('A heartbeat must fail after another process takes ownership.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('lease was lost', $exception->getMessage());
        } finally {
            $lease->release($now);
        }
    }

    public function testAcquireDistinguishesContentionFromAMissingMigrationRow(): void
    {
        $now = new \DateTimeImmutable('2026-09-01 18:00:00', new \DateTimeZone('UTC'));
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('executeStatement')->willReturn(0);
        $connection->expects(self::once())
            ->method('fetchOne')
            ->with('SELECT id FROM analytics_maintenance_lock WHERE id = 1')
            ->willReturn(1);

        $this->expectException(AnalyticsMaintenanceAlreadyRunning::class);

        (new AnalyticsMaintenanceLease($connection))->acquire($now);
    }

    public function testAcquireFailsActionablyWhenTheMigrationRowIsMissing(): void
    {
        $now = new \DateTimeImmutable('2026-09-01 18:00:00', new \DateTimeZone('UTC'));
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('executeStatement')->willReturn(0);
        $connection->expects(self::once())
            ->method('fetchOne')
            ->with('SELECT id FROM analytics_maintenance_lock WHERE id = 1')
            ->willReturn(false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('lock row is missing');

        (new AnalyticsMaintenanceLease($connection))->acquire($now);
    }
}
