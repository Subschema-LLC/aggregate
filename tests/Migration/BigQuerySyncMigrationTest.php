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
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261007000000;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once dirname(__DIR__, 2).'/migrations/Version20261007000000.php';

final class BigQuerySyncMigrationTest extends TestCase
{
    #[DataProvider('platforms')]
    public function testTheStatusTableIsCreatedPortablyAndHoldsNoEventData(AbstractPlatform $platform): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $schema = new Schema();
        (new Version20261007000000($connection, new NullLogger()))->up($schema);

        $table = $schema->getTable('analytics_bigquery_sync');
        self::assertSame(['name', 'status', 'owner_token', 'started_at', 'finished_at', 'succeeded_at', 'row_count', 'message', 'job_id', 'updated_at'], array_map(static fn (\Doctrine\DBAL\Schema\Column $column): string => $column->getName(), $table->getColumns()));
        $sql = implode(";\n", $platform->getCreateTablesSQL($schema->getTables()));
        self::assertStringContainsString('analytics_bigquery_sync', $sql);
        self::assertMatchesRegularExpression('/PRIMARY KEY\s*\(\s*["`\[]?name/i', $sql);
        if ($platform instanceof MySQL80Platform) {
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

    public function testSqliteUpAndDown(): void
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $migration = new Version20261007000000($connection, new NullLogger());
        $schema = new Schema();
        $migration->up($schema);
        foreach ($connection->getDatabasePlatform()->getCreateTablesSQL($schema->getTables()) as $sql) {
            $connection->executeStatement($sql);
        }
        self::assertTrue($connection->createSchemaManager()->tablesExist(['analytics_bigquery_sync']));
        $connection->insert('analytics_bigquery_sync', ['name' => 'bi_anonymous_events_v1', 'status' => 'idle', 'updated_at' => '2026-10-07 12:00:00']);

        $migration->down($schema);
        self::assertFalse($schema->hasTable('analytics_bigquery_sync'));
        self::assertStringContainsString('BigQuery', $migration->getDescription());
    }
}
