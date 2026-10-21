<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Command\ExportDataCommand;
use App\Command\ImportDataCommand;
use App\Entity\AnalyticsArchiveEvent;
use App\Entity\AnalyticsArchiveGeoEvent;
use App\Entity\AnalyticsArchiveGoal;
use App\Entity\Event;
use App\Entity\User;
use App\Service\DataTransfer\DataTransfer;
use App\Service\DataTransfer\DataTransferException;
use App\Service\DataTransfer\TransferCatalog;
use App\Service\DataTransfer\TransferConsole;
use App\Service\DataTransfer\TransferReport;
use App\Service\Operations\AuditTrail;
use App\Service\Operations\ProcessingTasks;
use App\Service\Operations\TaskTrigger;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\BigIntType;
use Doctrine\DBAL\Types\DateTimeImmutableType;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Tester\CommandTester;

final class DataTransferTest extends TestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
        $this->directory = sys_get_temp_dir().'/aggregate-data-transfer-'.bin2hex(random_bytes(4));
        mkdir($this->directory, 0700, true);
        foreach ([Types::DATETIME_IMMUTABLE => DateTimeImmutableType::class, Types::BIGINT => BigIntType::class] as $name => $class) {
            if (!Type::hasType($name)) {
                Type::addType($name, $class);
            }
        }
    }

    protected function tearDown(): void
    {
        if ($this->directory !== '') {
            $this->remove($this->directory);
        }
    }

    public function testExportThenImportKeepsRowsAndLeavesConfigurationBehind(): void
    {
        $source = $this->connection($this->directory.'/source.sqlite');
        $this->schema($source);
        $source->executeStatement('CREATE TABLE analytics_glossary (code TEXT)');
        $source->insert('analytics_glossary', ['code' => 'not-collected']);
        $source->executeStatement('ALTER TABLE events ADD COLUMN secret_note TEXT');
        $this->insert($source, 'events', $this->anonymous(1, 'site-a'));
        $this->insert($source, 'events', $this->enhanced(2, 'site-b'));
        $this->insert($source, 'analytics_archive_events', $this->archiveCell('aa', 'site-a'));
        $this->insert($source, 'analytics_archive_events', [
            ...$this->archiveCell('bb', 'site-b'),
            'privacy_mode' => 'enhanced',
            'page_path' => 'https://example.com/account?user=42',
            'referrer_channel' => 'https://referrer.example/',
        ]);
        $this->insert($source, 'users', $this->user(7, 'ada'));

        $exported = (new DataTransfer($source, $this->directory))->export('move-1', null, [], true, true, false, false);

        self::assertSame(5, $exported->written);
        self::assertStringContainsString('Keep it private', $exported->summary());
        $package = $this->directory.'/var/data-transfer/move-1';
        self::assertSame(0600, fileperms($package.'/events.jsonl') & 0777);
        $manifest = json_decode((string) file_get_contents($package.'/manifest.json'), true);
        self::assertSame(1, $manifest['format']);
        self::assertArrayNotHasKey('analytics_glossary', $manifest['tables']);
        self::assertSame(2, $manifest['tables']['events']['rows']);
        $eventLine = (string) file_get_contents($package.'/events.jsonl');
        self::assertStringNotContainsString('secret_note', $eventLine);
        self::assertStringNotContainsString('not-collected', $eventLine);
        self::assertStringContainsString('"privacy_mode":"anonymous"', $eventLine);
        self::assertStringContainsString('"consent_state":"granted"', $eventLine);

        $target = $this->connection($this->directory.'/target.sqlite');
        $this->schema($target);
        $imported = (new DataTransfer($target, $this->directory))->import('move-1', null, [], [], true, 'fail', false, false);

        self::assertSame(5, $imported->written);
        self::assertSame('site-a', $target->fetchOne('SELECT website_token FROM events WHERE id = 1'));
        self::assertNull($target->fetchOne('SELECT visitor_id FROM events WHERE id = 1'));
        self::assertSame('visitor-2', $target->fetchOne('SELECT visitor_id FROM events WHERE id = 2'));
        self::assertSame('6', (string) $target->fetchOne('SELECT SUM(event_count) FROM analytics_archive_events'));
        self::assertSame('ada', $target->fetchOne('SELECT username FROM users WHERE id = 7'));
        self::assertSame(0, (int) $target->fetchOne("SELECT COUNT(*) FROM sqlite_master WHERE name = 'analytics_glossary'"));
    }

    public function testImportSkipsOrReplacesAnExistingRowAndFailsClosed(): void
    {
        $source = $this->connection($this->directory.'/source.sqlite');
        $this->schema($source);
        $this->insert($source, 'events', $this->anonymous(5, 'site-a'));
        (new DataTransfer($source, $this->directory))->export('again', ['events'], [], false, false, false, false);

        $target = $this->connection($this->directory.'/target.sqlite');
        $this->schema($target);
        $this->insert($target, 'events', $this->anonymous(5, 'kept-site'));
        $transfer = new DataTransfer($target, $this->directory);

        try {
            $transfer->import('again', ['events'], [], [], false, 'fail', false, false);
            self::fail('An existing row should stop the import.');
        } catch (DataTransferException $e) {
            self::assertStringNotContainsString('kept-site', $e->getMessage());
        }
        self::assertSame('kept-site', $target->fetchOne('SELECT website_token FROM events WHERE id = 5'));

        $skipped = $transfer->import('again', ['events'], [], [], false, 'skip', false, false);
        self::assertSame(1, $skipped->tables['events']['skipped']);
        self::assertSame('kept-site', $target->fetchOne('SELECT website_token FROM events WHERE id = 5'));

        $replaced = $transfer->import('again', ['events'], [], [], false, 'replace', false, false);
        self::assertSame(1, $replaced->tables['events']['replaced']);
        self::assertSame('site-a', $target->fetchOne('SELECT website_token FROM events WHERE id = 5'));
    }

    public function testABadAnonymousRowIsRefusedAndNotRewritten(): void
    {
        $source = $this->connection($this->directory.'/source.sqlite');
        $this->schema($source);
        $bad = $this->anonymous(1, 'site-a');
        $bad['visitor_id'] = 'should-not-travel';
        $this->insert($source, 'events', $bad);

        try {
            (new DataTransfer($source, $this->directory))->export('bad', ['events'], [], false, false, false, false);
            self::fail('An anonymous identifier should stop the export.');
        } catch (DataTransferException $e) {
            self::assertStringContainsString('anonymous', $e->getMessage());
            self::assertStringNotContainsString('should-not-travel', $e->getMessage());
        }
        self::assertDirectoryDoesNotExist($this->directory.'/var/data-transfer/bad');

        $badArchive = $this->archiveCell('aa', 'site-a');
        $badArchive['page_path'] = 'https://example.com/pricing?email=secret';
        $this->insert($source, 'analytics_archive_events', $badArchive);
        try {
            (new DataTransfer($source, $this->directory))->export('bad-archive', ['archives'], [], false, false, false, false);
            self::fail('An unsanitized anonymous archive path should stop the export.');
        } catch (DataTransferException $e) {
            self::assertStringContainsString('anonymous', $e->getMessage());
            self::assertStringNotContainsString('secret', $e->getMessage());
        }
    }

    public function testEnhancedWithoutConsentIsRefused(): void
    {
        $source = $this->connection($this->directory.'/source.sqlite');
        $this->schema($source);
        $row = $this->enhanced(3, 'site-a');
        $row['consent_state'] = null;
        $this->insert($source, 'events', $row);

        $this->expectException(DataTransferException::class);
        (new DataTransfer($source, $this->directory))->export('bad-consent', ['events'], [], false, false, false, false);
    }

    public function testUsersStayOutUnlessAskedAndATokenCanBeMapped(): void
    {
        $source = $this->connection($this->directory.'/source.sqlite');
        $this->schema($source);
        $this->insert($source, 'events', $this->anonymous(1, 'old_token'));
        $this->insert($source, 'users', $this->user(4, 'ada'));
        (new DataTransfer($source, $this->directory))->export('tokens', null, [], true, false, false, false);

        $target = $this->connection($this->directory.'/target.sqlite');
        $this->schema($target);
        $withoutUsers = (new DataTransfer($target, $this->directory))->import('tokens', ['events'], [], ['old_token' => 'new-token'], false, 'fail', false, false);

        self::assertSame('new-token', $target->fetchOne('SELECT website_token FROM events'));
        self::assertSame(0, (int) $target->fetchOne('SELECT COUNT(*) FROM users'));
        self::assertStringContainsString('was not imported', implode(' ', $withoutUsers->notes));
    }

    public function testNewIdsAreMappedAndTheOldIdIsNotReused(): void
    {
        $source = $this->connection($this->directory.'/source.sqlite');
        $this->schema($source);
        $this->insert($source, 'events', $this->anonymous(9, 'site-a'));
        (new DataTransfer($source, $this->directory))->export('ids', ['events'], [], false, false, false, false);
        $target = $this->connection($this->directory.'/target.sqlite');
        $this->schema($target);
        $this->insert($target, 'events', $this->anonymous(9, 'kept'));

        $report = (new DataTransfer($target, $this->directory))->import('ids', ['events'], [], [], false, 'fail', true, false);

        self::assertNotNull($report->idMapPath);
        self::assertSame(0, (int) $target->fetchOne("SELECT COUNT(*) FROM events WHERE website_token = 'site-a' AND id = 9"));
        self::assertSame(1, (int) $target->fetchOne("SELECT COUNT(*) FROM events WHERE website_token = 'site-a'"));
        $map = (string) file_get_contents($this->directory.'/'.$report->idMapPath);
        self::assertStringContainsString('"source_id":"9"', $map);
        self::assertStringNotContainsString('site-a', $map);
    }

    public function testCopyRefusesTheSameDatabaseAndCopiesADifferentOne(): void
    {
        $source = $this->connection($this->directory.'/source.sqlite');
        $this->schema($source);
        $this->insert($source, 'events', $this->anonymous(1, 'site-a'));
        $transfer = new DataTransfer($source, $this->directory);

        try {
            $transfer->copy($this->sqliteUrl($this->directory.'/source.sqlite'), ['events'], [], [], false, 'fail', false, false, false);
            self::fail('A copy into the same database should be refused.');
        } catch (DataTransferException $e) {
            self::assertStringNotContainsString($this->directory, $e->getMessage());
        }

        $targetPath = $this->directory.'/other.sqlite';
        $target = $this->connection($targetPath);
        $this->schema($target);
        $target->close();
        $report = $transfer->copy($this->sqliteUrl($targetPath), ['events'], [], [], false, 'fail', false, true, false);

        $check = $this->connection($targetPath);
        self::assertSame(1, $report->written);
        self::assertSame('site-a', $check->fetchOne('SELECT website_token FROM events'));
    }

    public function testDryRunWritesNothingAndAPackageNameCannotLeaveTheDirectory(): void
    {
        $source = $this->connection($this->directory.'/source.sqlite');
        $this->schema($source);
        $this->insert($source, 'events', $this->anonymous(1, 'site-a'));
        $transfer = new DataTransfer($source, $this->directory);

        $dry = $transfer->export('preview', ['events'], [], false, false, false, true);
        self::assertTrue($dry->dryRun);
        self::assertDirectoryDoesNotExist($this->directory.'/var/data-transfer/preview');

        $this->expectException(DataTransferException::class);
        $transfer->export('../outside', ['events'], [], false, false, false, false);
    }

    public function testTheCommandRecordsTheProcessingTaskAndAuditTrail(): void
    {
        $source = $this->connection($this->directory.'/source.sqlite');
        $this->schema($source);
        $this->operationTables($source);
        $this->insert($source, 'events', $this->anonymous(1, 'site-a'));
        $logger = new RecordingDataTransferLogger();
        $command = new ExportDataCommand(
            new DataTransfer($source, $this->directory),
            new TransferConsole(new ProcessingTasks($source, new AuditTrail($source)), new FixedDataTransferClock(), $logger),
        );

        $tester = new CommandTester($command);
        self::assertSame(0, $tester->execute(['--package' => 'recorded', '--dataset' => ['events']]), $tester->getDisplay()."\n".$logger->recorded);

        $task = $source->fetchAssociative('SELECT status, task_type, subject, lock_key, details FROM processing_tasks');
        self::assertSame(['succeeded', 'data_transfer', 'transfer', null], [$task['status'], $task['task_type'], $task['subject'], $task['lock_key']]);
        self::assertStringContainsString('events:', (string) $task['details']);
        $audit = $source->fetchAssociative('SELECT category, operation, subject, outcome, details FROM audit_trail');
        self::assertSame(['task', 'data_transfer', 'transfer', 'succeeded'], [$audit['category'], $audit['operation'], $audit['subject'], $audit['outcome']]);
        self::assertSame($task['details'], $audit['details']);
        self::assertStringNotContainsString('site-a', (string) $audit['details']);
    }

    public function testAFailedRunIsAuditedWithoutTheRowValueAndASecondRunWaits(): void
    {
        $source = $this->connection($this->directory.'/source.sqlite');
        $this->schema($source);
        $this->operationTables($source);
        $bad = $this->anonymous(1, 'site-a');
        $bad['visitor_id'] = 'secret-visitor';
        $this->insert($source, 'events', $bad);
        $logger = new RecordingDataTransferLogger();
        $tasks = new ProcessingTasks($source, new AuditTrail($source));
        $console = new TransferConsole($tasks, new FixedDataTransferClock(), $logger);
        $command = new ExportDataCommand(new DataTransfer($source, $this->directory), $console);
        $tester = new CommandTester($command);

        self::assertSame(1, $tester->execute(['--package' => 'failed', '--dataset' => ['events']]), $tester->getDisplay()."\n".$logger->recorded);
        $failed = $source->fetchAssociative('SELECT status, details FROM processing_tasks');
        self::assertSame('failed', $failed['status'], $tester->getDisplay()."\n".$logger->recorded);
        self::assertStringNotContainsString('secret-visitor', (string) $failed['details']);
        self::assertSame('failed', $source->fetchOne('SELECT outcome FROM audit_trail'));

        $tasks->start(DataTransfer::TASK_TYPE, DataTransfer::TASK_SUBJECT, TaskTrigger::command(), new \DateTimeImmutable('2026-10-18 19:00:00 UTC'), DataTransfer::LOCK_SECONDS);
        $output = new BufferedOutput();
        $code = $console->run(new SymfonyStyle(new ArrayInput([]), $output), TaskTrigger::command(), static fn (): TransferReport => throw new \RuntimeException('password=secret-url'));
        self::assertSame(1, $code);
        self::assertStringContainsString('already running', $output->fetch());
        self::assertStringNotContainsString('secret-url', (string) $source->fetchOne('SELECT details FROM audit_trail ORDER BY id DESC'));
    }

    public function testTheImportCommandRewritesAWebsiteToken(): void
    {
        $source = $this->connection($this->directory.'/source.sqlite');
        $this->schema($source);
        $this->operationTables($source);
        $this->insert($source, 'events', $this->anonymous(1, 'old_token'));
        $logger = new RecordingDataTransferLogger();
        $tasks = new ProcessingTasks($source, new AuditTrail($source));
        $console = new TransferConsole($tasks, new FixedDataTransferClock(), $logger);
        $exported = new CommandTester(new ExportDataCommand(new DataTransfer($source, $this->directory), $console));
        self::assertSame(0, $exported->execute(['--package' => 'mapped', '--dataset' => ['events']]), $exported->getDisplay()."\n".$logger->recorded);

        $target = $this->connection($this->directory.'/target.sqlite');
        $this->schema($target);
        $this->operationTables($target);
        $importLogger = new RecordingDataTransferLogger();
        $tester = new CommandTester(new ImportDataCommand(
            new DataTransfer($target, $this->directory),
            new TransferConsole(new ProcessingTasks($target, new AuditTrail($target)), new FixedDataTransferClock(), $importLogger),
        ));
        self::assertSame(0, $tester->execute([
            '--package' => 'mapped',
            '--dataset' => ['events'],
            '--map-token' => ['old_token=new-token'],
        ]), $tester->getDisplay()."\n".$importLogger->recorded);
        self::assertSame('new-token', $target->fetchOne('SELECT website_token FROM events'));
        self::assertStringNotContainsString('old_token', (string) $target->fetchOne('SELECT details FROM audit_trail'));
    }

    public function testCatalogColumnsMatchMappedEntities(): void
    {
        $config = ORMSetup::createAttributeMetadataConfig([dirname(__DIR__, 2).'/src/Entity'], true);
        $config->setNamingStrategy(new UnderscoreNamingStrategy(\CASE_LOWER));
        if (\PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        } else {
            $config->setProxyDir(sys_get_temp_dir());
            $config->setProxyNamespace('AggregateTestProxies');
        }
        $manager = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config), $config);

        foreach ([
            'events' => Event::class,
            'analytics_archive_events' => AnalyticsArchiveEvent::class,
            'analytics_archive_goals' => AnalyticsArchiveGoal::class,
            'analytics_archive_geo_events' => AnalyticsArchiveGeoEvent::class,
        ] as $table => $entity) {
            self::assertSame(
                array_values($manager->getClassMetadata($entity)->getColumnNames()),
                array_keys(TransferCatalog::columns($table)),
                $table,
            );
        }

        $userColumns = array_values($manager->getClassMetadata(User::class)->getColumnNames());
        foreach (array_keys(TransferCatalog::columns('users')) as $column) {
            self::assertContains($column, $userColumns);
        }
    }

    private function sqliteUrl(string $path): string
    {
        return 'sqlite:///'.$path;
    }

    private function connection(string $path): Connection
    {
        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]);
    }

    private function schema(Connection $connection): void
    {
        foreach (TransferCatalog::tables() as $table) {
            $parts = [];
            foreach (TransferCatalog::columns($table) as $name => $column) {
                if ($name === TransferCatalog::primary($table) && TransferCatalog::isIdentity($table)) {
                    $parts[] = $name.' INTEGER PRIMARY KEY AUTOINCREMENT';
                    continue;
                }
                if ($name === TransferCatalog::primary($table)) {
                    $parts[] = $name.' TEXT PRIMARY KEY';
                    continue;
                }
                $parts[] = $name.' TEXT';
            }
            $connection->executeStatement('CREATE TABLE '.$table.' ('.implode(', ', $parts).')');
        }
        $connection->executeStatement('CREATE UNIQUE INDEX uniq_users_username ON users (username)');
    }

    private function operationTables(Connection $connection): void
    {
        $connection->executeStatement('CREATE TABLE processing_tasks (id INTEGER PRIMARY KEY AUTOINCREMENT, task_type TEXT, subject TEXT, status TEXT, triggered_by TEXT, requested_by TEXT, lock_key TEXT, started_at TEXT, finished_at TEXT, row_count TEXT, external_id TEXT, details TEXT, fingerprint TEXT)');
        $connection->executeStatement('CREATE UNIQUE INDEX UNIQ_PROCESSING_TASKS_LOCK ON processing_tasks (lock_key)');
        $connection->executeStatement('CREATE TABLE audit_trail (id INTEGER PRIMARY KEY AUTOINCREMENT, occurred_at TEXT, category TEXT, operation TEXT, subject TEXT, outcome TEXT, actor TEXT, processing_task_id INTEGER, details TEXT)');
    }

    /** @param array<string, mixed> $row */
    private function insert(Connection $connection, string $table, array $row): void
    {
        $connection->insert($table, $row);
    }

    /** @return array<string, mixed> */
    private function anonymous(int $id, string $token): array
    {
        return [
            'id' => $id,
            'website_token' => $token,
            'event_name' => 'page_view',
            'url' => '/pricing',
            'referrer' => 'search',
            'generalized_user_agent' => null,
            'privacy_mode' => 'anonymous',
            'device_class' => 'desktop',
            'viewport_bucket' => 'large',
            'geo_area' => 'country:US',
            'screen_width' => null,
            'visitor_id' => null,
            'session_id' => null,
            'consent_state' => null,
            'custom_data' => null,
            'goal_event' => 'signup',
            'created_at' => '2026-10-18 19:00:00',
            'archived_at' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function enhanced(int $id, string $token): array
    {
        return [
            'id' => $id,
            'website_token' => $token,
            'event_name' => 'page_view',
            'url' => 'https://example.com/pricing',
            'referrer' => 'https://example.com/from',
            'generalized_user_agent' => 'Chrome',
            'privacy_mode' => 'enhanced',
            'device_class' => 'desktop',
            'viewport_bucket' => 'large',
            'geo_area' => null,
            'screen_width' => 1440,
            'visitor_id' => 'visitor-'.$id,
            'session_id' => 'session-'.$id,
            'consent_state' => 'granted',
            'custom_data' => '{"plan":"pro","org_internal_traffic":false}',
            'goal_event' => null,
            'created_at' => '2026-10-18 19:04:05',
            'archived_at' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function archiveCell(string $prefix, string $token): array
    {
        return [
            'cell_key' => str_pad($prefix, 64, '0'),
            'website_token' => $token,
            'event_hour' => '2026-10-18 19:00:00',
            'privacy_mode' => 'anonymous',
            'event_name' => 'page_view',
            'page_path' => '/pricing',
            'referrer_channel' => 'search',
            'device_class' => 'desktop',
            'viewport_bucket' => 'large',
            'event_count' => '3',
        ];
    }

    /** @return array<string, mixed> */
    private function user(int $id, string $username): array
    {
        return [
            'id' => $id,
            'username' => $username,
            'roles' => '["ROLE_ADMIN"]',
            'password' => '$2y$13$abcdefghijklmnopqrstuu0123456789abcdefghij',
            'created_at' => '2026-10-18 19:00:00',
        ];
    }

    private function remove(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = scandir($path);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $child = $path.'/'.$item;
            is_dir($child) ? $this->remove($child) : unlink($child);
        }
        rmdir($path);
    }
}

final class RecordingDataTransferLogger extends NullLogger
{
    public string $recorded = '';

    public function error(string|\Stringable $message, array $context = []): void
    {
        $exception = $context['exception'] ?? null;
        $this->recorded = $exception instanceof \Throwable
            ? $exception::class.': '.$exception->getMessage()."\n".$exception->getTraceAsString()
            : (string) $message;
    }
}

final class FixedDataTransferClock implements ClockInterface
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-18 19:04:00 UTC');
    }
}
