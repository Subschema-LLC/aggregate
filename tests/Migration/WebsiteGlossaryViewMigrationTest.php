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
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20260928000000;
use DoctrineMigrations\Version20261001000000;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once dirname(__DIR__, 2).'/migrations/Version20260928000000.php';
require_once dirname(__DIR__, 2).'/migrations/Version20261001000000.php';

final class WebsiteGlossaryViewMigrationTest extends TestCase
{
    #[DataProvider('platforms')]
    public function testThePlainViewMatchesTheOtherDimensionViewsOnEveryEngine(AbstractPlatform $platform): void
    {
        $migration = $this->migration($platform);
        $migration->up(new Schema());
        $queries = array_map(static fn ($query): string => $query->getStatement(), $migration->getSql());

        self::assertCount(1, $queries);
        self::assertSame(!$platform instanceof AbstractMySQLPlatform, $migration->isTransactional());
        // The same portable SQL as the six views created with the glossary table.
        $original = $this->original($platform);
        $original->up(new Schema());
        $deviceView = array_values(array_filter(array_map(static fn ($query): string => $query->getStatement(), $original->getSql()), static fn (string $sql): bool => str_starts_with($sql, 'CREATE VIEW bi_dim_device_class_v1')))[0];
        self::assertSame(str_replace('device_class', 'website_token', $deviceView), $queries[0]);

        $down = $this->migration($platform);
        $down->down(new Schema());
        $downQueries = array_map(static fn ($query): string => $query->getStatement(), $down->getSql());
        self::assertCount(1, $downQueries);
        self::assertStringContainsString($platform instanceof SQLServerPlatform ? "IF OBJECT_ID('bi_dim_website_token_v1', 'V')" : 'DROP VIEW IF EXISTS bi_dim_website_token_v1', $downQueries[0]);
    }

    public static function platforms(): iterable
    {
        yield 'PostgreSQL' => [new PostgreSQLPlatform()];
        yield 'MySQL 8' => [new MySQL80Platform()];
        yield 'MariaDB 10.6' => [new MariaDB1060Platform()];
        yield 'SQL Server' => [new SQLServerPlatform()];
        yield 'SQLite' => [new SQLitePlatform()];
    }

    public function testSqliteViewPublishesOneDefaultLocaleRowPerWebsite(): void
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach ([new Version20260928000000($connection, new NullLogger()), new Version20261001000000($connection, new NullLogger())] as $migration) {
            $migration->up(new Schema());
            foreach ($migration->getSql() as $query) {
                $connection->executeStatement($query->getStatement());
            }
        }
        foreach (['en' => 1, 'es' => 0] as $locale => $default) {
            $connection->insert('analytics_glossary', [
                'entry_type' => 'value', 'subject' => 'website_token', 'code' => str_repeat('a', 32),
                'locale' => $locale, 'label' => $locale === 'en' ? 'Online shop' : 'Tienda en línea', 'label_locale' => $locale,
                'description' => 'shop.example.com', 'sort_order' => 10, 'is_default_locale' => $default,
                'source' => 'config', 'synced_at' => '2026-10-01 00:00:00',
            ]);
        }
        $connection->insert('analytics_glossary', [
            'entry_type' => 'value', 'subject' => 'device_class', 'code' => 'tablet', 'locale' => 'en', 'label' => 'Tablet',
            'label_locale' => 'en', 'sort_order' => 20, 'is_default_locale' => 1, 'source' => 'builtin', 'synced_at' => '2026-10-01 00:00:00',
        ]);

        self::assertSame([[
            'website_token' => str_repeat('a', 32), 'website_token_label' => 'Online shop', 'website_token_group' => null,
            'website_token_description' => 'shop.example.com', 'website_token_sort' => 10,
        ]], $connection->fetchAllAssociative('SELECT * FROM bi_dim_website_token_v1'));
        self::assertSame('Tienda en línea', $connection->fetchOne("SELECT label FROM bi_glossary_values_v1 WHERE dimension = 'website_token' AND locale = 'es'"));

        $down = new Version20261001000000($connection, new NullLogger());
        $down->down(new Schema());
        foreach ($down->getSql() as $query) {
            $connection->executeStatement($query->getStatement());
        }
        self::assertSame(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM sqlite_master WHERE name = 'bi_dim_website_token_v1'"));
        self::assertSame(1, (int) $connection->fetchOne("SELECT COUNT(*) FROM sqlite_master WHERE name = 'bi_dim_device_class_v1'"));
    }

    private function migration(AbstractPlatform $platform): Version20261001000000
    {
        return new Version20261001000000($this->connection($platform), new NullLogger());
    }

    private function original(AbstractPlatform $platform): Version20260928000000
    {
        return new Version20260928000000($this->connection($platform), new NullLogger());
    }

    private function connection(AbstractPlatform $platform): Connection
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);

        return $connection;
    }
}
