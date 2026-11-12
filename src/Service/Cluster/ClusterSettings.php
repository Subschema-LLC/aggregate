<?php

declare(strict_types=1);

namespace App\Service\Cluster;

use App\Service\AggregateConfigLoader;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * The cluster's settings: which servers may pull configuration (`cluster`),
 * the shared key their requests are signed with (CLUSTER_KEY, in .env.local
 * only), this server's name in the cluster, and how long a server may go
 * without the newest configuration before it pauses collection.
 *
 *     cluster:
 *       - { name: collect-1, address: 10.0.0.11, role: collection }
 *       - { name: worker-1, address: 10.0.0.21, role: worker }
 *
 * A server's role here is what the balancer configuration is built from
 * (app:server:balancer-config takes the collection servers, each at its
 * `port` or cluster_collection_port); the server's own SERVER_ROLE decides
 * what it runs.
 *     cluster_config_max_age_minutes: 5
 *     cluster_collection_port: 80
 */
final class ClusterSettings
{
    public const SERVERS_KEY = 'cluster';
    public const MAX_AGE_KEY = 'cluster_config_max_age_minutes';
    public const ALERT_DESTINATIONS_KEY = 'cluster_alert_destinations';
    public const COLLECTION_PORT_KEY = 'cluster_collection_port';
    public const DEFAULT_COLLECTION_PORT = 80;
    public const SERVER_NAME_KEY = 'cluster_server_name';
    public const SERVER_NAME_VARIABLE = 'CLUSTER_SERVER_NAME';
    public const KEY_VARIABLE = 'CLUSTER_KEY';
    public const DEFAULT_MAX_AGE_MINUTES = 5;
    public const MIN_KEY_LENGTH = 32;
    public const NAME_PATTERN = '/^[a-z0-9](?:[a-z0-9.-]{0,61}[a-z0-9])?$/D';
    public const MAX_SERVERS = 64;
    /** The roles a server in the list may have; the administration server is not in it. */
    public const SERVER_ROLES = [ServerRole::COLLECTION, ServerRole::WORKER];

    public function __construct(private readonly AggregateConfigLoader $config)
    {
    }

    /**
     * The servers that may pull configuration, by name. An entry whose name
     * or address is not valid is left out, so it can never pull. A role that
     * is missing or not valid is null, which leaves the server out of the
     * balancer configuration but lets it pull.
     *
     * @return array<string, array{name: string, address: string, role: ?string, port: ?int}>
     */
    public function servers(): array
    {
        $configured = $this->config->get(self::SERVERS_KEY, []);
        if (!is_array($configured)) {
            return [];
        }
        $servers = [];
        foreach (array_slice($configured, 0, self::MAX_SERVERS) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            try {
                $server = self::server($entry['name'] ?? null, $entry['address'] ?? null);
            } catch (\InvalidArgumentException) {
                continue;
            }
            $role = $entry['role'] ?? null;
            $server['role'] = is_string($role) && in_array(strtolower(trim($role)), self::SERVER_ROLES, true) ? strtolower(trim($role)) : null;
            try {
                $server['port'] = isset($entry['port']) ? self::validCollectionPort($entry['port']) : null;
            } catch (\InvalidArgumentException) {
                $server['port'] = null;
            }
            $servers[$server['name']] = $server;
        }

        return $servers;
    }

    /**
     * The servers with that role in the list, by name.
     *
     * @return array<string, array{name: string, address: string, role: ?string, port: ?int}>
     */
    public function serversWithRole(string $role): array
    {
        return array_filter($this->servers(), static fn (array $server): bool => $server['role'] === $role);
    }

    /** The server of that name, when the request came from its address. */
    public function serverAt(string $name, ?string $clientIp): ?array
    {
        $server = $this->servers()[$name] ?? null;
        if ($server === null || $clientIp === null || !IpUtils::checkIp($clientIp, $server['address'])) {
            return null;
        }

        return $server;
    }

    /**
     * A validated server entry: a lower-case name of letters, digits, dots and
     * hyphens, an IP address or network, and, when given, collection or worker.
     *
     * @return array{name: string, address: string, role?: string}
     */
    public static function server(mixed $name, mixed $address, mixed $role = null): array
    {
        if (!is_string($name) || preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new \InvalidArgumentException('A server name is lower-case letters, digits, dots and hyphens, such as collect-1.');
        }
        if (!is_string($address) || !self::isAddress($address)) {
            throw new \InvalidArgumentException('A server address is an IP address or network, such as 10.0.0.11 or 10.0.0.0/24.');
        }

        if ($role === null) {
            return ['name' => $name, 'address' => $address];
        }
        if (!is_string($role) || !in_array($role, self::SERVER_ROLES, true)) {
            throw new \InvalidArgumentException('A server\'s role in the cluster list is collection or worker. The administration server is not in the list, and the balancer runs no Aggregate.');
        }

        return ['name' => $name, 'address' => $address, 'role' => $role];
    }

    public static function isAddress(string $address): bool
    {
        [$ip, $bits] = array_pad(explode('/', $address, 2), 2, null);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        if ($bits === null) {
            return true;
        }
        $max = str_contains((string) $ip, ':') ? 128 : 32;

        return ctype_digit($bits) && (int) $bits >= 1 && (int) $bits <= $max;
    }

