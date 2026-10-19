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
use Doctrine\Migrations\Configuration\Configuration;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Finder\MigrationFinder;
use Doctrine\Migrations\Metadata\Storage\TableMetadataStorageConfiguration;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\Version;
use DoctrineMigrations\Version20260330000000;
use DoctrineMigrations\Version20261020000000;
use DoctrineMigrations\Version20261020000001;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once dirname(__DIR__, 2).'/migrations/Version20260330000000.php';
require_once dirname(__DIR__, 2).'/migrations/Version20261020000000.php';
require_once dirname(__DIR__, 2).'/migrations/Version20261020000001.php';

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

    /**
     * Runs both migrations through Doctrine's own migrator, which executes a
     * migration's queued SQL before its schema changes. Updating the access
     * column in the migration that adds it failed on MySQL for that reason.
     */
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

        // A dry run changes nothing. Each run gets fresh migrations, as a new command would.
        $this->runMigrations($this->migrations($connection), dryRun: true);
        self::assertNotContains('access', $this->columns($connection));

        $this->runMigrations($this->migrations($connection));
        $access = $connection->fetchAllKeyValue('SELECT username, access FROM users ORDER BY username');
        self::assertNull($access['root']);
        foreach (['viewer', 'analyst'] as $username) {
            $user = (new User())->setUsername($username);
            $user->setAccess(json_decode((string) $access[$username], true, flags: JSON_THROW_ON_ERROR));
            self::assertSame(['permission_groups' => [], 'websites' => ['all' => true, 'groups' => [], 'sites' => []], 'pages' => ['websites' => 'view']], $user->getAccess(), $username);
        }
        self::assertSame(3, (int) $connection->fetchOne('SELECT COUNT(*) FROM users'));

        // Only accounts without access are changed, so access granted since is kept on a rerun.
        $connection->update('users', ['access' => '{"pages":{"branding":"view"}}'], ['username' => 'viewer']);
        $this->runMigrations($this->migrations($connection), version: '0');
        self::assertNotContains('access', $this->columns($connection));
        self::assertSame(3, (int) $connection->fetchOne('SELECT COUNT(*) FROM users'));
        $this->migrate($connection, new Version20261020000000($connection, new NullLogger()), 'up');
        $connection->update('users', ['access' => '{"pages":{"branding":"view"}}'], ['username' => 'viewer']);
        $second = new Version20261020000001($connection, new NullLogger());
        $second->up($connection->createSchemaManager()->introspectSchema());
        foreach ($second->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
        self::assertSame('{"pages":{"branding":"view"}}', $connection->fetchOne('SELECT access FROM users WHERE username = ?', ['viewer']));
        self::assertNotNull($connection->fetchOne('SELECT access FROM users WHERE username = ?', ['analyst']));
    }

    private function migrations(Connection $connection): DependencyFactory
    {
        $configuration = new Configuration();
        $configuration->addMigrationsDirectory('DoctrineMigrations', dirname(__DIR__, 2).'/migrations');
        $configuration->setMetadataStorageConfiguration(new TableMetadataStorageConfiguration());
        $factory = DependencyFactory::fromConnection(new ExistingConfiguration($configuration), new ExistingConnection($connection));
        $factory->setService(MigrationFinder::class, new class implements MigrationFinder {
            public function findMigrations(string $directory, ?string $namespace = null): array
            {
                return [Version20261020000000::class, Version20261020000001::class];
            }
        });
        $factory->getMetadataStorage()->ensureInitialized();

        return $factory;
    }

    private function runMigrations(DependencyFactory $factory, bool $dryRun = false, string $version = 'latest'): void
    {
        $target = $version === 'latest' ? $factory->getVersionAliasResolver()->resolveVersionAlias('latest') : new Version($version);
        $plan = $factory->getMigrationPlanCalculator()->getPlanUntilVersion($target);
        $factory->getMigrator()->migrate($plan, (new MigratorConfiguration())->setDryRun($dryRun));
    }

    /** @return list<string> */
    private function columns(Connection $connection): array
    {
        return array_map(static fn (\Doctrine\DBAL\Schema\Column $column): string => strtolower($column->getName()), array_values($connection->createSchemaManager()->introspectTableByUnquotedName('users')->getColumns()));
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
