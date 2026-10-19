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
use DoctrineMigrations\Version20261019000000;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once dirname(__DIR__, 2).'/migrations/Version20261007210000.php';
require_once dirname(__DIR__, 2).'/migrations/Version20261018000000.php';
require_once dirname(__DIR__, 2).'/migrations/Version20261019000000.php';

final class AuditTrailIndexesMigrationTest extends TestCase
{
    private const INDEXES = [
        'idx_audit_trail_category' => ['category', 'occurred_at'],
        'idx_audit_trail_operation' => ['operation'],
        'idx_audit_trail_actor' => ['actor'],
    ];

    #[DataProvider('platforms')]
    public function testTheIndexesAreAddedWithoutRebuildingTheTableOnEveryEngine(AbstractPlatform $platform): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $from = new Schema();
        (new Version20261007210000($connection, new NullLogger()))->up($from);
        (new Version20261018000000($connection, new NullLogger()))->up($from);
        $to = clone $from;
        (new Version20261019000000($connection, new NullLogger()))->up($to);

        foreach (self::INDEXES as $name => $columns) {
            self::assertSame($columns, $to->getTable('audit_trail')->getIndex($name)->getColumns());
        }
        $sql = implode(";\n", $platform->getAlterSchemaSQL((new Comparator($platform))->compareSchemas($from, $to)));
        if (!$platform instanceof SQLitePlatform) {
            // SQLite copies the table to change it; the upgrade test below shows the rows are kept.
            self::assertSame(3, substr_count(strtoupper($sql), 'CREATE INDEX'));
            self::assertStringNotContainsStringIgnoringCase('DROP TABLE', $sql);
        }

        $again = clone $to;
        (new Version20261019000000($connection, new NullLogger()))->up($again);
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

    public function testSqliteUpgradeKeepsTheEntriesAndDowngradeRemovesTheIndexes(): void
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->migrate($connection, new Version20261007210000($connection, new NullLogger()), 'up');
        $connection->insert('audit_trail', ['occurred_at' => '2026-10-17 12:00:00', 'category' => 'task', 'operation' => 'bigquery_sync', 'outcome' => 'succeeded']);

        $migration = new Version20261019000000($connection, new NullLogger());
        $this->migrate($connection, $migration, 'up');
        $indexes = array_change_key_case($connection->createSchemaManager()->introspectTableByUnquotedName('audit_trail')->getIndexes());
        foreach (array_keys(self::INDEXES) as $name) {
            self::assertArrayHasKey($name, $indexes);
        }
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM audit_trail'));

        $this->migrate($connection, $migration, 'down');
        $indexes = array_change_key_case($connection->createSchemaManager()->introspectTableByUnquotedName('audit_trail')->getIndexes());
        foreach (array_keys(self::INDEXES) as $name) {
            self::assertArrayNotHasKey($name, $indexes);
        }
        self::assertArrayHasKey('idx_audit_trail_occurred', $indexes);
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM audit_trail'));
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
