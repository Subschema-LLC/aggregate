<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDB1060Platform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261007000000;
use DoctrineMigrations\Version20261007210000;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once dirname(__DIR__, 2).'/migrations/Version20261007000000.php';
require_once dirname(__DIR__, 2).'/migrations/Version20261007210000.php';

final class OperationsTablesMigrationTest extends TestCase
{
    #[DataProvider('platforms')]
    public function testTheGenericTablesReplaceTheBigQueryTablePortably(AbstractPlatform $platform): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $schema = new Schema();
        (new Version20261007000000($connection, new NullLogger()))->up($schema);
        (new Version20261007210000($connection, new NullLogger()))->up($schema);

        self::assertFalse($schema->hasTable('analytics_bigquery_sync'));
        self::assertSame(
            ['id', 'task_type', 'subject', 'status', 'triggered_by', 'requested_by', 'lock_key', 'started_at', 'finished_at', 'row_count', 'external_id', 'details'],
            self::columns($schema, 'processing_tasks'),
        );
        self::assertSame(
            ['id', 'occurred_at', 'category', 'operation', 'subject', 'outcome', 'actor', 'processing_task_id', 'details'],
            self::columns($schema, 'audit_trail'),
        );
        $sql = implode(";\n", $platform->getCreateTablesSQL([$schema->getTable('processing_tasks'), $schema->getTable('audit_trail')]));
        self::assertMatchesRegularExpression('/UNIQUE INDEX UNIQ_PROCESSING_TASKS_LOCK (ON processing_tasks )?\(lock_key\)/', $sql);
        if ($platform instanceof SQLServerPlatform) {
            // Several finished rows have no lock key; SQL Server needs the filter.
            self::assertStringContainsString('(lock_key) WHERE lock_key IS NOT NULL', $sql);
        }
        if ($platform instanceof AbstractMySQLPlatform) {
            self::assertStringContainsString('utf8mb4', $sql);
        }
    }

    public static function platforms(): iterable
    {
        yield 'PostgreSQL' => [new PostgreSQLPlatform()];
        yield 'MySQL 8' => [new MySQL80Platform()];
        yield 'MariaDB 10.6' => [new MariaDB1060Platform()];
        yield 'SQL Server' => [new SQLServerPlatform()];
        yield 'SQLite' => [new SQLitePlatform()];
    }

    public function testSqliteUpgradeFromTheBigQueryTableAndBack(): void
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->migrate($connection, new Version20261007000000($connection, new NullLogger()), 'up');
        $connection->insert('analytics_bigquery_sync', ['name' => 'bi_anonymous_events_v1', 'status' => 'success', 'updated_at' => '2026-10-07 12:00:00']);

        $migration = new Version20261007210000($connection, new NullLogger());
        $this->migrate($connection, $migration, 'up');
        $manager = $connection->createSchemaManager();
        self::assertFalse($manager->tablesExist(['analytics_bigquery_sync']));
        self::assertTrue($manager->tablesExist(['processing_tasks', 'audit_trail']));
        $connection->insert('processing_tasks', ['task_type' => 'bigquery_sync', 'status' => 'succeeded', 'triggered_by' => 'schedule', 'started_at' => '2026-10-07 12:00:00']);
        $connection->insert('processing_tasks', ['task_type' => 'bigquery_sync', 'status' => 'succeeded', 'triggered_by' => 'schedule', 'started_at' => '2026-10-07 12:00:00']);
        self::assertSame('2', (string) $connection->lastInsertId(), 'Several rows without a lock key are allowed.');

        $this->migrate($connection, $migration, 'down');
        self::assertTrue($manager->tablesExist(['analytics_bigquery_sync']));
        self::assertFalse($manager->tablesExist(['processing_tasks']));
        self::assertStringContainsString('processing_tasks', $migration->getDescription());
    }

    private function migrate(Connection $connection, \Doctrine\Migrations\AbstractMigration $migration, string $direction): void
    {
        $from = $connection->createSchemaManager()->introspectSchema();
        $to = clone $from;
        $migration->{$direction}($to);
        $diff = $connection->createSchemaManager()->createComparator()->compareSchemas($from, $to);
        foreach ($connection->getDatabasePlatform()->getAlterSchemaSQL($diff) as $sql) {
            $connection->executeStatement($sql);
        }
    }

    /** @return list<string> */
    private static function columns(Schema $schema, string $table): array
    {
        return array_values(array_map(static fn (Column $column): string => $column->getName(), $schema->getTable($table)->getColumns()));
    }
}