    /** Minutes without the newest configuration before collection pauses: 1 to 60, 5 by default. */
    public function maxAgeMinutes(): int
    {
        $value = $this->config->get(self::MAX_AGE_KEY, self::DEFAULT_MAX_AGE_MINUTES);
        $minutes = is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : self::DEFAULT_MAX_AGE_MINUTES;

        return max(1, min(60, $minutes));
    }

    /** A value for cluster_config_max_age_minutes, refused outside 1 to 60. */
    public static function validMaxAgeMinutes(mixed $value): int
    {
        if ((!is_int($value) && !(is_string($value) && ctype_digit($value))) || (int) $value < 1 || (int) $value > 60) {
            throw new \InvalidArgumentException('cluster_config_max_age_minutes is a whole number of minutes from 1 to 60.');
        }

        return (int) $value;
    }

    /**
     * The port the balancer reaches the collection servers at over the
     * private network: cluster_collection_port, 80 by default.
     */
    public function collectionPort(): int
    {
        try {
            return self::validCollectionPort($this->config->get(self::COLLECTION_PORT_KEY, self::DEFAULT_COLLECTION_PORT));
        } catch (\InvalidArgumentException) {
            return self::DEFAULT_COLLECTION_PORT;
        }
    }

    public static function validCollectionPort(mixed $value): int
    {
        if ((!is_int($value) && !(is_string($value) && ctype_digit($value))) || (int) $value < 1 || (int) $value > 65535) {
            throw new \InvalidArgumentException('cluster_collection_port is a port number from 1 to 65535.');
        }

        return (int) $value;
    }

    /**
     * The pause limit, alert destinations and collection port, as entered on
     * the Updates page or with app:config and app:cluster:settings.
     *
     * @param array<string, mixed> $changes
     * @param list<string> $destinations the alert destinations' ids
     *
     * @return array<string, mixed>
     */
    public static function validateSettings(array $changes, array $destinations): array
    {
        $validated = [];
        if (array_key_exists(self::MAX_AGE_KEY, $changes)) {
            $validated[self::MAX_AGE_KEY] = self::validMaxAgeMinutes($changes[self::MAX_AGE_KEY]);
        }
        if (array_key_exists(self::ALERT_DESTINATIONS_KEY, $changes)) {
            $validated[self::ALERT_DESTINATIONS_KEY] = self::validAlertDestinations($changes[self::ALERT_DESTINATIONS_KEY], $destinations);
        }
        if (array_key_exists(self::COLLECTION_PORT_KEY, $changes)) {
            $validated[self::COLLECTION_PORT_KEY] = self::validCollectionPort($changes[self::COLLECTION_PORT_KEY]);
        }

        return $validated;
    }

    /**
     * The alert destinations a server that stopped pulling is reported to, as
     * entered: "all" (null, every destination), "none" ([]), ids separated by
     * commas, or a YAML list of ids, each one a destination that exists.
     *
     * @param list<string> $known the ids of the alert destinations
     *
     * @return list<string>|null null for every destination
     */
    public static function validAlertDestinations(mixed $value, array $known): ?array
    {
        if ($value === null || (is_string($value) && trim($value) === 'all')) {
            return null;
        }
        if (is_string($value) && trim($value) === 'none') {
            return [];
        }
        $ids = is_array($value) ? $value : explode(',', is_string($value) ? $value : '');
        if (array_filter($ids, 'is_string') !== $ids) {
            throw new \InvalidArgumentException('cluster_alert_destinations is a list of alert destination ids, "all" or "none".');
        }
        $ids = array_values(array_unique(array_filter(array_map('trim', $ids), static fn (string $id): bool => $id !== '')));
        if ($ids === [] && !is_array($value)) {
            throw new \InvalidArgumentException('Name the alert destinations, separated by commas, or use "all" or "none".');
        }
        $unknown = array_diff($ids, $known);
        if ($unknown !== []) {
            throw new \InvalidArgumentException(sprintf(
                'No alert destination is called %s. Destinations are listed by app:alerts:destination list.',
                implode(', ', $unknown),
            ));
        }

        return $ids;
    }

    /**
     * The alert destinations told about a server that stopped pulling, or
     * null for every destination.
     *
     * @return list<string>|null
     */
    public function alertDestinations(): ?array
    {
        $value = $this->config->get(self::ALERT_DESTINATIONS_KEY);

        return is_array($value) ? array_values(array_filter($value, 'is_string')) : null;
    }

    /** The shared key, or null when it is missing or too short to use. */
    public function key(): ?string
    {
        $key = $_ENV[self::KEY_VARIABLE] ?? $_SERVER[self::KEY_VARIABLE] ?? null;

        return is_string($key) && strlen(trim($key)) >= self::MIN_KEY_LENGTH ? trim($key) : null;
    }

    /** This server's name in the cluster: CLUSTER_SERVER_NAME, cluster_server_name, or the host name. */
    public function serverName(): string
    {
        $value = $_ENV[self::SERVER_NAME_VARIABLE] ?? $_SERVER[self::SERVER_NAME_VARIABLE] ?? $this->config->get(self::SERVER_NAME_KEY);
        if (is_string($value) && preg_match(self::NAME_PATTERN, strtolower(trim($value))) === 1) {
            return strtolower(trim($value));
        }
        $host = strtolower((string) gethostname());

        return preg_match(self::NAME_PATTERN, $host) === 1 ? $host : 'server';
    }
}
