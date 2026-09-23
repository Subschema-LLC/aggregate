<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20260724000500;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once dirname(__DIR__, 2).'/migrations/Version20260724000500.php';

final class PrivacyCleanupMigrationTest extends TestCase
{
    public function testCleanupPurgesOnlyUntrustedEnhancedRowsAndTrackerEnvelopes(): void
    {
        $schema = new Schema();
        $events = $schema->createTable('events');
        $events->addColumn('id', 'integer');
        $events->setPrimaryKey(['id']);
        $messages = $schema->createTable('messenger_messages');
        $messages->addColumn('id', 'bigint');
        $messages->setPrimaryKey(['id']);

        $migration = new Version20260724000500(
            $this->createStub(Connection::class),
            $this->createStub(LoggerInterface::class),
        );
        $migration->up($schema);

        self::assertTrue($schema->hasTable('events'));
        self::assertTrue($schema->hasTable('messenger_messages'));
        self::assertCount(1, $schema->getTable('events')->getColumns());
        self::assertTrue($schema->getTable('events')->hasColumn('id'));
        self::assertCount(1, $schema->getTable('messenger_messages')->getColumns());
        self::assertTrue($schema->getTable('messenger_messages')->hasColumn('id'));

        $statements = array_map(
            static fn ($query): string => $query->getStatement(),
            $migration->getSql(),
        );

        self::assertCount(2, $statements);
        self::assertSame(
            "DELETE FROM events WHERE privacy_mode = 'enhanced' AND (consent_state IS NULL OR consent_state <> 'granted')",
            $statements[0],
        );
        self::assertStringContainsString("body LIKE '%TrackEventMessage%'", $statements[1]);
        self::assertStringContainsString("headers LIKE '%TrackEventMessage%'", $statements[1]);
        self::assertStringNotContainsString("privacy_mode = 'anonymous'", $statements[0]);
    }
}
