<?php

declare(strict_types=1);

namespace App\Tests\Service\Glossary;

use App\Service\Glossary\EventNameSuggestions;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\TestCase;

final class EventNameSuggestionsTest extends TestCase
{
    public function testOnlySafeUndeclaredNamesAreReturnedFromABoundedRead(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());
        $connection->method('createQueryBuilder')->willReturnCallback(static fn (): QueryBuilder => new QueryBuilder($connection));
        $result = $this->createStub(Result::class);
        $result->method('fetchFirstColumn')->willReturn(['view', 'known_name', 'newsletter_signup', 'newsletter_signup', '<script>', 'download', null]);
        $connection->expects(self::once())->method('executeQuery')->with('SELECT event_name FROM events ORDER BY id DESC LIMIT 1000')->willReturn($result);
        $connection->expects(self::never())->method('executeStatement');
        self::assertSame(['download', 'newsletter_signup'], (new EventNameSuggestions($connection))->find(['view', 'known_name']));
    }

    public function testSampleIsLatestThousandNamesOnlyAndNeverPersistsSuggestions(): void
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is unavailable.');
        }
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE events (id INTEGER PRIMARY KEY, event_name TEXT)');
        $connection->insert('events', ['id' => 1, 'event_name' => 'too_old']);
        for ($id = 2; $id <= 1001; ++$id) {
            $connection->insert('events', ['id' => $id, 'event_name' => 'view']);
        }
        foreach (['known_name', 'newsletter_signup', 'newsletter_signup', '<script>', 'download'] as $offset => $name) {
            $connection->insert('events', ['id' => 1002 + $offset, 'event_name' => $name]);
        }
        $before = $connection->fetchAllAssociative('SELECT * FROM events');

        self::assertSame(['download', 'newsletter_signup'], (new EventNameSuggestions($connection))->find(['view', 'known_name']));
        self::assertSame($before, $connection->fetchAllAssociative('SELECT * FROM events'));
        $connection->close();
    }
}
