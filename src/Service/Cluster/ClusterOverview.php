<?php

declare(strict_types=1);

namespace App\Service\Cluster;

use App\Service\AggregateConfigLoader;
use App\Service\InstalledRelease;

/**
 * Every server in the cluster list, as the administration server knows it:
 * its role, the release and configuration version it last reported, and
 * whether it is behind this server. Shown on the Updates page and by
 * app:cluster:server list, so both say the same.
 *
 * @phpstan-type ServerRow array{
 *     name: string,
 *     address: string,
 *     port: ?int,
 *     role: ?string,
 *     reported_role: ?string,
 *     release: ?string,
 *     same_release: ?bool,
 *     version: ?string,
 *     newest_configuration: ?bool,
 *     last_pull_at: ?int,
 *     alerting_since: ?int
 * }
 */
class ClusterOverview
{
    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly ClusterServers $servers,
        private readonly ClusterConfigPublisher $publisher,
        private readonly InstalledRelease $release,
    ) {
    }

    /**
     * @return array{release: ?string, version: ?string, servers: list<ServerRow>}
     */
    public function overview(): array
    {
        try {
            $release = $this->release->read()['version'] ?? null;
        } catch (\RuntimeException) {
            $release = null;
        }
        try {
            $version = $this->publisher->recordedVersion();
        } catch (\Throwable) {
            $version = null;
        }
        $known = $this->servers->all();
        $rows = [];
        foreach ((new ClusterSettings($this->config))->servers() as $server) {
            $record = $known[$server['name']] ?? null;
            $reportedRelease = $record['release'] ?? null;
            $reportedVersion = $record['version'] ?? null;
            $rows[] = [
                'name' => $server['name'],
                'address' => $server['address'],
                'port' => $server['port'],
                'role' => $server['role'],
                'reported_role' => $record['role'] ?? null,
                'release' => $reportedRelease,
                'same_release' => $release !== null && $reportedRelease !== null ? $release === $reportedRelease : null,
                'version' => $reportedVersion,
                'newest_configuration' => $version !== null && $reportedVersion !== null ? hash_equals($version, $reportedVersion) : null,
                'last_pull_at' => $record['last_pull_at'] ?? null,
                'alerting_since' => $record['alert_since'] ?? null,
            ];
        }

        return ['release' => is_string($release) ? $release : null, 'version' => $version, 'servers' => $rows];
    }
}
