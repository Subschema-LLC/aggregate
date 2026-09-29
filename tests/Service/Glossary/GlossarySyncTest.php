<?php

declare(strict_types=1);

namespace App\Tests\Service\Glossary;

use App\Command\SyncGlossaryCommand;
use App\Service\AggregateConfigLoader;
use App\Service\CustomDataSettings;
use App\Service\GeoIp\GeoArea;
use App\Service\Glossary\BiGlossarySettings;
use App\Service\Glossary\BuiltinGlossaryCatalog;
use App\Service\Glossary\GlossaryResolver;
use App\Service\Glossary\GlossarySync;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Logging\Middleware;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20260928000000;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Intl\Countries;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Yaml\Yaml;

require_once dirname(__DIR__, 3).'/migrations/Version20260928000000.php';

final class GlossarySyncTest extends TestCase
{
    public function testDiffIgnoresDriverIntegerTypesAndRowOrder(): void
    {
        $expected = [$this->row('first'), $this->row('second')];
        $actual = array_reverse($expected);
        foreach ($actual as &$row) {
            $row['sort_order'] = (string) $row['sort_order'];
            $row['is_default_locale'] = (string) $row['is_default_locale'];
        }
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchAllAssociative')->willReturn($actual);
        $connection->expects(self::never())->method('executeStatement');
        $sync = new GlossarySync($connection, $this->resolver($expected));

        self::assertSame(['insert' => 0, 'change' => 0, 'delete' => 0, 'total' => 2, 'changed' => false], $sync->diff());
    }

    public function testDiffCountsAddedChangedAndDeletedEntries(): void
    {
        $old = $this->row('edited');
        $edited = $old;
        $edited['description'] = 'New definition';
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([$old, $this->row('removed')]);
        $sync = new GlossarySync($connection, $this->resolver([$edited, $this->row('added')]));

        self::assertSame(['insert' => 1, 'change' => 1, 'delete' => 1, 'total' => 2, 'changed' => true], $sync->diff());
    }

