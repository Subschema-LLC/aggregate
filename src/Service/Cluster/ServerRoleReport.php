<?php

declare(strict_types=1);

namespace App\Service\Cluster;

use App\Service\AggregateConfigLoader;
use App\Service\CollectionAddress;
use App\Service\InstalledRelease;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * What app:server:role reports, and what PRAGMA and the setup guides build
 * on: this server's role, release, queue, database connections, and the
 * checks its role needs, each with its fix.
 *
 * The JSON form carries SCHEMA_VERSION. Within a version, fields are only
 * ever added, never renamed or removed, so tools can rely on them.
 */
final class ServerRoleReport
{
    public const SCHEMA_VERSION = 1;
    /** Above this share of max_connections in use, the budget is reported as tight. */
    public const CONNECTION_WARNING_SHARE = 0.7;

    public function __construct(
        private readonly ServerRole $role,
        private readonly AggregateConfigLoader $config,
        private readonly InstalledRelease $release,
        private readonly Connection $connection,
        #[Autowire('%aggregate.server_role.compiled%')]
        private readonly string $compiledRole,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment = 'prod',
    ) {
    }

    /** @return array<string, mixed> */
    public function report(): array
    {
        $database = $this->database();
        $queue = $this->queue();
        $collectionAddress = (new CollectionAddress($this->config))->configuredValue();

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'role' => $this->role->role,
            'configured_role' => $this->role->configured,
            'role_source' => $this->role->source,
            'compiled_role' => $this->compiledRole,
            'problem' => $this->role->problem,
            'release' => $this->release(),
            // Recorded by the configuration pull (app:cluster:sync), once it exists.
            'configuration_version' => null,
            'administration_url' => $this->role->administrationUrl,
            'collection_base_url' => $collectionAddress !== '' ? $collectionAddress : null,
            'serves' => [
                'dashboard' => $this->role->administers(),
                'collection' => $this->role->collects(),
                'scheduled_task' => $this->role->runsScheduledTask(),
                'consumers' => $this->role->runsConsumers(),
            ],
            'queue' => $queue,
            'database' => $database,
            'requirements' => $this->requirements($database, $queue, $collectionAddress),
        ];
    }

    /** Whether any check this role needs has failed outright (not a warning). */
    public static function hasFailures(array $report): bool
    {
        foreach ($report['requirements'] as $requirement) {
            if ($requirement['status'] === 'error') {
                return true;
            }
        }

        return false;
    }

    /** @return array{version: ?string, commit: ?string, built_at: ?string, error: ?string} */
    private function release(): array
    {
        try {
            $release = $this->release->read();
        } catch (\RuntimeException $e) {
            return ['version' => null, 'commit' => null, 'built_at' => null, 'error' => $e->getMessage()];
        }

        return [
            'version' => is_string($release['version'] ?? null) ? $release['version'] : null,
            'commit' => is_string($release['commit'] ?? null) ? $release['commit'] : null,
            'built_at' => is_string($release['built_at'] ?? null) ? $release['built_at'] : null,
            'error' => null,
        ];
    }

    /** @return array{transport: string, scheme: string, note: string} */
    private function queue(): array
    {
        $dsn = $_ENV['MESSENGER_TRANSPORT_DSN'] ?? $_SERVER['MESSENGER_TRANSPORT_DSN'] ?? 'sync://';
        $scheme = is_string($dsn) && preg_match('~^([a-z][a-z0-9+.-]*):~i', $dsn, $match) === 1 ? strtolower($match[1]) : 'sync';
        [$transport, $note] = match ($scheme) {
            'sync' => ['sync', 'Enhanced events are written during the request, by the server that receives them. No worker server is needed.'],
            'doctrine' => ['doctrine', 'Enhanced events wait in the messenger_messages table of the shared database until a consumer writes them. The database headroom test found this the costliest setup for a shared database (docs/LOAD-TESTING.md).'],
            default => ['other', 'Enhanced events wait in an external queue until a consumer writes them.'],
        };

        return ['transport' => $transport, 'scheme' => $scheme, 'note' => $note];
    }

    /**
     * The engine, whether connections stay open, and how many the database
     * allows and has open now. Every server's connections count against the
     * same max_connections; on PostgreSQL each PHP-FPM worker and consumer
     * keeps one open.
     *
     * @return array<string, mixed>
     */
    private function database(): array
    {
        $params = $this->connection->getParams();
        $persistent = filter_var($params['persistent'] ?? false, FILTER_VALIDATE_BOOL);
        $state = ['engine' => 'unknown', 'persistent_connections' => $persistent, 'max_connections' => null, 'open_connections' => null, 'error' => null];
        try {
            $platform = $this->connection->getDatabasePlatform();
            if ($platform instanceof PostgreSQLPlatform) {
                $state['engine'] = 'postgresql';
                $state['max_connections'] = (int) $this->connection->fetchOne('SHOW max_connections');
                $state['open_connections'] = (int) $this->connection->fetchOne('SELECT count(*) FROM pg_stat_activity WHERE datname = current_database()');
            } elseif ($platform instanceof AbstractMySQLPlatform) {
                $state['engine'] = 'mysql';
                $state['max_connections'] = (int) $this->connection->fetchOne('SELECT @@max_connections');
                $row = $this->connection->fetchAssociative("SHOW GLOBAL STATUS LIKE 'Threads_connected'");
                $state['open_connections'] = is_array($row) ? (int) array_values($row)[1] : null;
            } elseif ($platform instanceof SQLitePlatform) {
                $state['engine'] = 'sqlite';
            }
        } catch (\Throwable $e) {
            $state['error'] = 'The database could not be reached: '.$e->getMessage();
        }

        return $state;
    }

    /**
     * @param array<string, mixed> $database
     * @param array{transport: string} $queue
     * @return list<array{id: string, status: 'ok'|'warning'|'error', message: string, fix: ?string}>
     */
    private function requirements(array $database, array $queue, string $collectionAddress): array
    {
        $role = $this->role->role;
        $checks = [];
        $add = static function (string $id, string $status, string $message, ?string $fix = null) use (&$checks): void {
            $checks[] = ['id' => $id, 'status' => $status, 'message' => $message, 'fix' => $fix];
        };

        $add('role', $this->role->problem === null ? 'ok' : 'error',
            $this->role->problem ?? sprintf('This server runs as %s%s.', $role, $this->role->source === 'default' ? ' (no server_role is set)' : ''),
            $this->role->problem === null ? null : 'Set server_role (or SERVER_ROLE) to one of '.implode(', ', ServerRole::ROLES).', on a MySQL, MariaDB or PostgreSQL database for any role but all.');

        $add('cache', $this->compiledRole === $role ? 'ok' : 'error',
            $this->compiledRole === $role
                ? 'The cache was built for this role.'
                : sprintf('The cache was built for the role %s, so this server still serves that role\'s pages and routes.', $this->compiledRole),
            // cache:clear in the same second as the build it replaces keeps that build, so the folder goes.
            $this->compiledRole === $role ? null : sprintf('Remove the cache with "rm -rf var/cache/%s", then reload PHP-FPM.', $this->environment));

        $add('database', $database['error'] === null ? 'ok' : 'error',
            $database['error'] ?? 'The database answers.',
            $database['error'] === null ? null : 'Check DATABASE_URL in .env.local and that this server may connect to the database server.');

        if ($database['max_connections'] !== null && $database['open_connections'] !== null && $database['max_connections'] > 0) {
            $share = $database['open_connections'] / $database['max_connections'];
            $add('connections', $share > self::CONNECTION_WARNING_SHARE ? 'warning' : 'ok',
                sprintf('%d of the database\'s %d connections are open, from every server that uses it%s.',
                    $database['open_connections'], $database['max_connections'],
                    $database['persistent_connections'] ? '; on PostgreSQL each PHP-FPM worker and consumer of every server keeps one open' : ''),
                $share > self::CONNECTION_WARNING_SHARE ? 'Raise max_connections on the database server, or pool connections (PgBouncer in transaction mode for PostgreSQL), before adding servers.' : null);
        }

        if (in_array($role, [ServerRole::COLLECTION, ServerRole::WORKER], true)) {
            $add('administration_url', $this->role->administrationUrl !== null ? 'ok' : 'warning',
                $this->role->administrationUrl !== null
                    ? 'The administration server is '.$this->role->administrationUrl.'.'
                    : 'This server does not know the administration server\'s address, so its messages cannot name it.',
                $this->role->administrationUrl !== null ? null : 'Set ADMINISTRATION_URL in .env.local (or administration_url) to the administration server\'s address.');
        }

        if ($role === ServerRole::ADMINISTRATION) {
            $add('collection_address', $collectionAddress !== '' ? 'ok' : 'warning',
                $collectionAddress !== ''
                    ? 'Tracking code sends websites to '.$collectionAddress.'.'
                    : 'Tracking code would send websites to this administration server, which does not collect.',
                $collectionAddress !== '' ? null : 'Set the collection address (collection_base_url) in General settings to the balancer\'s address, then copy the tracking code again.');
        }

        if ($role === ServerRole::COLLECTION) {
            $trustedProxies = trim((string) ($_ENV['TRUSTED_PROXIES'] ?? $_SERVER['TRUSTED_PROXIES'] ?? ''));
            $add('trusted_proxies', $trustedProxies !== '' ? 'ok' : 'warning',
                $trustedProxies !== ''
                    ? 'Requests from '.$trustedProxies.' may name the visitor\'s address.'
                    : 'Behind a balancer, every request would seem to come from the balancer, so rate limits and geography would see one address.',
                $trustedProxies !== '' ? null : 'Set TRUSTED_PROXIES in .env.local to the balancer\'s private address.');
        }

        if ($role === ServerRole::WORKER && $queue['transport'] === 'sync') {
            $add('queue', 'warning', 'On sync:// enhanced events are written during the request, so this worker server has nothing to consume.',
                'Use the role collection, or set MESSENGER_TRANSPORT_DSN to the queue the collection servers use.');
        } elseif ($role === ServerRole::COLLECTION && $queue['transport'] !== 'sync') {
            $add('queue', 'ok', 'Enhanced events are queued; a worker server (or a server with the role all) must run messenger:consume.');
        } elseif ($role !== ServerRole::ALL && $queue['transport'] === 'doctrine') {
            $add('queue', 'warning', 'The queue is in the shared database, which the database headroom test found its costliest load.',
                'Prefer sync:// for a cluster until a separate broker is supported.');
        }

        return $checks;
    }
}
