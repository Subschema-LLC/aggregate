<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Event;
use App\Repository\EventRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Runs against SQLite. Set AGGREGATE_TEST_EVENT_DATABASE_URL to a disposable
 * PostgreSQL, MySQL, MariaDB or SQL Server database to run it there too.
 */
final class EventRepositoryTest extends TestCase
{
    private ?string $file = null;
    private EntityManager $manager;

    protected function setUp(): void
    {
        $url = getenv('AGGREGATE_TEST_EVENT_DATABASE_URL');
        if (!is_string($url) || $url === '') {
            $this->file = sys_get_temp_dir().'/aggregate-events-'.bin2hex(random_bytes(6)).'.sqlite';
            $url = 'pdo-sqlite:///'.$this->file;
        }
        $config = ORMSetup::createAttributeMetadataConfig([dirname(__DIR__, 2).'/src/Entity'], true);
        $config->setNamingStrategy(new UnderscoreNamingStrategy(\CASE_LOWER));
        if (\PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        } else {
            $config->setProxyDir(sys_get_temp_dir());
            $config->setProxyNamespace('AggregateTestProxies');
        }
        $parser = new DsnParser(['pdo-sqlite' => 'pdo_sqlite', 'sqlite' => 'pdo_sqlite', 'postgresql' => 'pdo_pgsql', 'mysql' => 'pdo_mysql', 'sqlsrv' => 'pdo_sqlsrv']);
        $connection = DriverManager::getConnection($parser->parse($url), $config);
        $this->manager = new EntityManager($connection, $config);
        $metadata = [$this->manager->getClassMetadata(Event::class)];
        $tool = new SchemaTool($this->manager);
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        (new SchemaTool($this->manager))->dropSchema([$this->manager->getClassMetadata(Event::class)]);
        $this->manager->getConnection()->close();
        if ($this->file !== null) {
            @unlink($this->file);
        }
    }

    public function testItFindsEachWebsitesNewestEventAndSkipsWebsitesWithout(): void
    {
        $connection = $this->manager->getConnection();
        foreach ([
            ['first', '2026-10-01 08:00:00'],
            ['first', '2026-10-15 23:00:00'],
            ['first', '2026-10-03 12:00:00'],
            ['second', '2026-09-30 17:00:00'],
            ['unregistered', '2026-10-16 09:00:00'],
        ] as [$token, $createdAt]) {
            $this->insert($connection, $token, $createdAt);
        }

        $latest = $this->repository()->findLastEventDatesByTokens(['first', 'second', 'never-sent', '', 'first']);

        self::assertSame(['first', 'second'], array_keys($latest));
        self::assertSame('2026-10-15 23:00:00', $latest['first']->format('Y-m-d H:i:s'));
        self::assertSame('2026-09-30 17:00:00', $latest['second']->format('Y-m-d H:i:s'));
        self::assertSame([], $this->repository()->findLastEventDatesByTokens([]));
    }

    public function testTheMappingIndexesEventsByWebsiteAndTime(): void
    {
        $indexes = $this->manager->getClassMetadata(Event::class)->table['indexes'];

        self::assertSame(['website_token', 'created_at'], $indexes['IDX_EVENTS_TOKEN_CREATED']['columns']);
        self::assertArrayNotHasKey('IDX_EVENTS_WEBSITE_TOKEN', $indexes, 'the composite index replaces the token-only one');
    }

    private function repository(): EventRepository
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->manager);

        return new EventRepository($registry);
    }

    private function insert(Connection $connection, string $token, string $createdAt): void
    {
        $connection->insert('events', [
            'website_token' => $token, 'event_name' => 'view', 'url' => '/', 'privacy_mode' => 'anonymous',
            'device_class' => 'unknown', 'viewport_bucket' => 'unknown', 'created_at' => $createdAt,
        ]);
    }
}
