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
 *       - { name: collect-1, address: 10.0.0.11 }
 *       - { name: worker-1, address: 10.0.0.21 }
 *     cluster_config_max_age_minutes: 5
 */
final class ClusterSettings
{
    public const SERVERS_KEY = 'cluster';
    public const MAX_AGE_KEY = 'cluster_config_max_age_minutes';
    public const ALERT_DESTINATIONS_KEY = 'cluster_alert_destinations';
    public const SERVER_NAME_KEY = 'cluster_server_name';
    public const SERVER_NAME_VARIABLE = 'CLUSTER_SERVER_NAME';
    public const KEY_VARIABLE = 'CLUSTER_KEY';
    public const DEFAULT_MAX_AGE_MINUTES = 5;
    public const MIN_KEY_LENGTH = 32;
    public const NAME_PATTERN = '/^[a-z0-9](?:[a-z0-9.-]{0,61}[a-z0-9])?$/D';
    public const MAX_SERVERS = 64;

    public function __construct(private readonly AggregateConfigLoader $config)
    {
    }

    /**
     * The servers that may pull configuration, by name. An entry whose name
     * or address is not valid is left out, so it can never pull.
     *
     * @return array<string, array{name: string, address: string}>
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
            $servers[$server['name']] = $server;
        }

        return $servers;
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
     * hyphens, and an IP address or network.
     *
     * @return array{name: string, address: string}
     */
    public static function server(mixed $name, mixed $address): array
    {
        if (!is_string($name) || preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new \InvalidArgumentException('A server name is lower-case letters, digits, dots and hyphens, such as collect-1.');
        }
        if (!is_string($address) || !self::isAddress($address)) {
            throw new \InvalidArgumentException('A server address is an IP address or network, such as 10.0.0.11 or 10.0.0.0/24.');
        }

        return ['name' => $name, 'address' => $address];
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
