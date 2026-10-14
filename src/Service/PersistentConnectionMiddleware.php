<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use SensitiveParameter;

/**
 * Reads `persistent` in DATABASE_URL as a yes/no value.
 *
 * A URL carries every option as text, and Doctrine's PDO drivers turn
 * persistent connections on for any non-empty value, so `persistent=false`
 * would turn them on. The setup page writes `persistent=true` for PostgreSQL;
 * `persistent=false`, `persistent=0` or removing the option turns it off.
 */
final class PersistentConnectionMiddleware implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            public function connect(#[SensitiveParameter] array $params): DriverConnection
            {
                return parent::connect(PersistentConnectionMiddleware::normalize($params));
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
        if (!array_key_exists('persistent', $params)) {
            return $params;
        }
        $value = $params['persistent'];
        $params['persistent'] = is_string($value)
            ? !in_array(strtolower(trim($value)), ['', '0', 'false', 'no', 'off'], true)
            : (bool) $value;

        return $params;
    }
}
