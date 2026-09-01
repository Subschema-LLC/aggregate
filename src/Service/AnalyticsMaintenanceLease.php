<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

/** A database-backed lease prevents maintenance overlap across application replicas. */
final class AnalyticsMaintenanceLease
{
    private const LEASE_INTERVAL = 'PT2H';

    private ?string $ownerToken = null;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function acquire(\DateTimeImmutable $now): void
    {
        if ($this->ownerToken !== null) {
            throw new \LogicException('The analytics maintenance lease is already held by this process.');
        }

        $ownerToken = bin2hex(random_bytes(32));
        $affected = $this->connection->executeStatement(
            <<<'SQL'
UPDATE analytics_maintenance_lock
SET owner_token = :owner_token,
    expires_at = :expires_at,
    updated_at = :updated_at
WHERE id = 1
  AND (owner_token IS NULL OR expires_at IS NULL OR expires_at < :updated_at)
SQL,
            [
                'owner_token' => $ownerToken,
                'expires_at' => $now->add(new \DateInterval(self::LEASE_INTERVAL)),
                'updated_at' => $now,
            ],
            [
                'owner_token' => Types::STRING,
                'expires_at' => Types::DATETIME_IMMUTABLE,
                'updated_at' => Types::DATETIME_IMMUTABLE,
            ],
        );

        if ((int) $affected !== 1) {
            if ($this->connection->fetchOne(
                'SELECT id FROM analytics_maintenance_lock WHERE id = 1',
            ) === false) {
                throw new \RuntimeException(
                    'The analytics maintenance lock row is missing; run or repair the database migrations.',
                );
            }

            throw new AnalyticsMaintenanceAlreadyRunning(
                'Another analytics maintenance process currently holds the database lease.',
            );
        }

        $this->ownerToken = $ownerToken;
    }

    public function refresh(\DateTimeImmutable $now): void
    {
        if ($this->ownerToken === null) {
            throw new \LogicException('The analytics maintenance lease is not held.');
        }

        $affected = $this->connection->executeStatement(
            <<<'SQL'
UPDATE analytics_maintenance_lock
SET expires_at = :expires_at,
    updated_at = :updated_at
WHERE id = 1 AND owner_token = :owner_token
SQL,
            [
                'owner_token' => $this->ownerToken,
                'expires_at' => $now->add(new \DateInterval(self::LEASE_INTERVAL)),
                'updated_at' => $now,
            ],
            [
                'owner_token' => Types::STRING,
                'expires_at' => Types::DATETIME_IMMUTABLE,
                'updated_at' => Types::DATETIME_IMMUTABLE,
            ],
        );

        // MySQL/MariaDB report zero changed rows when an immediate heartbeat
        // writes the same second-level datetime values. Confirm ownership before
        // treating that driver behavior as a lost lease.
        if ((int) $affected !== 1
            && $this->connection->fetchOne(
                'SELECT owner_token FROM analytics_maintenance_lock WHERE id = 1',
            ) !== $this->ownerToken) {
            throw new \RuntimeException('The analytics maintenance database lease was lost.');
        }
    }

    public function release(\DateTimeImmutable $now): void
    {
        if ($this->ownerToken === null) {
            return;
        }

        try {
            $this->connection->executeStatement(
                <<<'SQL'
UPDATE analytics_maintenance_lock
SET owner_token = NULL,
    expires_at = NULL,
    updated_at = :updated_at
WHERE id = 1 AND owner_token = :owner_token
SQL,
                [
                    'owner_token' => $this->ownerToken,
                    'updated_at' => $now,
                ],
                [
                    'owner_token' => Types::STRING,
                    'updated_at' => Types::DATETIME_IMMUTABLE,
                ],
            );
        } finally {
            $this->ownerToken = null;
        }
    }
}
