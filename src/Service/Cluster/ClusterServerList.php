<?php

declare(strict_types=1);

namespace App\Service\Cluster;

use App\Service\AggregateConfigLoader;

/**
 * Adds and removes the servers in the `cluster` list, for app:cluster:server
 * and the Updates page alike, with the same checks.
 */
final class ClusterServerList
{
    public function __construct(private readonly AggregateConfigLoader $config)
    {
    }

    /**
     * Adds a server, or replaces the one of the same name.
     *
     * @return array{name: string, address: string, role: string, port?: int} the entry saved
     */
    public function add(mixed $name, mixed $address, mixed $role, mixed $port = null): array
    {
        if ($role === null || $role === '') {
            throw new \InvalidArgumentException('Give the server\'s role: collection or worker.');
        }
        $server = ClusterSettings::server($name, $address, $role);
        if ($port !== null && $port !== '') {
            $server['port'] = ClusterSettings::validCollectionPort($port);
        }
        $this->config->updateMany(static function (array $current) use ($server): array {
            $list = array_values(array_filter(
                is_array($current[ClusterSettings::SERVERS_KEY] ?? null) ? $current[ClusterSettings::SERVERS_KEY] : [],
                static fn ($entry): bool => !is_array($entry) || ($entry['name'] ?? null) !== $server['name'],
            ));
            $list[] = $server;

            return [ClusterSettings::SERVERS_KEY => $list];
        });

        return $server;
    }

    public function remove(mixed $name): void
    {
        if (!is_string($name) || $name === '') {
            throw new \InvalidArgumentException('Name the server to remove.');
        }
        $this->config->updateMany(static fn (array $current): array => [ClusterSettings::SERVERS_KEY => array_values(array_filter(
            is_array($current[ClusterSettings::SERVERS_KEY] ?? null) ? $current[ClusterSettings::SERVERS_KEY] : [],
            static fn ($entry): bool => !is_array($entry) || ($entry['name'] ?? null) !== $name,
        ))]);
    }
}
