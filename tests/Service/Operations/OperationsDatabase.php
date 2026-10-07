<?php

declare(strict_types=1);

namespace App\Tests\Service\Operations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261007210000;
use PHPUnit\Framework\Assert;
use Psr\Log\NullLogger;

require_once dirname(__DIR__, 3).'/migrations/Version20261007210000.php';

/** An in-memory SQLite database with the processing_tasks and audit_trail tables from their migration. */
final class OperationsDatabase
{
    public static function connection(): Connection
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            Assert::markTestSkipped('pdo_sqlite is required.');
        }
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $schema = new Schema();
        (new Version20261007210000($connection, new NullLogger()))->up($schema);
        foreach ($connection->getDatabasePlatform()->getCreateTablesSQL($schema->getTables()) as $sql) {
            $connection->executeStatement($sql);
        }

        return $connection;
    }
}
