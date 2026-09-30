<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\SqlServerCharsetMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SqlServerCharsetMiddlewareTest extends TestCase
{
    #[DataProvider('connections')]
    public function testOnlyTheBundleDefaultForTheNativeSqlServerDriverChanges(array $params, ?string $expected): void
    {
        self::assertSame($expected, SqlServerCharsetMiddleware::normalize($params)['charset'] ?? null);
    }

    public static function connections(): iterable
    {
        yield 'native sqlsrv with the bundle default' => [['driver' => 'sqlsrv', 'charset' => 'utf8'], 'UTF-8'];
        yield 'native sqlsrv, any case' => [['driver' => 'sqlsrv', 'charset' => 'UTF8'], 'UTF-8'];
        yield 'native sqlsrv with an explicit charset' => [['driver' => 'sqlsrv', 'charset' => 'UTF-8'], 'UTF-8'];
        yield 'native sqlsrv without a charset' => [['driver' => 'sqlsrv'], null];
        yield 'pdo_sqlsrv ignores the charset' => [['driver' => 'pdo_sqlsrv', 'charset' => 'utf8'], 'utf8'];
        yield 'PostgreSQL keeps utf8' => [['driver' => 'pdo_pgsql', 'charset' => 'utf8'], 'utf8'];
    }

    public function testWrappedDriversReceiveTheNormalizedParameters(): void
    {
        $received = null;
        $driver = $this->createMock(Driver::class);
        $driver->expects(self::once())->method('connect')->willReturnCallback(function (array $params) use (&$received): DriverConnection {
            $received = $params;

            return $this->createStub(DriverConnection::class);
        });

        (new SqlServerCharsetMiddleware())->wrap($driver)->connect(['driver' => 'sqlsrv', 'charset' => 'utf8', 'password' => 'x']);

        self::assertSame(['driver' => 'sqlsrv', 'charset' => 'UTF-8', 'password' => 'x'], $received);
    }
}
