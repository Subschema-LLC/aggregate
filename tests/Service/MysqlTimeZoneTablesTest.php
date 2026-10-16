<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\MysqlTimeZoneTables;
use App\Service\MysqlTimeZoneTablesMissing;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use PHPUnit\Framework\TestCase;

final class MysqlTimeZoneTablesTest extends TestCase
{
    public function testPostgreSqlDoesNotNeedTheTables(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $connection->expects(self::never())->method('fetchOne');
        $tables = new MysqlTimeZoneTables($connection, sys_get_temp_dir().'/no-shadow');

        self::assertFalse($tables->applies());
        self::assertSame('This database does not need MySQL time zone tables.', $tables->load());
    }

    public function testMySqlCommandIsOnePipeline(): void
    {
        $tables = $this->tables(null);

        self::assertSame(
            'mysql_tzinfo_to_sql /usr/share/zoneinfo | mysql -u root -p mysql',
            $tables->operatorCommand(),
        );
    }

    public function testMariaDbUsesItsOwnToolNames(): void
    {
        $tables = $this->tables('11.4.2-MariaDB');

        self::assertSame(
            'mariadb-tzinfo-to-sql /usr/share/zoneinfo | mariadb -u root -p mysql',
            $tables->operatorCommand(),
        );
    }

    public function testPleskUsesTheAdminPasswordFile(): void
    {
        $shadow = tempnam(sys_get_temp_dir(), 'aggregate-psa-');
        self::assertIsString($shadow);
        file_put_contents($shadow, "secret\n");
        try {
            $tables = $this->tables(null, $shadow);

            self::assertSame(
                'mysql_tzinfo_to_sql /usr/share/zoneinfo | mysql -u admin -p"$(sudo cat /etc/psa/.psa.shadow)" mysql',
                $tables->operatorCommand(),
            );
        } finally {
            unlink($shadow);
        }
    }

    public function testAMissingConversionNamesTheZoneAndTheCommand(): void
    {
        $tables = $this->tables(null);

        try {
            $tables->assertConverts('America/Chicago');
            self::fail('MySQL accepted a named zone without time zone tables.');
        } catch (MysqlTimeZoneTablesMissing $e) {
            self::assertStringContainsString('MySQL cannot convert America/Chicago', $e->getMessage());
            self::assertSame('mysql_tzinfo_to_sql /usr/share/zoneinfo | mysql -u root -p mysql', $e->command());
        }
    }

    public function testLoadDoesNotRunWhenTheToolsAreMissing(): void
    {
        $tables = new MysqlTimeZoneTables(
            $this->connection(null),
            sys_get_temp_dir().'/no-shadow',
            sys_get_temp_dir().'/no-loader',
            sys_get_temp_dir().'/no-client',
            sys_get_temp_dir().'/no-zoneinfo',
        );

        $this->expectExceptionMessage('mysql_tzinfo_to_sql /usr/share/zoneinfo | mysql -u root -p mysql');
        $tables->load();
    }

    private function tables(?string $version, string $shadow = ''): MysqlTimeZoneTables
    {
        return new MysqlTimeZoneTables($this->connection($version), $shadow !== '' ? $shadow : sys_get_temp_dir().'/no-shadow');
    }

    private function connection(?string $version): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQL80Platform());
        $connection->method('fetchOne')->willReturnCallback(static function (string $sql) use ($version): ?string {
            if (str_contains($sql, 'VERSION()')) {
                return $version;
            }

            return null;
        });

        return $connection;
    }
}
