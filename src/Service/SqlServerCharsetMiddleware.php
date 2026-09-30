<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use SensitiveParameter;

/**
 * Lets the documented `sqlsrv://` DATABASE_URL work with Microsoft's native
 * sqlsrv extension.
 *
 * DoctrineBundle gives every non-MySQL connection the default charset "utf8".
 * Doctrine's sqlsrv driver passes it on as the CharacterSet connection option,
 * and the extension accepts only "UTF-8", so every connection failed with
 * "The encoding 'utf8' is not a supported encoding". pdo_sqlsrv (`mssql://`)
 * ignores the charset and is unaffected. Only that exact default is changed; an
 * explicitly configured charset is passed through as it is.
 */
final class SqlServerCharsetMiddleware implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            public function connect(#[SensitiveParameter] array $params): DriverConnection
            {
                return parent::connect(SqlServerCharsetMiddleware::normalize($params));
            }
        };
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public static function normalize(#[SensitiveParameter] array $params): array
    {
        if (($params['driver'] ?? null) === 'sqlsrv' && is_string($params['charset'] ?? null) && strcasecmp($params['charset'], 'utf8') === 0) {
            $params['charset'] = 'UTF-8';
        }

        return $params;
    }
}
