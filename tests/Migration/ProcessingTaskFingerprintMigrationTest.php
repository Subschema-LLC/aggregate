<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDB1060Platform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use DoctrineMigrations\Version20261007210000;
use DoctrineMigrations\Version20261018000000;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once dirname(__DIR__, 2).'/migrations/Version20261007210000.php';
require_once dirname(__DIR__, 2).'/migrations/Version20261018000000.php';

final class ProcessingTaskFingerprintMigrationTest extends TestCase
{
    #[DataProvider('platforms')]
    public function testTheColumnIsAddedAsOneNullableColumnOnEveryEngine(AbstractPlatform $platform): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $from = new Schema();
        (new Version20261007210000($connection, new NullLogger()))->up($from);
        $to = clone $from;
        (new Version20261018000000($connection, new NullLogger()))->up($to);

        $column = $to->getTable('processing_tasks')->getColumn('fingerprint');
        self::assertFalse($column->getNotnull());
        self::assertSame(64, $column->getLength());
        $sql = implode(";\n", $platform->getAlterSchemaSQL((new Comparator($platform))->compareSchemas($from, $to)));
        self::assertStringContainsStringIgnoringCase('fingerprint', $sql);
        self::assertStringNotContainsStringIgnoringCase('DROP TABLE', $sql, 'Adding the column does not rebuild the table');

        // Applying it again changes nothing.
        $again = clone $to;
        (new Version20261018000000($connection, new NullLogger()))->up($again);
        self::assertSame([], $platform->getAlterSchemaSQL((new Comparator($platform))->compareSchemas($to, $again)));
    }

    public static function platforms(): iterable
    {
        yield 'PostgreSQL' => [new PostgreSQLPlatform()];
        yield 'MySQL 8' => [new MySQL80Platform()];
        yield 'MariaDB 10.6' => [new MariaDB1060Platform()];
        yield 'SQL Server' => [new SQLServerPlatform()];
        yield 'SQLite' => [new SQLitePlatform()];
    }

    public function testSqliteUpgradeKeepsTheRowsAndDowngradeRemovesTheColumn(): void
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->migrate($connection, new Version20261007210000($connection, new NullLogger()), 'up');
        $connection->insert('processing_tasks', ['task_type' => 'bigquery_sync', 'subject' => 'bi_anonymous_events_v1', 'status' => 'succeeded', 'triggered_by' => 'schedule', 'started_at' => '2026-10-17 12:00:00']);

        $migration = new Version20261018000000($connection, new NullLogger());
        $this->migrate($connection, $migration, 'up');
        self::assertSame([['subject' => 'bi_anonymous_events_v1', 'fingerprint' => null]], $connection->fetchAllAssociative('SELECT subject, fingerprint FROM processing_tasks'));
        $connection->executeStatement("UPDATE processing_tasks SET fingerprint = 'abc'");

        $this->migrate($connection, $migration, 'down');
        self::assertArrayNotHasKey('fingerprint', $connection->createSchemaManager()->introspectTableByUnquotedName('processing_tasks')->getColumns());
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM processing_tasks'));
        self::assertStringContainsString('fingerprint', $migration->getDescription());
    }

    private function migrate(Connection $connection, AbstractMigration $migration, string $direction): void
    {
        $from = $connection->createSchemaManager()->introspectSchema();
        $to = clone $from;
        $migration->{$direction}($to);
        $diff = $connection->createSchemaManager()->createComparator()->compareSchemas($from, $to);
        foreach ($connection->getDatabasePlatform()->getAlterSchemaSQL($diff) as $sql) {
            $connection->executeStatement($sql);
        }
    }
}
