<?php

declare(strict_types=1);

namespace App\Tests\Service\Operations;

use App\Service\Operations\QueueRetention;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

/**
 * Queued and failed enhanced events are deleted once they are older than
 * the retention period, in batches, whichever queue they wait in.
 */
final class QueueRetentionTest extends TestCase
{
    public static function queue(Connection $connection): void
    {
        $connection->executeStatement('CREATE TABLE messenger_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, body TEXT NOT NULL, headers TEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL)');
    }

    public static function message(Connection $connection, string $queue, string $createdAt): void
    {
        $connection->insert('messenger_messages', ['body' => 'O:24:"App\\Message\\TrackEventMessage"', 'headers' => '[]', 'queue_name' => $queue, 'created_at' => $createdAt, 'available_at' => $createdAt]);
    }

    public function testOldMessagesInEveryQueueAreDeletedInBatches(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        self::queue($connection);
        foreach (range(1, 5) as $day) {
            self::message($connection, 'default', sprintf('2026-11-%02d 12:00:00', $day));
        }
        self::message($connection, 'failed', '2026-10-01 08:00:00');
        self::message($connection, 'failed', '2026-11-10 08:00:00');
        $queue = new QueueRetention($connection);
        $batches = 0;

        self::assertSame(4, $queue->countBefore(new \DateTimeImmutable('2026-11-04 00:00:00 UTC')));
        self::assertSame(4, $queue->purgeBefore(new \DateTimeImmutable('2026-11-04 00:00:00 UTC'), 2, static function () use (&$batches): void {
            ++$batches;
        }));
        self::assertSame(3, $batches, 'Two full batches, then one that finds nothing.');

        $summary = $queue->summary();
        self::assertSame(['default', 'failed'], array_keys($summary));
        self::assertSame([2, '2026-11-04 12:00'], [$summary['default']['messages'], $summary['default']['oldest']?->format('Y-m-d H:i')]);
        self::assertSame([1, '2026-11-10 08:00'], [$summary['failed']['messages'], $summary['failed']['oldest']?->format('Y-m-d H:i')]);
    }

    public function testAnInstallationThatNeverQueuedHasNothingToDelete(): void
    {
        $queue = new QueueRetention(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]));

        self::assertFalse($queue->exists());
        self::assertSame(0, $queue->countBefore(new \DateTimeImmutable()));
        self::assertSame(0, $queue->purgeBefore(new \DateTimeImmutable(), 100));
        self::assertSame([], $queue->summary());
    }
}
