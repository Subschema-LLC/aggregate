<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AnalyticsPrivacySettings;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AnalyticsPrivacySettingsTest extends TestCase
{
    public function testReadsBothThresholdsFromTheDatabaseSingleton(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchAssociative')
            ->with(self::callback(static fn (string $sql): bool =>
                str_contains($sql, 'FROM analytics_privacy_settings')
                && str_contains($sql, 'WHERE id = 1')))
            ->willReturn([
                'anonymous_min_cell_count' => '14',
                'anonymous_geo_min_cell_count' => '40',
            ]);

        self::assertSame(
            ['anonymous' => 14, 'geo' => 40],
            (new AnalyticsPrivacySettings($connection))->getMinimumCellCounts(),
        );
    }

    public function testSavesBothThresholdsAtomicallyWithAUtcTimestamp(): void
    {
        $now = new \DateTimeImmutable('2026-07-24 13:30:00', new \DateTimeZone('America/Chicago'));
        $utcNow = $now->setTimezone(new \DateTimeZone('UTC'));
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('executeStatement')
            ->with(
                self::callback(static fn (string $sql): bool =>
                    str_contains($sql, 'UPDATE analytics_privacy_settings')
                    && str_contains($sql, 'anonymous_min_cell_count = :minimum')
                    && str_contains($sql, 'anonymous_geo_min_cell_count = :geo_minimum')
                    && str_contains($sql, 'WHERE id = 1')),
                [
                    'minimum' => 14,
                    'geo_minimum' => 40,
                    'updated_at' => $utcNow,
                ],
                [
                    'minimum' => Types::INTEGER,
                    'geo_minimum' => Types::INTEGER,
                    'updated_at' => Types::DATETIME_IMMUTABLE,
                ],
            )
            ->willReturn(1);
        $connection->expects(self::never())->method('fetchOne');

        (new AnalyticsPrivacySettings($connection))->saveMinimumCellCounts(14, 40, $now);
    }

    #[DataProvider('invalidMinimums')]
    public function testRejectsInvalidThresholdsBeforeWriting(int $minimum, int $geoMinimum): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('executeStatement');

        $this->expectException(\InvalidArgumentException::class);

        (new AnalyticsPrivacySettings($connection))->saveMinimumCellCounts($minimum, $geoMinimum);
    }

    public static function invalidMinimums(): iterable
    {
        yield 'hourly below floor' => [1, 25];
        yield 'hourly above maximum' => [1001, 25];
        yield 'geography below floor' => [5, 9];
        yield 'geography above maximum' => [5, 1001];
    }

    public function testMissingSingletonFailsOnRead(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAssociative')->willReturn(false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('analytics privacy settings row is missing');

        (new AnalyticsPrivacySettings($connection))->getMinimumCellCounts();
    }

    public function testAdminSaveRepairsAMissingSingleton(): void
    {
        $statements = [];
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(2))
            ->method('executeStatement')
            ->willReturnCallback(static function (string $sql) use (&$statements): int {
                $statements[] = $sql;

                return str_contains($sql, 'UPDATE analytics_privacy_settings') ? 0 : 1;
            });
        $connection->expects(self::once())
            ->method('fetchOne')
            ->with('SELECT id FROM analytics_privacy_settings WHERE id = 1')
            ->willReturn(false);

        (new AnalyticsPrivacySettings($connection))->saveMinimumCellCounts(5, 25);

        self::assertStringContainsString('UPDATE analytics_privacy_settings', $statements[0]);
        self::assertStringContainsString('INSERT INTO analytics_privacy_settings', $statements[1]);
    }

    public function testUnchangedUpdateIsSuccessfulWhenTheSingletonStillExists(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('executeStatement')->willReturn(0);
        $connection->expects(self::once())->method('fetchOne')->willReturn(1);

        (new AnalyticsPrivacySettings($connection))->saveMinimumCellCounts(5, 25);
    }

    #[DataProvider('invalidStoredMinimums')]
    public function testInvalidStoredThresholdFailsClosed(mixed $minimum, mixed $geoMinimum): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAssociative')->willReturn([
            'anonymous_min_cell_count' => $minimum,
            'anonymous_geo_min_cell_count' => $geoMinimum,
        ]);

        $this->expectException(\RuntimeException::class);

        (new AnalyticsPrivacySettings($connection))->getMinimumCellCounts();
    }

    public static function invalidStoredMinimums(): iterable
    {
        yield 'hourly non-numeric' => ['five', 25];
        yield 'hourly below floor' => [1, 25];
        yield 'geography non-numeric' => [5, 'twenty-five'];
        yield 'geography below floor' => [5, 9];
    }
}
