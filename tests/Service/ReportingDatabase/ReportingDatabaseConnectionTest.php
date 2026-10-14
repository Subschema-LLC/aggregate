<?php

declare(strict_types=1);

namespace App\Tests\Service\ReportingDatabase;

use App\Service\ReportingDatabase\ReportingDatabaseConnection;
use App\Service\ReportingDatabase\ReportingDatabaseException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReportingDatabaseConnectionTest extends TestCase
{
    public function testItDescribesTheDatabaseWithoutCredentials(): void
    {
        $connection = new ReportingDatabaseConnection('postgresql://writer:p%40ss@db.example.net/aggregate_reporting?serverVersion=16', '/srv/aggregate', $this->application());

        self::assertTrue($connection->isConfigured());
        self::assertSame(['engine' => 'PostgreSQL', 'host' => 'db.example.net', 'port' => 5432, 'database' => 'aggregate_reporting'], $connection->describe());
        self::assertSame('PostgreSQL database aggregate_reporting on db.example.net:5432', ReportingDatabaseConnection::label($connection->describe()));

        $sqlite = new ReportingDatabaseConnection('sqlite:///%kernel.project_dir%/var/reporting.db', '/srv/aggregate', $this->application());
        self::assertSame('SQLite file var/reporting.db', ReportingDatabaseConnection::label($sqlite->describe()));
        self::assertSame('SQL Server', (new ReportingDatabaseConnection('sqlsrv://u:p@sql.example.net:1433/reporting', '/srv', $this->application()))->describe()['engine']);
    }

    /** @return iterable<string, array{0: ?string, 1: string}> */
    public static function unusableUrls(): iterable
    {
        yield 'not set' => [null, 'REPORTING_DATABASE_URL is not set.'];
        yield 'blank' => ['  ', 'REPORTING_DATABASE_URL is not set.'];
        yield 'not a URL' => ['reporting database please', 'must use a MySQL, MariaDB, PostgreSQL, SQL Server or SQLite database URL'];
        yield 'unsupported engine' => ['oci8://u:p@oracle.example.net/reporting', 'must use a MySQL, MariaDB, PostgreSQL, SQL Server or SQLite database URL'];
        yield 'no database name' => ['mysql://u:p@localhost:3306', 'must name a database'];
        yield 'in-memory SQLite' => ['sqlite:///:memory:', 'needs a file path'];
    }

    #[DataProvider('unusableUrls')]
    public function testUnusableUrlsAreExplainedWithoutRepeatingThem(?string $url, string $message): void
    {
        $connection = new ReportingDatabaseConnection($url, '/srv/aggregate', $this->application());
        try {
            $connection->connection();
            self::fail('An unusable URL was accepted.');
        } catch (ReportingDatabaseException $e) {
            self::assertStringContainsString($message, $e->getMessage());
            if ($url !== null && trim($url) !== '') {
                self::assertStringNotContainsString($url, $e->getMessage());
            }
        }
    }

    public function testTheApplicationDatabaseIsRefusedOnTheSameServer(): void
    {
        $application = $this->createStub(Connection::class);
        $application->method('getParams')->willReturn(['driver' => 'pdo_mysql', 'host' => '127.0.0.1', 'port' => 3306, 'dbname' => 'aggregate']);

        foreach (['mysql://other:pw@localhost/Aggregate', 'mysql://u:p@127.0.0.1:3306/aggregate?serverVersion=8.0'] as $url) {
            try {
                (new ReportingDatabaseConnection($url, '/srv', $application))->connection();
                self::fail('The application database was accepted: '.$url);
            } catch (ReportingDatabaseException $e) {
                self::assertStringContainsString('names the application database', $e->getMessage());
            }
        }
        // Another database on the same server, or the same name on another server, is fine.
        foreach (['mysql://u:p@localhost/aggregate_reporting', 'mysql://u:p@reporting.example.net/aggregate', 'postgresql://u:p@localhost:5432/aggregate'] as $url) {
            self::assertInstanceOf(Connection::class, (new ReportingDatabaseConnection($url, '/srv', $application))->connection());
        }
    }

    public function testMysqlConnectionsUseFourByteUtf8UnlessTheUrlSaysOtherwise(): void
    {
        $connection = (new ReportingDatabaseConnection('mysql://u:p@localhost/aggregate_reporting', '/srv', $this->application()))->connection();
        self::assertSame('utf8mb4', $connection->getParams()['charset']);

        $connection = (new ReportingDatabaseConnection('mysql://u:p@localhost/aggregate_reporting?charset=utf8', '/srv', $this->application()))->connection();
        self::assertSame('utf8', $connection->getParams()['charset']);
    }

    private function application(): Connection
    {
        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    }
}
