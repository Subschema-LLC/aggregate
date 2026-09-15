<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\CustomDataSettings;
use App\Service\ReportingViewManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class ReportingViewManagerTest extends TestCase
{
    #[DataProvider('platforms')]
    public function testPreviewUsesPlatformJsonFunctionsAndLiteralTopLevelKeys(AbstractPlatform $platform, string $verb, string $function): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->expects(self::never())->method('executeStatement');
        $connection->expects(self::never())->method('executeQuery');
        $sql = $this->manager($connection, ['campaign' => 'campaign.name', 'staff' => 'org-internal'])->previewSql();

        self::assertSame(ReportingViewManager::VIEW_NAMES, array_keys($sql));
        foreach ($sql as $name => $definition) {
            $qualifiedName = $platform instanceof SqlitePlatform ? 'main.'.$name : $name;
            self::assertStringContainsString($verb.' VIEW '.$platform->quoteIdentifier($qualifiedName), $definition);
            self::assertStringContainsString($function, $definition);
            self::assertStringContainsString('events.url AS page_path', $definition);
            self::assertStringContainsString('events.archived_at', $definition);
            self::assertStringContainsString(' AS '.$platform->quoteIdentifier('campaign'), $definition);
            self::assertStringNotContainsString('events.visitor_id', $definition);
            self::assertStringNotContainsString('events.session_id', $definition);
            self::assertStringNotContainsString('analytics_archive_', $definition);
            self::assertStringNotContainsString("privacy_mode = 'anonymous'", $definition);
            self::assertStringNotContainsString('archived_at IS NULL', $definition);
            if ($platform instanceof PostgreSQLPlatform) {
                self::assertStringContainsString("->> 'campaign.name'", $definition);
                self::assertStringContainsString("->> 'org-internal'", $definition);
            } else {
                self::assertStringContainsString('$."campaign.name"', $definition);
                self::assertStringContainsString('$."org-internal"', $definition);
            }
        }
        self::assertStringContainsString("events.event_name = 'view'", $sql[ReportingViewManager::VIEW_NAMES[1]]);
        self::assertStringContainsString('events.goal_event IS NOT NULL', $sql[ReportingViewManager::VIEW_NAMES[2]]);
    }

    public static function platforms(): iterable
    {
        yield 'PostgreSQL' => [new PostgreSQLPlatform(), 'CREATE OR REPLACE', 'jsonb_typeof'];
        yield 'MySQL' => [new MySQL80Platform(), 'CREATE OR REPLACE', 'JSON_TYPE'];
        yield 'MariaDB' => [new MariaDBPlatform(), 'CREATE OR REPLACE', 'JSON_TYPE'];
        yield 'SQL Server' => [new SQLServerPlatform(), 'CREATE OR ALTER', 'JSON_VALUE'];
        yield 'SQLite' => [new SqlitePlatform(), 'CREATE', 'json_type'];
    }

    public function testUnsupportedPlatformFailsBeforeAnyDatabaseMutation(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new OraclePlatform());
        $connection->expects(self::never())->method('executeStatement');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('support PostgreSQL');
        $this->manager($connection)->regenerate();
    }

    public function testDiscoveryIsBoundedAndReturnsOnlyPropertyNamesTypesAndCounts(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQL80Platform());
        $connection->method('createQueryBuilder')->willReturnCallback(static fn (): QueryBuilder => new QueryBuilder($connection));
        $result = $this->createStub(Result::class);
        $result->method('fetchFirstColumn')->willReturn([
            '{"campaign":"private-value","staff":true,"nested":{"secret":"never-output"},"nullable":null}',
            '{"campaign":12,"staff":false,"nested":["hidden"],"a.b":"literal"}',
            '{"bad\\nkey":"hidden","":"hidden","123":true}',
            '{invalid',
            '["not-an-object"]',
            null,
        ]);
        $connection->expects(self::once())->method('executeQuery')->with(self::callback(static fn (string $sql): bool =>
            str_contains($sql, 'SELECT custom_data FROM events WHERE custom_data IS NOT NULL ORDER BY id DESC')
            && str_contains($sql, 'LIMIT 25'),
        ))->willReturn($result);

        self::assertSame([
            ['key' => '123', 'types' => ['boolean'], 'event_count' => 1],
            ['key' => 'a.b', 'types' => ['string'], 'event_count' => 1],
            ['key' => 'campaign', 'types' => ['number', 'string'], 'event_count' => 2],
            ['key' => 'nested', 'types' => ['array', 'object'], 'event_count' => 2],
            ['key' => 'nullable', 'types' => ['null'], 'event_count' => 1],
            ['key' => 'staff', 'types' => ['boolean'], 'event_count' => 2],
        ], $this->manager($connection)->discoverProperties(25));
    }

    #[DataProvider('invalidSampleSizes')]
    public function testDiscoveryRejectsUnboundedSampleSizes(int $limit): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('createQueryBuilder');
        $this->expectException(\InvalidArgumentException::class);
        $this->manager($connection)->discoverProperties($limit);
    }

    public static function invalidSampleSizes(): iterable
    {
        yield [0];
        yield [-1];
        yield [10001];
    }

    #[DataProvider('platforms')]
    public function testRegenerationPreflightsAllSelectsBeforeReplacingOnlyManagedViews(AbstractPlatform $platform): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $schemaPredicate = match (true) {
            $platform instanceof PostgreSQLPlatform => 'table_schema = current_schema()',
            $platform instanceof SQLServerPlatform => 'TABLE_SCHEMA = SCHEMA_NAME()',
            $platform instanceof SqlitePlatform => "pragma_table_info(:view_name, 'main')",
            default => 'TABLE_SCHEMA = DATABASE()',
        };
        $metadataNames = [];
        $connection->expects(self::exactly(3))->method('fetchFirstColumn')
            ->willReturnCallback(static function (string $query, array $parameters) use ($schemaPredicate, &$metadataNames): array {
                self::assertStringContainsString($schemaPredicate, $query);
                self::assertStringContainsString('ORDER BY', $query);
                self::assertSame(['view_name'], array_keys($parameters));
                $metadataNames[] = $parameters['view_name'];

                return [];
            });
        $connection->expects(self::never())->method('createSchemaManager');
        $connection->expects($platform instanceof AbstractMySQLPlatform ? self::never() : self::once())
            ->method('transactional')->willReturnCallback(static fn (\Closure $callback): array => $callback());
        $result = $this->createMock(Result::class);
        $result->expects(self::exactly(3))->method('free');
        $operations = [];
        $connection->expects(self::exactly(3))->method('executeQuery')
            ->willReturnCallback(static function (string $sql) use (&$operations, $result): Result {
                self::assertStringEndsWith(' AND 1 = 0', $sql);
                $operations[] = 'preflight';

                return $result;
            });
        $connection->expects(self::exactly($platform instanceof SqlitePlatform ? 6 : 3))->method('executeStatement')
            ->willReturnCallback(static function (string $sql) use (&$operations, $platform): int {
                self::assertStringContainsString('analytics_custom_', $sql);
                self::assertStringNotContainsString('bi_anonymous_', $sql);
                if (!$platform instanceof SqlitePlatform) {
                    self::assertStringNotContainsString('DROP VIEW', $sql);
                }
                $operations[] = 'replace';

                return 0;
            });

        self::assertSame(ReportingViewManager::VIEW_NAMES, $this->manager($connection)->regenerate());
        self::assertSame(ReportingViewManager::VIEW_NAMES, $metadataNames);
        self::assertSame(['preflight', 'preflight', 'preflight', 'replace'], array_slice($operations, 0, 4));
    }

    #[DataProvider('platforms')]
    public function testExistingColumnsCanBeExtendedInTheirCurrentOrder(AbstractPlatform $platform): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->expects(self::exactly(3))->method('fetchFirstColumn')->willReturn([...ReportingViewManager::BUILTIN_COLUMNS, 'old_property']);
        $connection->method('transactional')->willReturnCallback(static fn (\Closure $callback): array => $callback());
        $connection->method('executeQuery')->willReturn($this->createStub(Result::class));
        $connection->expects(self::exactly($platform instanceof SqlitePlatform ? 6 : 3))->method('executeStatement');

        self::assertCount(3, $this->manager($connection, ['old_property' => 'old', 'new_property' => 'new'])->regenerate());
    }

    #[DataProvider('platforms')]
    public function testRemovingOrReorderingExistingAliasesFailsBeforeMutation(AbstractPlatform $platform): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->expects(self::once())->method('fetchFirstColumn')->willReturn([...ReportingViewManager::BUILTIN_COLUMNS, 'old_property']);
        $connection->method('transactional')->willReturnCallback(static fn (\Closure $callback): array => $callback());
        $connection->expects(self::never())->method('executeQuery');
        $connection->expects(self::never())->method('executeStatement');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('append new columns');
        $this->manager($connection, ['replacement' => 'new'])->regenerate();
    }

    public function testPreflightFailureLeavesEveryExistingDefinitionUntouched(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQL80Platform());
        $connection->method('fetchFirstColumn')->willReturn([]);
        $connection->method('executeQuery')->willThrowException(new \RuntimeException('Missing events table'));
        $connection->expects(self::never())->method('executeStatement');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('could not be regenerated');
        $this->manager($connection)->regenerate();
    }

    public function testMysqlPartialDdlFailureReportsCommittedViewReplacements(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQL80Platform());
        $connection->method('fetchFirstColumn')->willReturn([]);
        $connection->method('executeQuery')->willReturn($this->createStub(Result::class));
        $calls = 0;
        $connection->method('executeStatement')->willReturnCallback(static function () use (&$calls): int {
            if (++$calls === 2) {
                throw new \RuntimeException('DDL failed');
            }

            return 0;
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('committed 1 view replacement(s)');
        $this->manager($connection)->regenerate();
    }

    public function testViewDdlCannotImplicitlyCommitACallersTransaction(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQL80Platform());
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->expects(self::never())->method('fetchFirstColumn');
        $connection->expects(self::never())->method('executeStatement');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('without an active transaction');
        $this->manager($connection)->regenerate();
    }

    public function testSqliteExecutionPreservesScalarSemanticsAndRetainedRowFilters(): void
    {
        // Python's standard-library SQLite provides an execution check even on
        // development PHP installations that only have pdo_mysql installed.
        $python = (new ExecutableFinder())->find('python3');
        if ($python === null) {
            self::markTestSkipped('Python 3 with SQLite is required for this portable SQL execution check.');
        }
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new SqlitePlatform());
        $sql = $this->manager($connection, [
            'campaign' => 'campaign.name', 'staff' => 'org-internal', 'number_value' => 'number',
            'object_value' => 'object', 'array_value' => 'array', 'null_value' => 'null', 'missing_value' => 'missing',
        ])->previewSql();
        $process = new Process([$python, '-c', <<<'PY'
import json, sqlite3, sys
database = sqlite3.connect(':memory:')
database.row_factory = sqlite3.Row
database.execute('''CREATE TABLE events (
    id INTEGER PRIMARY KEY, website_token TEXT DEFAULT 'website', event_name TEXT,
    url TEXT DEFAULT '/', referrer TEXT DEFAULT 'direct', privacy_mode TEXT,
    device_class TEXT DEFAULT 'desktop', viewport_bucket TEXT DEFAULT 'large',
    geo_area TEXT, goal_event TEXT, created_at TEXT DEFAULT '2026-01-01 00:00:00',
    archived_at TEXT, custom_data TEXT
)''')
database.executemany('INSERT INTO events (id, event_name, privacy_mode, goal_event, archived_at, custom_data) VALUES (?, ?, ?, ?, ?, ?)', [
    (1, 'view', 'anonymous', None, None, json.dumps({'campaign.name':'literal', 'campaign':{'name':'nested'}, 'org-internal':True, 'number':2.5, 'object':{}, 'array':[], 'null':None})),
    (2, 'signup', 'enhanced', 'registration', None, json.dumps({'campaign.name':'second', 'org-internal':False, 'number':0})),
    (3, 'view', 'enhanced', None, '2026-09-01 00:00:00', json.dumps({'campaign.name':'retained archive row', 'org-internal':'true'})),
    (4, 'signup', 'anonymous', 'registration', None, None),
])
statements = json.load(sys.stdin)
for sql in statements.values():
    database.executescript(sql)
for name in statements:
    columns = [row[0] for row in database.execute("SELECT name FROM pragma_table_info(:view_name, 'main') ORDER BY cid", {'view_name': name})]
    assert columns[-7:] == ['campaign', 'staff', 'number_value', 'object_value', 'array_value', 'null_value', 'missing_value']
for sql in statements.values():
    database.executescript(sql)
rows = [dict(row) for row in database.execute('SELECT * FROM analytics_custom_events_v1 ORDER BY id')]
assert [row['privacy_mode'] for row in rows] == ['anonymous', 'enhanced', 'enhanced', 'anonymous']
assert rows[0]['campaign'] == 'literal'
assert rows[0]['staff'] == 'true' and rows[1]['staff'] == 'false'
assert rows[0]['number_value'] == '2.5' and rows[1]['number_value'] == '0'
assert all(rows[0][column] is None for column in ['object_value', 'array_value', 'null_value', 'missing_value'])
assert rows[3]['campaign'] is None
assert [row[0] for row in database.execute('SELECT id FROM analytics_custom_pageviews_v1 ORDER BY id')] == [1, 3]
assert [row[0] for row in database.execute('SELECT id FROM analytics_custom_goals_v1 ORDER BY id')] == [2, 4]
database.execute('DELETE FROM events WHERE id = 3')
assert database.execute('SELECT COUNT(*) FROM analytics_custom_pageviews_v1').fetchone()[0] == 1
print('SQLite reporting views verified')
PY]);
        $process->setInput(json_encode($sql, JSON_THROW_ON_ERROR));
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('SQLite reporting views verified', $process->getOutput());
    }

    private function manager(Connection $connection, array $columns = ['campaign' => 'utm_campaign']): ReportingViewManager
    {
        $settings = $this->createStub(CustomDataSettings::class);
        $settings->method('reportingColumns')->willReturn($columns);

        return new ReportingViewManager($connection, $settings);
    }

}
