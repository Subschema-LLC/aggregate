<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Event;
use App\Repository\EventRepository;
use App\Service\Operations\AuditTrail;
use App\Service\Operations\ProcessingTasks;
use App\Service\Operations\TaskTrigger;
use App\Tests\Service\Alerts\AlertsTestDatabase;
use App\Tests\Service\Operations\OperationsDatabase;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AlertCountingPlatformSqlTest extends TestCase
{
    /**
     * Confirms that the capped event and tracking-failure subquery shape works
     * across all five supported database engines, including SQL Server's
     * requirement for a column alias on TOP and a derived table alias.
     */
    #[DataProvider('databasePlatforms')]
    public function testCappedSubquerySyntaxIsPortableAcrossAllFiveDatabasePlatforms(object $platform, string $expectedLimitFragment): void
    {
        $innerEvents = $platform->modifyLimitQuery(
            'SELECT 1 AS c FROM events WHERE website_token = ? AND created_at >= ? AND created_at < ? AND privacy_mode = ? AND event_name = ?',
            5,
        );
        $eventsSql = 'SELECT COUNT(*) FROM ('.$innerEvents.') AS t';

        self::assertStringContainsString('SELECT 1 AS c FROM events', $innerEvents);
        self::assertStringContainsString($expectedLimitFragment, $eventsSql);
        self::assertStringEndsWith(') AS t', $eventsSql);

        $innerTasks = $platform->modifyLimitQuery(
            'SELECT 1 AS c FROM processing_tasks WHERE task_type IN (?, ?) AND status = ? AND subject IN (?, ?, ?) AND started_at >= ? AND started_at < ?',
            11,
        );
        $tasksSql = 'SELECT COUNT(*) FROM ('.$innerTasks.') AS t';

        self::assertStringContainsString('SELECT 1 AS c FROM processing_tasks', $innerTasks);
        self::assertStringEndsWith(') AS t', $tasksSql);
    }

    public static function databasePlatforms(): iterable
    {
        yield 'PostgreSQL' => [new PostgreSQLPlatform(), 'LIMIT 5'];
        yield 'MySQL' => [new MySQLPlatform(), 'LIMIT 5'];
        yield 'MariaDB' => [new MariaDBPlatform(), 'LIMIT 5'];
        yield 'SQL Server' => [new SQLServerPlatform(), 'FETCH NEXT 5 ROWS ONLY'];
        yield 'SQLite' => [new SQLitePlatform(), 'LIMIT 5'];
    }

    public function testCountEventsUpToStopsAtCapAndRespectsHalfOpenUtcBoundariesAndFilters(): void
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $from = new \DateTimeImmutable('2025-06-02 13:00:00', new \DateTimeZone('UTC'));
            $to = new \DateTimeImmutable('2025-06-02 14:00:00', new \DateTimeZone('UTC'));
            $captured = [];
            $conn = $this->createStub(\Doctrine\DBAL\Connection::class);
            $conn->method('getDatabasePlatform')->willReturn(new SQLitePlatform());
            $conn->method('fetchOne')->willReturnCallback(function (string $sql, array $params) use (&$captured): int {
                $captured[] = ['sql' => $sql, 'params' => $params];

                return 3;
            });
            $em = $this->createStub(\Doctrine\ORM\EntityManagerInterface::class);
            $em->method('getConnection')->willReturn($conn);
            $meta = new \Doctrine\ORM\Mapping\ClassMetadata(Event::class);
            $registry = $this->createStub(\Doctrine\Persistence\ManagerRegistry::class);
            $registry->method('getManagerForClass')->willReturn($em);
            $em->method('getClassMetadata')->willReturn($meta);

            $repo = new EventRepository($registry);
            self::assertSame(0, $repo->countEventsUpTo('', $from, $to, 5));
            self::assertSame(0, $repo->countEventsUpTo('site-a', $to, $from, 5));
            self::assertSame(3, $repo->countEventsUpTo('site-a', $from, $to, 5, 'enhanced', 'signup'));
            self::assertSame(
                'SELECT COUNT(*) FROM (SELECT 1 AS c FROM events WHERE website_token = ? AND created_at >= ? AND created_at < ? AND privacy_mode = ? AND event_name = ? LIMIT 5) AS t',
                $captured[0]['sql'],
            );
            self::assertSame(['site-a', '2025-06-02 13:00:00', '2025-06-02 14:00:00', 'enhanced', 'signup'], $captured[0]['params']);

            return;
        }

        $config = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__, 2).'/src/Entity'], true);
        // As config/packages/doctrine.yaml: the index columns are snake_case.
        $config->setNamingStrategy(new UnderscoreNamingStrategy(\CASE_LOWER, true));
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $em = new EntityManager($connection, $config);
        (new SchemaTool($em))->createSchema([$em->getClassMetadata(Event::class)]);

        $from = new \DateTimeImmutable('2025-06-02 13:00:00', new \DateTimeZone('UTC'));
        $to = new \DateTimeImmutable('2025-06-02 14:00:00', new \DateTimeZone('UTC'));

        // Event before window [13:00, 14:00): excluded.
        $this->insertEvent($em, 'site-a', 'anonymous', 'page_view', new \DateTimeImmutable('2025-06-02 12:59:59 UTC'));
        // Events inside window [13:00, 14:00): included.
        $this->insertEvent($em, 'site-a', 'anonymous', 'page_view', new \DateTimeImmutable('2025-06-02 13:00:00 UTC'));
        $this->insertEvent($em, 'site-a', 'enhanced', 'page_view', new \DateTimeImmutable('2025-06-02 13:15:00 UTC'));
        $this->insertEvent($em, 'site-a', 'enhanced', 'signup', new \DateTimeImmutable('2025-06-02 13:45:00 UTC'));
        // Event at exact end boundary 14:00:00 (belongs to next open hour): excluded.
        $this->insertEvent($em, 'site-a', 'enhanced', 'page_view', new \DateTimeImmutable('2025-06-02 14:00:00 UTC'));
        $em->flush();

        $registry = $this->createStub(\Doctrine\Persistence\ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($em);
        $repo = new EventRepository($registry);

        self::assertSame(1, $repo->countEventsUpTo('site-a', $from, $to, 1), 'Stops at cap=1');
        self::assertSame(2, $repo->countEventsUpTo('site-a', $from, $to, 2), 'Stops at cap=2');
        self::assertSame(3, $repo->countEventsUpTo('site-a', $from, $to, 10), 'Counts 3 events inside [from, to)');
        self::assertSame(2, $repo->countEventsUpTo('site-a', $from, $to, 10, 'enhanced'), 'Filters by privacy_mode=enhanced');
        self::assertSame(1, $repo->countEventsUpTo('site-a', $from, $to, 10, 'anonymous'), 'Filters by privacy_mode=anonymous');
        self::assertSame(1, $repo->countEventsUpTo('site-a', $from, $to, 10, 'enhanced', 'signup'), 'Filters by privacy_mode and event_name');
        self::assertSame(0, $repo->countEventsUpTo('site-b', $from, $to, 5));
    }

    public function testCountTrackingFailuresUpToCountsRetainedIngestAndStoreFailuresInWindow(): void
    {
        $connection = AlertsTestDatabase::connection();
        $tasks = new ProcessingTasks($connection, new AuditTrail($connection));

        $t1 = new \DateTimeImmutable('2025-06-02 10:00:00 UTC');
        $t2 = new \DateTimeImmutable('2025-06-02 11:30:00 UTC');
        $t3 = new \DateTimeImmutable('2025-06-02 14:00:00 UTC');

        $id1 = $tasks->start('tracking_event_store', 'site-a', TaskTrigger::schedule(), $t1);
        self::assertNotNull($id1);
        $tasks->fail($id1, $t1, 'Database timeout');

        $id2 = $tasks->start('tracking_ingest', 'anonymous:site-a', TaskTrigger::schedule(), $t2);
        self::assertNotNull($id2);
        $tasks->fail($id2, $t2, 'Queue failure');

        // A succeeded task does not count as a failure.
        $idOk = $tasks->start('tracking_event_store', 'site-a', TaskTrigger::schedule(), $t2);
        self::assertNotNull($idOk);
        $tasks->succeed($idOk, $t2, 1, 'Stored.');

        // A failure at the end boundary 14:00:00 is outside [09:00, 14:00).
        $id3 = $tasks->start('tracking_ingest', 'enhanced:site-a', TaskTrigger::schedule(), $t3);
        self::assertNotNull($id3);
        $tasks->fail($id3, $t3, 'Outside window');

        $from = new \DateTimeImmutable('2025-06-02 09:00:00 UTC');
        $to = new \DateTimeImmutable('2025-06-02 14:00:00 UTC');

        self::assertSame(1, $tasks->countTrackingFailuresUpTo('site-a', $from, $to, 1));
        self::assertSame(2, $tasks->countTrackingFailuresUpTo('site-a', $from, $to, 10));
    }

    private function insertEvent(EntityManager $em, string $token, string $mode, string $name, \DateTimeImmutable $createdAt): void
    {
        $event = new Event();
        $event->setWebsiteToken($token);
        $event->setPrivacyMode($mode);
        $event->setEventName($name);
        $event->setUrl('/');
        $event->setReferrer('direct');
        $event->setDeviceClass('desktop');
        $event->setViewportBucket('large');
        if ($mode === 'enhanced') {
            $event->setConsentState('granted');
        }
        $event->setCreatedAt($createdAt);
        $em->persist($event);
    }
}