    #[DataProvider('batchPlatforms')]
    public function testBoundInsertsStayWithinPlatformParameterLimits(AbstractPlatform $platform, int $limit, int $batches): void
    {
        $rows = array_map(fn (int $number): array => $this->row('code'.$number), range(1, 205));
        $rows[0]['label'] = "Operator's label; DROP TABLE events;";
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->method('transactional')->willReturnCallback(static fn (\Closure $callback): array => $callback());
        $connection->method('fetchAllAssociative')->willReturn([]);
        $inserted = 0;
        $connection->expects(self::exactly($batches + 1))->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $params = [], array $types = []) use ($limit, &$inserted): int {
                self::assertStringNotContainsString('Operator', $sql);
                self::assertStringNotContainsString('DROP TABLE', $sql);
                if (str_starts_with($sql, 'INSERT')) {
                    self::assertLessThanOrEqual($limit, count($params));
                    self::assertCount(count($params), $types);
                    self::assertSame(count($params), substr_count($sql, '?'));
                    $inserted += intdiv(count($params), 13);
                } else {
                    self::assertSame('DELETE FROM analytics_glossary', $sql);
                }

                return 1;
            },
        );

        $summary = (new GlossarySync($connection, $this->resolver($rows)))->sync();
        self::assertSame(205, $inserted);
        self::assertSame(205, $summary['written']);
        self::assertSame(1, $summary['dimensions']);
        self::assertSame(['en'], $summary['locales']);
        self::assertSame(['en' => 0], $summary['fallback_counts']);
    }

    public static function batchPlatforms(): iterable
    {
        yield 'SQLite legacy parameter limit' => [new SQLitePlatform(), 999, 3];
        yield 'SQL Server' => [new SQLServerPlatform(), 2100, 2];
        yield 'MySQL' => [new MySQL80Platform(), 65535, 2];
    }

    #[DataProvider('transientFailures')]
    public function testRetriesOneTransientTransactionFailure(\Throwable $exception): void
    {
        $connection = $this->createMock(Connection::class);
        $attempts = 0;
        $connection->expects(self::exactly(2))->method('transactional')->willReturnCallback(function (\Closure $callback) use (&$attempts, $exception): array {
            if (++$attempts === 1) {
                throw $exception;
            }

            return $callback();
        });
        $connection->method('fetchAllAssociative')->willReturn([$this->row('same')]);
        $connection->expects(self::never())->method('executeStatement');
        $summary = (new GlossarySync($connection, $this->resolver([$this->row('same')])))->sync();

        self::assertFalse($summary['changed']);
        self::assertSame(0, $summary['written']);
    }

    public static function transientFailures(): iterable
    {
        yield 'lock timeout or deadlock' => [new class('Database is locked') extends \RuntimeException implements RetryableException {}];
        $driverError = new class('Concurrent replacement conflict') extends \RuntimeException implements \Doctrine\DBAL\Driver\Exception {
            public function getSQLState(): ?string
            {
                return '23505';
            }
        };
        yield 'concurrent replacement unique conflict' => [new UniqueConstraintViolationException($driverError, null)];
    }

    public function testRepeatedLockFailureHasAnActionableMessage(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(2))->method('transactional')->willThrowException($this->lockException());
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Retried once; please run app:analytics:glossary:sync again.');

        (new GlossarySync($connection, $this->resolver([])))->sync();
    }

    public function testRepeatedUniqueConflictExplainsDatabaseCollationInsteadOfClaimingOnlyALock(): void
    {
        $failures = iterator_to_array(self::transientFailures());
        $exception = $failures['concurrent replacement unique conflict'][0];
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(2))->method('transactional')->willThrowException($exception);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('collation');

        (new GlossarySync($connection, $this->resolver([])))->sync();
    }

    public function testSqliteSyncIsIdempotentAndMiddlewareProvesItNeverReadsEventData(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $statements = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                if (isset($context['sql'])) {
                    TestCase::assertIsString($context['sql']);
                    $this->statements[] = $context['sql'];
                }
            }
        };
        $connection = $this->sqlite($logger);
        $rows = [$this->row('declared')];
        $translated = $rows[0];
        $translated['locale'] = 'fr-CA';
        $translated['is_default_locale'] = 0;
        $rows[] = $translated;
        $sync = new GlossarySync($connection, $this->resolver($rows));
        $logger->statements = [];
        $summary = $sync->sync();
        self::assertSame(2, $summary['written']);
        self::assertSame(['en' => 0, 'fr-CA' => 1], $summary['fallback_counts']);
        $firstStatements = $logger->statements;

        $connection->executeStatement("UPDATE analytics_glossary SET synced_at = '2000-01-01 00:00:00'");
        $logger->statements = [];
        $summary = $sync->sync();
        self::assertSame(0, $summary['written']);
        self::assertFalse($summary['changed']);
        self::assertCount(1, $logger->statements);
        self::assertStringStartsWith('SELECT', $logger->statements[0]);
        self::assertSame('2000-01-01 00:00:00', $connection->fetchOne('SELECT synced_at FROM analytics_glossary'));
        foreach (array_merge($firstStatements, $logger->statements) as $sql) {
            self::assertDoesNotMatchRegularExpression('/\bevents\b|analytics_archive_|\b(?:bi_anonymous|analytics_custom)_/i', $sql);
            self::assertDoesNotMatchRegularExpression('/\b(?:CREATE|DROP|ALTER)\b/i', $sql);
        }
    }

    public function testSqliteFailureInALaterBatchRollsBackTheCompleteReplacement(): void
    {
        $connection = $this->sqlite();
        $original = [$this->row('original')];
        (new GlossarySync($connection, $this->resolver($original)))->sync();
        $before = $connection->fetchAllAssociative('SELECT * FROM analytics_glossary');
        $connection->executeStatement("CREATE TRIGGER reject_glossary_insert BEFORE INSERT ON analytics_glossary WHEN NEW.code = 'code80' BEGIN SELECT RAISE(ABORT, 'insert failed'); END");
        $rows = array_map(fn (int $number): array => $this->row('code'.$number), range(1, 100));
        try {
            (new GlossarySync($connection, $this->resolver($rows)))->sync();
            self::fail('The trigger should reject the second insertion batch.');
        } catch (\Doctrine\DBAL\Exception $exception) {
            self::assertStringContainsString('insert failed', $exception->getMessage());
        }
        self::assertSame($before, $connection->fetchAllAssociative('SELECT * FROM analytics_glossary'));
        self::assertFalse($connection->isTransactionActive());
    }

    public function testActualYamlCatalogResolverAndCommandPublishCompleteSqliteContracts(): void
    {
        $connection = $this->sqlite();
        $projectDir = sys_get_temp_dir().'/aggregate-glossary-integration-'.bin2hex(random_bytes(8));
        mkdir($projectDir.'/config', 0700, true);
        $environment = [$_ENV, $_SERVER];
        foreach (['ANONYMOUS_TRACKING_ENABLED', 'ANONYMOUS_EXCLUDED_PATHS'] as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
        }
        try {
            file_put_contents($projectDir.'/config/aggregate.yaml', "{}\n");
            $loader = new AggregateConfigLoader($projectDir, 'test');
            $translator = new Translator('en');
            $translator->setFallbackLocales(['en']);
            $translator->addLoader('yaml', new YamlFileLoader());
            $translator->addResource('yaml', dirname(__DIR__, 3).'/translations/bi_glossary.en.yaml', 'en', 'bi_glossary');
            $catalog = new BuiltinGlossaryCatalog($translator);
            $settings = new BiGlossarySettings($loader, new CustomDataSettings($loader), $catalog, [
                'contact' => ['label' => 'Contact request', 'enabled' => true, 'anonymous' => true],
            ]);
            $sync = new GlossarySync($connection, new GlossaryResolver($settings, $catalog));
            $command = new CommandTester(new SyncGlossaryCommand($sync));

            self::assertSame(Command::SUCCESS, $command->execute([]));
            self::assertSame(5, (int) $connection->fetchOne('SELECT COUNT(*) FROM bi_dim_device_class_v1'));
            self::assertSame('Tablet', $connection->fetchOne("SELECT device_class_label FROM bi_dim_device_class_v1 WHERE device_class = 'tablet'"));
            self::assertSame(count(GeoArea::CONTINENT_CODES) + count(Countries::getCountryCodes()), (int) $connection->fetchOne('SELECT COUNT(*) FROM bi_dim_geo_area_v1'));
            self::assertSame(Command::SUCCESS, $command->execute(['--check' => true]));
            $connection->executeStatement("UPDATE analytics_glossary SET synced_at = '2000-01-01 00:00:00'");
            self::assertSame(0, $sync->sync()['written']);
            self::assertSame('2000-01-01 00:00:00', $connection->fetchOne('SELECT synced_at FROM analytics_glossary'));

            file_put_contents($projectDir.'/config/aggregate.yaml', Yaml::dump(['bi_glossary' => [
                'locales' => ['en', 'es', 'fr-CA'],
                'values' => ['device_class' => ['tablet' => ['label' => ['es' => 'Tableta']]]],
            ]], 8, 2));
            $loader->reset();
            self::assertSame(Command::FAILURE, $command->execute(['--check' => true]));
            self::assertSame(Command::SUCCESS, $command->execute([]));
            self::assertSame(Command::SUCCESS, $command->execute(['--check' => true]));
            self::assertSame(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM bi_glossary_values_v1 WHERE label IS NULL OR label = ''"));
            self::assertSame('Tableta', $connection->fetchOne("SELECT label FROM bi_glossary_values_v1 WHERE dimension = 'device_class' AND code = 'tablet' AND locale = 'es'"));
            self::assertSame(1, (int) $connection->fetchOne("SELECT is_fallback FROM bi_glossary_values_v1 WHERE dimension = 'device_class' AND code = 'tablet' AND locale = 'fr-CA'"));
            $counts = [];
            foreach ($connection->fetchAllAssociative('SELECT dimension, locale, COUNT(*) AS total FROM bi_glossary_values_v1 GROUP BY dimension, locale') as $row) {
                $counts[$row['dimension']][$row['locale']] = (int) $row['total'];
            }
            foreach ($counts as $locales) {
                self::assertCount(3, $locales);
                self::assertCount(1, array_unique(array_values($locales)));
            }
            foreach (BuiltinGlossaryCatalog::DIMENSIONS as $dimension) {
                self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM (SELECT '.$dimension.' FROM bi_dim_'.$dimension.'_v1 GROUP BY '.$dimension.' HAVING COUNT(*) <> 1) invalid_keys'));
            }
        } finally {
            [$_ENV, $_SERVER] = $environment;
            unlink($projectDir.'/config/aggregate.yaml');
            rmdir($projectDir.'/config');
            rmdir($projectDir);
        }
    }

    private function sqlite(?AbstractLogger $logger = null): Connection
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
        $configuration = new Configuration();
        if ($logger !== null) {
            $configuration->setMiddlewares([new Middleware($logger)]);
        }
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration);
        $migration = new Version20260928000000($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement());
        }

        return $connection;
    }

    private function row(string $code): array
    {
        return [
            'entry_type' => 'value', 'subject' => 'event_name', 'code' => $code,
            'locale' => 'en', 'label' => 'Declared name', 'label_locale' => 'en',
            'group_label' => null, 'description' => null, 'description_locale' => null,
            'sort_order' => 10, 'is_default_locale' => 1, 'source' => 'glossary',
        ];
    }

    private function resolver(array $rows): GlossaryResolver
    {
        $resolver = $this->createStub(GlossaryResolver::class);
        $resolver->method('resolve')->willReturn($rows);

        return $resolver;
    }

    private function lockException(): RetryableException
    {
        return new class('Database is locked') extends \RuntimeException implements RetryableException {};
    }
}
