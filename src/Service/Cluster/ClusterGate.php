<?php

declare(strict_types=1);

namespace App\Service\Cluster;

use App\Service\AggregateConfigLoader;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Whether this server must pause collection, for /api/receive and
 * /api/health. Uses the role the cache was built for and the state file,
 * never the database, and does nothing at all on `all` and `administration`.
 */
class ClusterGate
{
    public function __construct(
        #[Autowire('%aggregate.server_role.compiled%')]
        private readonly string $role,
        private readonly ClusterState $state,
        private readonly AggregateConfigLoader $config,
    ) {
    }

    /** Why collection is paused, or null when it is not. */
    public function pauseReason(): ?string
    {
        if ($this->role !== ServerRole::COLLECTION && $this->role !== ServerRole::WORKER) {
            return null;
        }

        return $this->state->pauseReason((new ClusterSettings($this->config))->maxAgeMinutes(), time());
    }

    public function inCluster(): bool
    {
        return $this->role !== ServerRole::ALL;
    }
}
