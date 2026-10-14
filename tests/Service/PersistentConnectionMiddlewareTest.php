<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\PersistentConnectionMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PersistentConnectionMiddlewareTest extends TestCase
{
    #[DataProvider('urls')]
    public function testTheUrlOptionIsReadAsYesOrNo(string $url, ?bool $expected): void
    {
        $params = PersistentConnectionMiddleware::normalize((new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url));

        self::assertSame($expected, $params['persistent'] ?? null);
    }

    public static function urls(): iterable
    {
        $base = 'postgresql://u:p@localhost:5432/aggregate?serverVersion=16';
        yield 'written by the setup page' => [$base.'&persistent=true', true];
        yield 'one' => [$base.'&persistent=1', true];
        yield 'false turns it off' => [$base.'&persistent=false', false];
        yield 'any case' => [$base.'&persistent=FALSE', false];
        yield 'zero' => [$base.'&persistent=0', false];
        yield 'empty' => [$base.'&persistent=', false];
        yield 'absent' => [$base, null];
    }

    public function testWrappedDriversReceiveTheNormalizedParameters(): void
    {
        $received = null;
        $driver = $this->createMock(Driver::class);
        $driver->expects(self::once())->method('connect')->willReturnCallback(function (array $params) use (&$received): DriverConnection {
            $received = $params;

            return $this->createStub(DriverConnection::class);
        });

        (new PersistentConnectionMiddleware())->wrap($driver)->connect(['driver' => 'pdo_pgsql', 'persistent' => 'false', 'password' => 'x']);

        self::assertSame(['driver' => 'pdo_pgsql', 'persistent' => false, 'password' => 'x'], $received);
    }
}
