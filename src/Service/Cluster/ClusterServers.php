<?php

declare(strict_types=1);

namespace App\Service\Cluster;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * What the administration server knows about the other servers, in
 * var/cluster/servers.json: when each last pulled its configuration (or was
 * first expected to), the version and release it reported, and whether an
 * alert says it has stopped pulling. Kept in var/, not a table: it is this
 * server's own record, and the other servers never read it.
 *
 * @phpstan-type Server array{first_seen_at: int, last_pull_at: ?int, version: ?string, release: ?string, role: ?string, address: ?string, alert_since: ?int}
 */
class ClusterServers
{
    public const FILE = 'var/cluster/servers.json';

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /** @return array<string, Server> by server name */
    public function all(): array
    {
        $raw = @file_get_contents($this->path(), false, null, 0, 1048576);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data) || !is_array($data['servers'] ?? null)) {
            return [];
        }
        $servers = [];
        foreach ($data['servers'] as $name => $server) {
            if (is_string($name) && is_array($server) && is_int($server['first_seen_at'] ?? null)) {
                $servers[$name] = [
                    'first_seen_at' => $server['first_seen_at'],
                    'last_pull_at' => is_int($server['last_pull_at'] ?? null) ? $server['last_pull_at'] : null,
                    'version' => is_string($server['version'] ?? null) ? $server['version'] : null,
                    'release' => is_string($server['release'] ?? null) ? $server['release'] : null,
                    'role' => is_string($server['role'] ?? null) ? $server['role'] : null,
                    'address' => is_string($server['address'] ?? null) ? $server['address'] : null,
                    'alert_since' => is_int($server['alert_since'] ?? null) ? $server['alert_since'] : null,
                ];
            }
        }

        return $servers;
    }

    /** Records a pull: the server now has $version. */
    public function recordPull(string $name, int $now, string $version, ?string $release, ?string $role, ?string $address): void
    {
        $this->update(static function (array $servers) use ($name, $now, $version, $release, $role, $address): array {
            $server = $servers[$name] ?? ['first_seen_at' => $now, 'alert_since' => null];
            $servers[$name] = [
                'first_seen_at' => $server['first_seen_at'],
                'last_pull_at' => $now,
                'version' => $version,
                'release' => $release !== null ? substr($release, 0, 64) : null,
                'role' => $role !== null && in_array($role, ServerRole::ROLES, true) ? $role : null,
                'address' => $address,
                'alert_since' => $server['alert_since'],
            ];

            return $servers;
        });
    }

    /**
     * Reconciles the record with the configured servers: new ones are
     * expected from now, removed ones are forgotten.
     *
     * @param list<string> $names
     * @return array<string, Server>
     */
    public function expect(array $names, int $now): array
    {
        return $this->update(static function (array $servers) use ($names, $now): array {
            $kept = [];
            foreach ($names as $name) {
                $kept[$name] = $servers[$name] ?? ['first_seen_at' => $now, 'last_pull_at' => null, 'version' => null, 'release' => null, 'role' => null, 'address' => null, 'alert_since' => null];
            }

            return $kept;
        });
    }

    public function setAlert(string $name, ?int $since): void
    {
        $this->update(static function (array $servers) use ($name, $since): array {
            if (isset($servers[$name])) {
                $servers[$name]['alert_since'] = $since;
            }

            return $servers;
        });
    }

    /**
     * @param callable(array<string, Server>): array<string, Server> $change
     * @return array<string, Server>
     */
    private function update(callable $change): array
    {
        $path = $this->path();
        if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0775, true) && !is_dir(dirname($path))) {
            throw new \RuntimeException('var/cluster could not be created.');
        }
        $lock = @fopen($path.'.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new \RuntimeException(self::FILE.' could not be locked.');
        }
        try {
            $servers = $change($this->all());
            $json = json_encode(['servers' => $servers], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n";
            $temporary = $path.'.'.bin2hex(random_bytes(6)).'.tmp';
            if (@file_put_contents($temporary, $json) !== strlen($json) || !@rename($temporary, $path)) {
                @unlink($temporary);
                throw new \RuntimeException(self::FILE.' could not be written.');
            }

            return $servers;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function path(): string
    {
        return $this->projectDir.'/'.self::FILE;
    }
}
