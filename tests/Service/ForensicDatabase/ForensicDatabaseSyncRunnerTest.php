<?php

declare(strict_types=1);

namespace App\Tests\Service\ForensicDatabase;

use App\Service\AggregateConfigLoader;
use App\Service\CustomDataSettings;
use App\Service\ForensicDatabase\ForensicCatalog;
use App\Service\ForensicDatabase\ForensicDatabaseConnection;
use App\Service\ForensicDatabase\ForensicDatabaseSettings;
use App\Service\ForensicDatabase\ForensicDatabaseSyncRunner;
use App\Service\ForensicDatabase\ForensicReader;
use App\Service\Operations\AuditTrail;
use App\Service\Operations\ProcessingTasks;
use App\Service\ReportingDatabase\ReportingDatabaseConnection;
use App\Service\ReportingDatabase\ReportingDatabaseException;
use App\Service\ViewSync\ViewCatalog;
use App\Service\ViewSync\ViewExporter;
use App\Service\ViewSync\ViewReader;
use App\Tests\Service\Operations\OperationsDatabase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Yaml\Yaml;

/** The whole forensic sync with SQLite on both sides: raw tables in, hashed copies out. */
final class ForensicDatabaseSyncRunnerTest extends TestCase
{
    private const VISITOR_A = '6f1c2b8e-4d0a-4c3e-9a51-2f7d8e9b0c1a';
    private const VISITOR_B = '0b7e3a11-5c2d-4e6f-8a9b-1c2d3e4f5a6b';
    private const SESSION_A = 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d';
    private const FORENSIC_URL = 'sqlite:///%kernel.project_dir%/var/forensic.db';

