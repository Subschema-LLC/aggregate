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
use Doctrine\DBAL\Platforms\SQLitePlatform;
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
            $qualifiedName = $platform instanceof SQLitePlatform
                ? '"main"."'.$name.'"' : $platform->quoteSingleIdentifier($name);
            self::assertStringContainsString($verb.' VIEW '.$qualifiedName, $definition);
            self::assertStringContainsString($function, $definition);
            self::assertStringContainsString('events.url AS page_path', $definition);
            self::assertStringContainsString('events.archived_at', $definition);
            self::assertStringContainsString(' AS '.$platform->quoteSingleIdentifier('campaign'), $definition);
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
        yield 'PostgreSQL' => [new PostgreSQLPlatform(), 'CREATE OR REPLACE', 'json_typeof'];
        yield 'MySQL' => [new MySQL80Platform(), 'CREATE OR REPLACE', 'JSON_TYPE'];
        yield 'MariaDB' => [new MariaDBPlatform(), 'CREATE OR REPLACE', 'JSON_TYPE'];
        yield 'SQL Server' => [new SQLServerPlatform(), 'CREATE OR ALTER', 'JSON_VALUE'];
        yield 'SQLite' => [new SQLitePlatform(), 'CREATE', 'json_type'];
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
            $platform instanceof SQLitePlatform => "pragma_table_info(:view_name, 'main')",
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
        $connection->expects(self::exactly($platform instanceof SQLitePlatform ? 6 : 3))->method('executeStatement')
            ->willReturnCallback(static function (string $sql) use (&$operations, $platform): int {
                self::assertStringContainsString('analytics_custom_', $sql);
                self::assertStringNotContainsString('bi_anonymous_', $sql);
                if (!$platform instanceof SQLitePlatform) {
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
        $connection->method('fetchAllAssociative')->willReturn([['column_name' => 'old_property', 'data_type' => 'text', 'numeric_precision' => null]]);
        $connection->method('fetchOne')->willReturn('CREATE VIEW example AS SELECT old AS "old_property" FROM events');
        $connection->method('transactional')->willReturnCallback(static fn (\Closure $callback): array => $callback());
        $connection->method('executeQuery')->willReturn($this->createStub(Result::class));
        $connection->expects(self::exactly($platform instanceof SQLitePlatform ? 6 : 3))->method('executeStatement');

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
        $connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());
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

    #[DataProvider('platforms')]
    public function testNumericAliasesAreExplicitAndFollowExistingTextAliases(AbstractPlatform $platform): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->expects(self::never())->method('executeStatement');
        $sql = $this->manager($connection, ['amount_text' => 'amount'], [
            'amount_number' => ['property' => 'amount', 'type' => 'double'],
            'quantity_number' => ['property' => 'quantity', 'type' => 'integer'],
            'ratio_number' => ['property' => 'campaign.ratio', 'type' => 'float'],
        ])->previewSql();

        foreach ($sql as $definition) {
            self::assertLessThan(strpos($definition, ' AS '.$platform->quoteSingleIdentifier('amount_number')), strpos($definition, ' AS '.$platform->quoteSingleIdentifier('amount_text')));
            self::assertStringContainsString('9007199254740991', $definition);
            self::assertStringNotContainsString('bi_anonymous_', $definition);
        }
        $definition = reset($sql);
        if ($platform instanceof PostgreSQLPlatform) {
            self::assertStringContainsString('DOUBLE PRECISION', $definition);
            self::assertStringContainsString('trunc(', $definition);
            self::assertStringContainsString('[0-9]{1,3}', $definition);
        } elseif ($platform instanceof SQLServerPlatform) {
            self::assertStringContainsString('TRY_CONVERT(float(53)', $definition);
            self::assertStringContainsString('entry.[type] = 2', $definition);
            self::assertStringContainsString('Latin1_General_100_BIN2', $definition);
        } elseif ($platform instanceof AbstractMySQLPlatform) {
            self::assertStringContainsString('JSON_VALID', $definition);
            self::assertStringContainsString('FLOOR(', $definition);
        } else {
            self::assertStringContainsString('AS REAL)', $definition);
            self::assertStringContainsString('AS INTEGER)', $definition);
        }
    }

    #[DataProvider('platforms')]
    public function testDeployedNumericTypeCannotSilentlyChange(AbstractPlatform $platform): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->method('fetchFirstColumn')->willReturn([...ReportingViewManager::BUILTIN_COLUMNS, 'amount_number']);
        $connection->method('fetchAllAssociative')->willReturn([['column_name' => 'amount_number', 'data_type' => 'double precision', 'numeric_precision' => 53]]);
        $connection->method('fetchOne')->willReturn('CREATE VIEW example AS SELECT CAST((CASE WHEN 1 THEN 12.5 END) AS REAL) AS "amount_number" FROM events');
        $connection->method('transactional')->willReturnCallback(static fn (\Closure $callback): array => $callback());
        $connection->expects(self::never())->method('executeQuery');
        $connection->expects(self::never())->method('executeStatement');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('column types cannot change');
        $this->manager($connection, [], ['amount_number' => ['property' => 'amount', 'type' => 'integer']])->regenerate();
    }

    #[DataProvider('platforms')]
    public function testExistingTextAliasCannotBeRepurposedAsNumeric(AbstractPlatform $platform): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->method('fetchFirstColumn')->willReturn([...ReportingViewManager::BUILTIN_COLUMNS, 'amount']);
        $connection->method('fetchAllAssociative')->willReturn([['column_name' => 'amount', 'data_type' => 'text', 'numeric_precision' => null]]);
        $connection->method('fetchOne')->willReturn('CREATE VIEW example AS SELECT value AS "amount" FROM events');
        $connection->method('transactional')->willReturnCallback(static fn (\Closure $callback): array => $callback());
        $connection->expects(self::never())->method('executeStatement');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('column types cannot change');
        $this->manager($connection, [], ['amount' => ['property' => 'amount', 'type' => 'double']])->regenerate();
    }

    public function testNewTextAliasAfterDeployedNumericAliasesNeedsExplicitMigration(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQL80Platform());
        $connection->method('fetchFirstColumn')->willReturn([...ReportingViewManager::BUILTIN_COLUMNS, 'amount_number']);
        $connection->expects(self::never())->method('executeStatement');
        $connection->expects(self::never())->method('executeQuery');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('adding text aliases after numeric aliases');
        $this->manager($connection, ['currency' => 'currency'], ['amount_number' => ['property' => 'amount', 'type' => 'double']])->regenerate();
    }

    public function testSqliteNumericViewsExecuteWithoutCoercingStringsOrTruncatingFractions(): void
    {
        $python = (new ExecutableFinder())->find('python3');
        if ($python === null) {
            self::markTestSkipped('Python SQLite is required for SQL execution checks.');
        }
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());
        $sql = $this->manager($connection, ['amount_text' => 'amount'], [
            'amount_number' => ['property' => 'amount', 'type' => 'double'],
            'quantity_number' => ['property' => 'quantity', 'type' => 'integer'],
            'ratio_number' => ['property' => 'ratio.value', 'type' => 'float'],
        ])->previewSql();
        $process = new Process([$python, '-c', <<<'PY'
import json, sqlite3, sys
db = sqlite3.connect(':memory:')
db.execute('CREATE TABLE events (id INTEGER PRIMARY KEY, website_token TEXT, event_name TEXT, url TEXT, referrer TEXT, privacy_mode TEXT, device_class TEXT, viewport_bucket TEXT, geo_area TEXT, goal_event TEXT, created_at TEXT, archived_at TEXT, custom_data TEXT)')
values = [
    '{"amount":12.5,"quantity":2,"ratio.value":0.1}',
    '{"amount":"12.5","quantity":"2","ratio.value":true}',
    '{"amount":0,"quantity":-2.0,"ratio.value":-0.25}',
    '{"amount":1e999,"quantity":2.5}',
    '{"amount":null,"quantity":9007199254740992}',
    '{"amount":{},"quantity":false}',
    '{invalid',
]
for i, value in enumerate(values, 1):
    db.execute('INSERT INTO events(id,event_name,custom_data) VALUES (?, ?, ?)', (i, 'view', value))
for sql in json.load(sys.stdin).values():
    db.executescript(sql)
rows = db.execute('SELECT amount_text, amount_number, quantity_number, ratio_number FROM analytics_custom_events_v1 ORDER BY id').fetchall()
assert rows[0] == ('12.5',12.5,2,0.1), rows
assert rows[1] == ('12.5',None,None,None), rows
assert rows[2] == ('0',0.0,-2,-0.25), rows
assert all(value is None for row in rows[3:] for value in row[1:]), rows
assert db.execute('SELECT SUM(amount_number), SUM(quantity_number) FROM analytics_custom_events_v1').fetchone() == (12.5,0)
assert db.execute('SELECT typeof(amount_number),typeof(quantity_number) FROM analytics_custom_events_v1 WHERE id=1').fetchone() == ('real','integer')
print('SQLite numeric reporting views verified')
PY]);
        $process->setInput(json_encode($sql, JSON_THROW_ON_ERROR));
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('SQLite numeric reporting views verified', $process->getOutput());
    }

    public function testPostgresExecutionPreservesHistoricalNumericTextNormalization(): void
    {
        // Explicit opt-in uses a disposable, network-isolated container and
        // never an operator DATABASE_URL. No images are downloaded by tests.
        if (getenv('AGGREGATE_TEST_POSTGRES') !== '1') {
            self::markTestSkipped('Set AGGREGATE_TEST_POSTGRES=1 with cached postgres:16-alpine and Docker to execute this isolated integration test.');
        }
        $docker = (new ExecutableFinder())->find('docker');
        self::assertNotNull($docker, 'Docker is required for the explicitly requested PostgreSQL integration test.');
        $name = 'aggregate-reporting-test-'.bin2hex(random_bytes(6));
        $start = new Process([$docker, 'run', '--detach', '--rm', '--pull=never', '--name', $name, '--network', 'none', '--env', 'POSTGRES_HOST_AUTH_METHOD=trust', 'postgres:16-alpine']);
        $start->mustRun();
        try {
            $ready = false;
            for ($attempt = 0; $attempt < 50; ++$attempt) {
                $probe = new Process([$docker, 'exec', $name, 'pg_isready', '-U', 'postgres']);
                if ($probe->run() === 0) {
                    $ready = true;
                    break;
                }
                usleep(200000);
            }
            self::assertTrue($ready, 'Disposable PostgreSQL did not become ready.');
            $connection = $this->createStub(Connection::class);
            $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
            $views = implode("\n", $this->manager($connection, ['amount_text' => 'amount'], ['amount_number' => ['property' => 'amount', 'type' => 'double']])->previewSql());
            $sql = <<<'SQL'
CREATE TABLE events (id INTEGER PRIMARY KEY, website_token TEXT, event_name TEXT, url TEXT, referrer TEXT, privacy_mode TEXT, device_class TEXT, viewport_bucket TEXT, geo_area TEXT, goal_event TEXT, created_at TEXT, archived_at TEXT, custom_data JSON);
INSERT INTO events (id,custom_data) VALUES (1,'{"amount":1e2}'),(2,'{"amount":2.50}'),(3,'{"amount":1e999999}'),(4,'{"amount":true}'),(5,'{"amount":"12.5"}'),(6,'{"amount":1e0000002}');
SQL;
            $sql .= "\n".$views."\nCREATE ROLE fixture_bi;\nGRANT SELECT(amount_number) ON analytics_custom_events_v1 TO fixture_bi;\n".$views;
            $sql .= "\nSELECT json_agg(row_to_json(projected)) FROM (SELECT amount_text, amount_number FROM analytics_custom_events_v1 ORDER BY id) AS projected;\nSELECT has_column_privilege('fixture_bi','analytics_custom_events_v1','amount_number','SELECT');\n";
            $query = new Process([$docker, 'exec', '-i', $name, 'psql', '-U', 'postgres', '-v', 'ON_ERROR_STOP=1', '-Atq']);
            $query->setInput($sql);
            $query->mustRun();
            $lines = explode("\n", trim($query->getOutput()));
            self::assertSame([
                ['amount_text' => '100', 'amount_number' => 100],
                ['amount_text' => '2.50', 'amount_number' => 2.5],
                ['amount_text' => '1e999999', 'amount_number' => null],
                ['amount_text' => 'true', 'amount_number' => null],
                ['amount_text' => '12.5', 'amount_number' => null],
                ['amount_text' => '100', 'amount_number' => null],
            ], json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR));
            self::assertSame('t', $lines[1], 'Regeneration must retain the column grant.');
        } finally {
            (new Process([$docker, 'rm', '--force', $name]))->run();
        }
    }

    private function manager(Connection $connection, array $columns = ['campaign' => 'utm_campaign'], array $numericColumns = []): ReportingViewManager
    {
        $settings = $this->createStub(CustomDataSettings::class);
        $settings->method('reportingColumns')->willReturn($columns);
        $settings->method('numericReportingColumns')->willReturn($numericColumns);

        return new ReportingViewManager($connection, $settings);
    }

}
