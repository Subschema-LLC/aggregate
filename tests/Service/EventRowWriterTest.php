<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Event;
use App\Service\EventRowWriter;
use App\Tests\Support\EventRowParity;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

final class EventRowWriterTest extends TestCase
{
    private ?string $file = null;
    private EntityManager $manager;

    protected function setUp(): void
    {
        // Set AGGREGATE_TEST_EVENT_DATABASE_URL to compare on another engine.
        $url = getenv('AGGREGATE_TEST_EVENT_DATABASE_URL');
        if (!is_string($url) || $url === '') {
            $this->file = sys_get_temp_dir().'/aggregate-writer-'.bin2hex(random_bytes(6)).'.sqlite';
            $url = 'pdo-sqlite:///'.$this->file;
        }
        $parser = new DsnParser(['pdo-sqlite' => 'pdo_sqlite', 'sqlite' => 'pdo_sqlite', 'postgresql' => 'pdo_pgsql', 'mysql' => 'pdo_mysql', 'sqlsrv' => 'pdo_sqlsrv']);
        $this->manager = EventRowParity::entityManager(DriverManager::getConnection($parser->parse($url)), dirname(__DIR__, 2).'/src/Entity');
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

    public function testColumnsAndTypesMatchTheEntityMapping(): void
    {
        $metadata = $this->manager->getClassMetadata(Event::class);
        $mapped = [];
        foreach ($metadata->getFieldNames() as $field) {
            if ($metadata->isIdentifier($field)) {
                continue;
            }
            $mapping = $metadata->getFieldMapping($field);
            $mapped[$mapping->columnName] = $mapping->type;
        }
        ksort($mapped);
        $written = EventRowWriter::COLUMN_TYPES;
        ksort($written);

        self::assertSame($mapped, $written, 'A column added to Event must be added to EventRowWriter::COLUMN_TYPES and ::row().');
        self::assertSame(array_keys(EventRowWriter::COLUMN_TYPES), array_keys(EventRowWriter::row(EventRowParity::examples()['enhanced event with nothing optional']())));
        self::assertSame(EventRowWriter::TABLE, $metadata->getTableName());
    }

    public function testTheWriterStoresTheSameRowsAsDoctrineOrm(): void
    {
        self::assertSame([], EventRowParity::differences($this->manager->getConnection(), $this->manager));
    }

    public function testTheWriterAppliesTheEntityPrivacyRulesBeforeWriting(): void
    {
        $connection = $this->manager->getConnection();
        $event = EventRowParity::examples()['anonymous view with properties, geography and the traffic flag']();

        (new EventRowWriter($connection))->insert($event);

        $row = $connection->fetchAssociative('SELECT * FROM events');
        self::assertIsArray($row);
        self::assertNull($row['visitor_id']);
        self::assertNull($row['session_id']);
        self::assertNull($row['screen_width']);
        self::assertNull($row['generalized_user_agent']);
        self::assertNull($row['consent_state']);
        self::assertStringStartsWith('2026-11-10 14:00:00', (string) $row['created_at']);
        self::assertSame(
            // An out-of-range page_sequence and an unknown entry channel are dropped.
            ['plan' => 'Prò', 'org_internal_traffic' => true],
            json_decode((string) $row['custom_data'], true, flags: \JSON_THROW_ON_ERROR),
        );
        self::assertNull($event->getId(), 'The writer does not read back the generated ID.');
    }

    public function testARowThatBreaksTheRulesIsRefusedAndNothingIsWritten(): void
    {
        $connection = $this->manager->getConnection();
        $event = EventRowParity::examples()['anonymous goal without properties']()->setUrl('/pricing?email=person@example.com');

        try {
            (new EventRowWriter($connection))->insert($event);
            self::fail('An anonymous event with a query string was written.');
        } catch (\LogicException $e) {
            self::assertSame('Anonymous event URLs must be sanitized paths.', $e->getMessage());
        }

        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM events'));
    }

    public function testEnhancedEventsWithoutGrantedConsentAreRefused(): void
    {
        $event = EventRowParity::examples()['enhanced event with nothing optional']()->setConsentState(null);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Enhanced events require granted consent.');
        (new EventRowWriter($this->manager->getConnection()))->insert($event);
    }
}