    private string $projectDir;
    private Connection $application;
    private \DateTimeImmutable $now;
    private array $environment;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        foreach (array_keys(ForensicDatabaseSettings::defaults()) as $key) {
            unset($_ENV[strtoupper($key)], $_SERVER[strtoupper($key)]);
        }
        $this->projectDir = sys_get_temp_dir().'/aggregate-forensic-runner-'.bin2hex(random_bytes(6));
        mkdir($this->projectDir.'/config', 0700, true);
        mkdir($this->projectDir.'/var', 0700, true);
        $this->application = OperationsDatabase::connection();
        foreach ([
            'CREATE TABLE events (id INTEGER PRIMARY KEY, website_token VARCHAR(191), event_name VARCHAR(191), url CLOB, referrer CLOB, generalized_user_agent VARCHAR(191), privacy_mode VARCHAR(20), device_class VARCHAR(20), viewport_bucket VARCHAR(20), geo_area VARCHAR(16), screen_width INTEGER, visitor_id VARCHAR(191), session_id VARCHAR(191), consent_state VARCHAR(20), custom_data CLOB, goal_event VARCHAR(191), created_at DATETIME, archived_at DATETIME)',
            'CREATE TABLE analytics_archive_events (cell_key VARCHAR(64), website_token VARCHAR(191), event_hour DATETIME, privacy_mode VARCHAR(20), event_name VARCHAR(191), page_path CLOB, referrer_channel CLOB, device_class VARCHAR(20), viewport_bucket VARCHAR(20), event_count BIGINT)',
            'CREATE TABLE analytics_archive_geo_events (cell_key VARCHAR(64), website_token VARCHAR(191), event_day DATE, privacy_mode VARCHAR(20), event_name VARCHAR(191), geo_area VARCHAR(16), event_count BIGINT)',
            'CREATE TABLE analytics_archive_goals (cell_key VARCHAR(64), website_token VARCHAR(191), event_day DATE, privacy_mode VARCHAR(20), goal_event VARCHAR(191), event_count BIGINT)',
            'CREATE VIEW analytics_custom_events_v1 AS SELECT id, website_token, event_name, url AS page_path, created_at, archived_at FROM events',
            "INSERT INTO analytics_archive_events VALUES ('c1', 'tok', '2026-09-01 10:00:00', 'anonymous', 'view', '/old', 'direct', 'desktop', 'lg', 7)",
            "INSERT INTO analytics_archive_goals VALUES ('g1', 'tok', '2026-09-01', 'anonymous', 'signup', 2)",
        ] as $sql) {
            $this->application->executeStatement($sql);
        }
        $insert = 'INSERT INTO events (website_token, event_name, url, referrer, generalized_user_agent, privacy_mode, device_class, viewport_bucket, geo_area, screen_width, visitor_id, session_id, consent_state, custom_data, goal_event, created_at, archived_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        foreach ([
            ['tok', 'view', '/a', 'search', 'Firefox 132', 'enhanced', 'desktop', 'lg', 'EU', 1440, self::VISITOR_A, self::SESSION_A, 'granted', '{"plan":"pro"}', null, '2026-10-01 10:12:34', null],
            ['tok', 'click', '/a', 'search', 'Firefox 132', 'enhanced', 'desktop', 'lg', 'EU', 1440, self::VISITOR_A, self::SESSION_A, 'granted', null, 'signup', '2026-10-01 10:13:05', null],
            ['tok', 'view', '/b', 'direct', 'Safari 18', 'enhanced', 'mobile', 'sm', null, 390, self::VISITOR_B, self::VISITOR_B, 'granted', null, null, '2026-10-01 11:00:00', null],
            ['tok', 'view', '/b', 'direct', null, 'anonymous', 'mobile', 'sm', null, null, null, null, null, null, null, '2026-10-01 11:00:00', null],
        ] as $row) {
            $this->application->executeStatement($insert, $row);
        }
        $this->now = new \DateTimeImmutable('2026-10-07 12:00:00', new \DateTimeZone('UTC'));
    }

    protected function tearDown(): void
    {
        [$_ENV, $_SERVER] = $this->environment;
        foreach ([...(glob($this->projectDir.'/var/forensic-database/*') ?: [])] as $file) {
            unlink($file);
        }
        @rmdir($this->projectDir.'/var/forensic-database');
        foreach ([...(glob($this->projectDir.'/config/*') ?: []), ...(glob($this->projectDir.'/var/*') ?: [])] as $file) {
            unlink($file);
        }
        rmdir($this->projectDir.'/config');
        rmdir($this->projectDir.'/var');
        rmdir($this->projectDir);
    }

    public function testAnUnmarkableDatabaseGetsNothingUntilAccessIsConfirmedRestricted(): void
    {
        $result = $this->runner()->run();

        self::assertSame(['failed'], array_values(array_unique(array_column($result['results'], 'status'))));
        self::assertStringContainsString('keeps no database comment', $result['results'][0]['message']);
        self::assertSame([], $this->forensic()->fetchFirstColumn("SELECT name FROM sqlite_master WHERE type = 'table'"));
    }

    public function testEveryColumnIsCopiedAndBrowserIdentifiersAreReplacedByHashes(): void
    {
        $result = $this->runner(['forensic_database_access_restricted' => true])->run();

        self::assertSame(
            ['forensic_events_v1' => 4, 'forensic_archive_events_v1' => 1, 'forensic_archive_geo_events_v1' => 0, 'forensic_archive_goals_v1' => 1, 'analytics_custom_events_v1' => 4],
            array_column($result['results'], 'rows', 'view'),
        );
        $forensic = $this->forensic();
        self::assertSame(array_keys(ForensicCatalog::TABLES['forensic_events_v1']['columns']), array_keys($forensic->fetchAssociative('SELECT * FROM forensic_events_v1')));

        $rows = $forensic->fetchAllAssociative('SELECT * FROM forensic_events_v1 ORDER BY id');
        self::assertSame(['/a', 'search', 'Firefox 132', 'enhanced', 'EU', 1440, 'granted', '{"plan":"pro"}', '2026-10-01 10:12:34'], [
            $rows[0]['url'], $rows[0]['referrer'], $rows[0]['generalized_user_agent'], $rows[0]['privacy_mode'], $rows[0]['geo_area'], $rows[0]['screen_width'], $rows[0]['consent_state'], $rows[0]['custom_data'], substr((string) $rows[0]['created_at'], 0, 19),
        ]);
        // One browser's events stay linked within the copy; different browsers do not.
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', (string) $rows[0]['visitor_hash']);
        self::assertSame($rows[0]['visitor_hash'], $rows[1]['visitor_hash']);
        self::assertSame($rows[0]['session_hash'], $rows[1]['session_hash']);
        self::assertNotSame($rows[0]['visitor_hash'], $rows[2]['visitor_hash']);
        // A session ID equal to a visitor ID still hashes differently.
        self::assertNotSame($rows[2]['visitor_hash'], $rows[2]['session_hash']);
        // Anonymous events have no identifiers, and get none.
        self::assertNull($rows[3]['visitor_hash']);
        self::assertNull($rows[3]['session_hash']);

        self::assertSame([['cell_key' => 'c1', 'event_count' => 7]], $forensic->fetchAllAssociative('SELECT cell_key, event_count FROM forensic_archive_events_v1'));
        self::assertSame(4, (int) $forensic->fetchOne('SELECT COUNT(*) FROM analytics_custom_events_v1'));
    }

    public function testNoBrowserIdentifierReachesTheForensicDatabaseOrTheExportFiles(): void
    {
        $this->runner(['forensic_database_access_restricted' => true])->run();
        $this->forensic()->close();

        $bytes = (string) file_get_contents($this->projectDir.'/var/forensic.db');
        foreach (glob($this->projectDir.'/var/forensic-database/*') ?: [] as $file) {
            $bytes .= (string) file_get_contents($file);
        }
        // The scan reads the copied rows themselves: other values are there in plain text.
        self::assertStringContainsString('Firefox 132', $bytes);
        foreach ([self::VISITOR_A, self::VISITOR_B, self::SESSION_A] as $identifier) {
            self::assertStringNotContainsString($identifier, $bytes);
            self::assertStringNotContainsString(strtoupper($identifier), $bytes);
        }
        self::assertStringNotContainsString(self::VISITOR_A, (string) json_encode($this->tasks()->latest(ForensicDatabaseSyncRunner::TASK_TYPE)));
    }

    public function testEverySyncHashesWithANewKeySoHashesNeverMatchAcrossCopies(): void
    {
        $this->runner(['forensic_database_access_restricted' => true])->run();
        $first = $this->forensic()->fetchOne('SELECT visitor_hash FROM forensic_events_v1 WHERE id = 1');

        $this->now = $this->now->modify('+1 day');
        $result = $this->runner(['forensic_database_access_restricted' => true])->run();

        $statuses = array_column($result['results'], 'status', 'view');
        // The events copy always changes (new hashes); an unchanged archive keeps its table.
        self::assertSame('synced', $statuses['forensic_events_v1']);
        self::assertSame('unchanged', $statuses['forensic_archive_events_v1']);
        $second = $this->forensic()->fetchOne('SELECT visitor_hash FROM forensic_events_v1 WHERE id = 1');
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', (string) $second);
        self::assertNotSame($first, $second);
    }

    public function testDeletionsReachTheCopyAtTheNextSync(): void
    {
        $this->runner(['forensic_database_access_restricted' => true])->run();
        $this->application->executeStatement('DELETE FROM events WHERE visitor_id = ?', [self::VISITOR_A]);

        $this->now = $this->now->modify('+1 day');
        $this->runner(['forensic_database_access_restricted' => true])->run();

        self::assertSame(2, (int) $this->forensic()->fetchOne('SELECT COUNT(*) FROM forensic_events_v1'));
    }

    public function testADatabaseMarkedForBiUsersIsAlwaysRefused(): void
    {
        $result = $this->runner(['forensic_database_access_restricted' => true], 'Read-only reporting copy [aggregate-reporting-copy] [aggregate-forensic-copy]')->run();

        self::assertSame(['failed'], array_values(array_unique(array_column($result['results'], 'status'))));
        self::assertStringContainsString('marked as a read-only reporting copy for BI users', $result['results'][0]['message']);
    }

    public function testADatabaseThatKeepsCommentsMustBeMarkedEvenWithTheConfirmation(): void
    {
        $result = $this->runner(['forensic_database_access_restricted' => true], 'Analytics archive')->run();

        self::assertStringContainsString('is not marked as a forensic copy', $result['results'][0]['message']);
        self::assertSame([], $this->forensic()->fetchFirstColumn("SELECT name FROM sqlite_master WHERE type = 'table'"));
    }

    public function testAMarkedDatabaseIsWrittenWithoutTheConfirmationButNotOneMarkedForAnotherInstallation(): void
    {
        $result = $this->runner([], 'Support team only [aggregate-forensic-copy source=AGGREGATE]', 'aggregate')->run();
        self::assertSame(['synced'], array_values(array_unique(array_column($result['results'], 'status'))));

        $this->now = $this->now->modify('+1 day');
        $result = $this->runner([], '[aggregate-forensic-copy source=other_aggregate]', 'aggregate')->run();
        self::assertStringContainsString('forensic copy of the database other_aggregate, not of this installation\'s database aggregate', $result['results'][0]['message']);
    }

    public function testTheApplicationAndReportingDatabasesAreRefused(): void
    {
        $application = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->projectDir.'/var/data.db']);
        $sameAsApplication = new ForensicDatabaseConnection('sqlite:///%kernel.project_dir%/var/./data.db', $this->projectDir, $application, new ReportingDatabaseConnection(null, $this->projectDir, $application), $this->settings([]));
        try {
            $sameAsApplication->connection();
            self::fail('The application database must be refused.');
        } catch (ReportingDatabaseException $e) {
            self::assertStringContainsString('FORENSIC_DATABASE_URL names the application database', $e->getMessage());
        }

        $reporting = new ReportingDatabaseConnection('sqlite:///%kernel.project_dir%/var/reporting.db', $this->projectDir, $this->application);
        $sameAsReporting = new ForensicDatabaseConnection('sqlite:///%kernel.project_dir%/var/reporting.db', $this->projectDir, $this->application, $reporting, $this->settings([]));
        $this->expectException(ReportingDatabaseException::class);
        $this->expectExceptionMessage('FORENSIC_DATABASE_URL names the reporting database, whose BI users can read every table in it');
        $sameAsReporting->connection();
    }

    public function testCheckListsTheCopiesAndSaysWhatAllowsThem(): void
    {
        $this->runner(['forensic_database_access_restricted' => true])->run();
        $check = $this->runner(['forensic_database_access_restricted' => true])->check();

        self::assertSame(['forensic_events_v1', 'forensic_archive_events_v1', 'forensic_archive_geo_events_v1', 'forensic_archive_goals_v1', 'analytics_custom_events_v1'], $check['tables']);
        self::assertNull($check['mark']);
        self::assertStringContainsString('confirms that access to it is restricted', ForensicDatabaseConnection::markSentence($check['mark']));
    }

    public function testTheForensicCopiesAreNeverOfferedToBiToolsTheApiOrAssistants(): void
    {
        foreach (array_keys(ForensicCatalog::TABLES) as $table) {
            // The reporting database, BigQuery, the reporting API and the MCP server all read ViewCatalog.
            self::assertFalse(ViewCatalog::isKnown($table), $table);
        }
    }

    /**
     * @param array<string, mixed> $values
     * @param string|null          $comment the forensic database's comment, standing in for an engine that keeps one; null for SQLite's none
     */
    private function runner(array $values = [], ?string $comment = null, ?string $applicationDatabase = null): ForensicDatabaseSyncRunner
    {
        $customData = $this->createStub(CustomDataSettings::class);
        $customData->method('numericReportingColumns')->willReturn([]);
        $settings = $this->settings($values);
        $reporting = new ReportingDatabaseConnection(null, $this->projectDir, $this->application);
        $connection = $comment === null
            ? new ForensicDatabaseConnection(self::FORENSIC_URL, $this->projectDir, $this->application, $reporting, $settings)
            : new class(self::FORENSIC_URL, $this->projectDir, $this->application, $reporting, $settings, $comment, $applicationDatabase) extends ForensicDatabaseConnection {
                public function __construct(string $url, string $projectDir, Connection $application, ReportingDatabaseConnection $reporting, ForensicDatabaseSettings $settings, private readonly string $fakeComment, private readonly ?string $fakeApplication)
                {
                    parent::__construct($url, $projectDir, $application, $reporting, $settings);
                }

                protected function comment(): string
                {
                    return $this->fakeComment;
                }

                public function applicationDatabase(): ?string
                {
                    return $this->fakeApplication;
                }
            };
        $views = new ViewReader($this->application, new ViewCatalog($customData));

        return new ForensicDatabaseSyncRunner(
            $settings,
            $connection,
            new ViewExporter(new ForensicReader($this->application, $views), $this->projectDir),
            $this->tasks(),
            new NullLogger(),
            fn (): \DateTimeImmutable => $this->now,
        );
    }

    /** @param array<string, mixed> $values */
    private function settings(array $values): ForensicDatabaseSettings
    {
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump([
            'forensic_database_enabled' => true,
            'forensic_database_views' => ['analytics_custom_events_v1'],
            ...$values,
        ], 4, 2));

        return new ForensicDatabaseSettings(new AggregateConfigLoader($this->projectDir, 'test'));
    }

    private function tasks(): ProcessingTasks
    {
        return new ProcessingTasks($this->application, new AuditTrail($this->application));
    }

    private function forensic(): Connection
    {
        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->projectDir.'/var/forensic.db']);
    }
}
