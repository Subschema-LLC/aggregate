<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use App\Entity\User;
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
use DoctrineMigrations\Version20260330000000;
use DoctrineMigrations\Version20261020000000;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once dirname(__DIR__, 2).'/migrations/Version20260330000000.php';
require_once dirname(__DIR__, 2).'/migrations/Version20261020000000.php';

final class UserAccessMigrationTest extends TestCase
{
    #[DataProvider('platforms')]
    public function testTheColumnsAreAddedAsNullableOnEveryEngine(AbstractPlatform $platform): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->method('fetchAllAssociative')->willReturn([]);
        $from = new Schema();
        (new Version20260330000000($connection, new NullLogger()))->up($from);
        $to = clone $from;
        (new Version20261020000000($connection, new NullLogger()))->up($to);

        $users = $to->getTable('users');
        foreach (['display_name' => 120, 'email' => 254] as $column => $length) {
            self::assertFalse($users->getColumn($column)->getNotnull(), $column);
            self::assertSame($length, $users->getColumn($column)->getLength(), $column);
        }
        self::assertFalse($users->getColumn('access')->getNotnull());
        $sql = implode(";\n", $platform->getAlterSchemaSQL((new Comparator($platform))->compareSchemas($from, $to)));
        if (!$platform instanceof SQLitePlatform) {
            self::assertStringNotContainsStringIgnoringCase('DROP TABLE', $sql);
        }

        $again = clone $to;
        (new Version20261020000000($connection, new NullLogger()))->up($again);
        self::assertSame([], $platform->getAlterSchemaSQL((new Comparator($platform))->compareSchemas($to, $again)), 'running it twice changes nothing');
    }

    public static function platforms(): iterable
    {
        yield 'PostgreSQL' => [new PostgreSQLPlatform()];
        yield 'MySQL 8' => [new MySQL80Platform()];
        yield 'MariaDB 10.6' => [new MariaDB1060Platform()];
        yield 'SQL Server' => [new SQLServerPlatform()];
        yield 'SQLite' => [new SQLitePlatform()];
    }

    public function testAdministratorsStayAndFormerViewersKeepTheWebsitesPage(): void
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->migrate($connection, new Version20260330000000($connection, new NullLogger()), 'up');
        foreach (['root' => '["ROLE_ADMIN","ROLE_USER"]', 'viewer' => '["ROLE_USER"]', 'analyst' => '["ROLE_ANALYST"]'] as $username => $roles) {
            $connection->insert('users', ['username' => $username, 'roles' => $roles, 'password' => 'hash', 'created_at' => '2026-10-01 00:00:00']);
        }

        $migration = new Version20261020000000($connection, new NullLogger());
        $this->migrate($connection, $migration, 'up');
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }

        $access = $connection->fetchAllKeyValue('SELECT username, access FROM users ORDER BY username');
        self::assertNull($access['root']);
        foreach (['viewer', 'analyst'] as $username) {
            $user = (new User())->setUsername($username);
            $user->setAccess(json_decode((string) $access[$username], true, flags: JSON_THROW_ON_ERROR));
            self::assertSame(['permission_groups' => [], 'websites' => ['all' => true, 'groups' => [], 'sites' => []], 'pages' => ['websites' => 'view']], $user->getAccess(), $username);
        }
        self::assertSame(3, (int) $connection->fetchOne('SELECT COUNT(*) FROM users'));

        $this->migrate($connection, $migration, 'down');
        $columns = array_map(static fn (\Doctrine\DBAL\Schema\Column $column): string => strtolower($column->getName()), array_values($connection->createSchemaManager()->introspectTableByUnquotedName('users')->getColumns()));
        self::assertSame(['id', 'username', 'roles', 'password', 'created_at'], $columns);
        self::assertSame(3, (int) $connection->fetchOne('SELECT COUNT(*) FROM users'));
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
