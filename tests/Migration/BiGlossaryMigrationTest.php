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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once dirname(__DIR__, 2).'/migrations/Version20260928000000.php';

final class BiGlossaryMigrationTest extends TestCase
{
    private const DIMENSIONS = ['event_name', 'goal_event', 'referrer_channel', 'device_class', 'viewport_bucket', 'geo_area'];

    #[DataProvider('platforms')]
    public function testPortableSchemaAndIdenticalPlainViews(AbstractPlatform $platform): void
    {
        $migration = $this->migration($platform);
        $migration->up(new Schema());
        $queries = array_map(static fn ($query): string => $query->getStatement(), $migration->getSql());
        $sql = implode("\n", $queries);
        self::assertStringContainsString('CREATE TABLE analytics_glossary', $sql);
        self::assertStringContainsString('PRIMARY KEY', $sql);
        self::assertStringContainsString('entry_type, subject, code, locale', $sql);
        self::assertStringContainsString('IDX_GLOSSARY_DEFAULT', $sql);
        self::assertSame(!$platform instanceof AbstractMySQLPlatform, $migration->isTransactional());

        $views = array_values(array_filter($queries, static fn (string $query): bool => str_starts_with($query, 'CREATE VIEW')));
        self::assertCount(8, $views);
        $sqlite = $this->migration(new SQLitePlatform());
        $sqlite->up(new Schema());
        $sqliteViews = array_values(array_filter(array_map(static fn ($query): string => $query->getStatement(), $sqlite->getSql()), static fn (string $query): bool => str_starts_with($query, 'CREATE VIEW')));
        self::assertSame($sqliteViews, $views);
        foreach ($views as $view) {
            self::assertStringContainsString('FROM analytics_glossary', $view);
            self::assertDoesNotMatchRegularExpression('/\b(?:JOIN|UNION|GROUP BY)\b/i', $view);
        }
        if ($platform instanceof AbstractMySQLPlatform) {
            self::assertStringContainsString('utf8mb4_bin', $sql);
        }
        if ($platform instanceof SQLServerPlatform) {
            self::assertStringContainsString('code NVARCHAR(191)', $sql);
            self::assertStringNotContainsString('COLLATE', $sql, 'SQL Server columns must inherit the database collation to join to existing fact columns.');
        }

        $down = $this->migration($platform);
        $down->down(new Schema());
        $downQueries = array_map(static fn ($query): string => $query->getStatement(), $down->getSql());
        self::assertCount(9, $downQueries);
        self::assertSame('DROP TABLE analytics_glossary', $downQueries[8]);
        self::assertStringContainsString($platform instanceof SQLServerPlatform ? 'IF OBJECT_ID' : 'DROP VIEW IF EXISTS', $downQueries[0]);
    }

    public static function platforms(): iterable
    {
        yield 'PostgreSQL' => [new PostgreSQLPlatform()];
        yield 'MySQL 8' => [new MySQL80Platform()];
        yield 'MariaDB 10.6' => [new MariaDB1060Platform()];
        yield 'SQL Server' => [new SQLServerPlatform()];
        yield 'SQLite' => [new SQLitePlatform()];
    }

    public function testStaticInstallFilesPublishTheSameViewContracts(): void
    {
        $migration = $this->migration(new MySQL80Platform());
        $migration->up(new Schema());
        $views = array_filter(array_map(static fn ($query): string => $query->getStatement(), $migration->getSql()), static fn (string $query): bool => str_starts_with($query, 'CREATE VIEW'));
        foreach (['docs/install.sql', 'docs/install_fresh.sql', 'migrations/sql/mariadb/20260724_prod_schema_parity.sql'] as $file) {
            $sql = file_get_contents(dirname(__DIR__, 2).'/'.$file);
            self::assertIsString($sql);
            foreach ($views as $view) {
                self::assertStringContainsString($view.';', $sql, $file);
            }
            self::assertStringContainsString('utf8mb4_bin', $sql, $file);
            self::assertStringContainsString('app:analytics:glossary:sync', $sql, $file);
        }
    }

    public function testSqliteUpDownViewContractsUniqueCodesAndFallback(): void
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $migration = new Version20260928000000($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement());
        }
        foreach (self::DIMENSIONS as $dimension) {
            foreach (['en', 'fr-CA'] as $locale) {
                foreach (['code', 'Code'] as $code) {
                    $connection->insert('analytics_glossary', [
                        'entry_type' => 'value', 'subject' => $dimension,
                        'code' => $dimension === 'geo_area' ? 'country:'.$code : $code,
                        'locale' => $locale, 'label' => 'Label', 'label_locale' => 'en',
                        'sort_order' => 10, 'is_default_locale' => $locale === 'en' ? 1 : 0,
                        'source' => 'builtin', 'synced_at' => '2026-09-28 00:00:00',
                    ]);
                }
            }
            $rows = $connection->fetchAllAssociative('SELECT * FROM bi_dim_'.$dimension.'_v1');
            self::assertCount(2, $rows);
            self::assertCount(2, array_unique(array_column($rows, $dimension)));
            $columns = [$dimension, $dimension.'_label', $dimension.'_group', $dimension.'_description', $dimension.'_sort'];
            if ($dimension === 'geo_area') {
                $columns[] = 'geo_level';
                self::assertSame('country', $rows[0]['geo_level']);
            }
            self::assertSame($columns, array_keys($rows[0]));
        }
        $connection->insert('analytics_glossary', [
            'entry_type' => 'column', 'subject' => 'bi_anonymous_events_v1', 'code' => 'event_count',
            'locale' => 'en', 'label' => 'event_count', 'label_locale' => null,
            'sort_order' => 10, 'is_default_locale' => 1, 'source' => 'builtin', 'synced_at' => '2026-09-28 00:00:00',
        ]);
        $columns = $connection->fetchAssociative('SELECT * FROM bi_glossary_columns_v1');
        self::assertSame(['object_name', 'column_name', 'locale', 'label', 'label_locale', 'is_fallback', 'description', 'is_default_locale'], array_keys($columns));
        self::assertSame(1, $columns['is_fallback']);
        self::assertSame(12, (int) $connection->fetchOne("SELECT COUNT(*) FROM bi_glossary_values_v1 WHERE locale = 'fr-CA' AND is_fallback = 1"));
        self::assertSame(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM bi_glossary_values_v1 WHERE locale = 'de'"));
        self::assertSame(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM bi_glossary_values_v1 WHERE locale = 'en' AND is_fallback = 1"));

        $down = new Version20260928000000($connection, new NullLogger());
        $down->down(new Schema());
        foreach ($down->getSql() as $query) {
            $connection->executeStatement($query->getStatement());
        }
        self::assertSame(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM sqlite_master WHERE name LIKE '%glossary%' OR name LIKE 'bi_dim_%'"));
    }

    private function migration(AbstractPlatform $platform): Version20260928000000
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);

        return new Version20260928000000($connection, new NullLogger());
    }
}
