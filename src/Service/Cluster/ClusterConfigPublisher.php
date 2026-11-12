<?php

declare(strict_types=1);

namespace App\Service\Cluster;

use App\Service\Operations\ProcessingTasks;
use App\Service\Operations\TaskTrigger;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Records the version of the configuration the administration server hands
 * out, as the fingerprint of a successful `cluster_config` run in
 * processing_tasks, whenever it differs from the last one recorded. The other
 * servers compare their own version with it: a newer one they cannot fetch
 * means they are behind.
 *
 * processing_tasks keeps the latest row of each status through its purges,
 * so the record never ages out while the configuration stays unchanged.
 */
class ClusterConfigPublisher
{
    public const TASK_TYPE = 'cluster_config';

    private readonly ClockInterface $clock;

    public function __construct(
        private readonly ProcessingTasks $tasks,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? Clock::get();
    }

    /** The last version recorded, or null when none is. Reads the database. */
    public function recordedVersion(): ?string
    {
        $success = $this->tasks->latest(self::TASK_TYPE)['']['success'] ?? null;

        return is_string($success['fingerprint'] ?? null) ? $success['fingerprint'] : null;
    }

    /**
     * Hashes the configuration and records the version when it changed.
     *
     * @return array{version: string, recorded: bool, bundle: array}
     */
    public function publish(TaskTrigger $trigger): array
    {
        $bundle = ConfigBundle::build($this->projectDir);
        if ($this->recordedVersion() === $bundle['version']) {
            return ['version' => $bundle['version'], 'recorded' => false, 'bundle' => $bundle];
        }
        $now = $this->clock->now();
        $id = $this->tasks->start(self::TASK_TYPE, null, $trigger, $now);
        if ($id !== null) {
            $this->tasks->succeed($id, $now, count($bundle['files']), 'Configuration version '.substr($bundle['version'], 0, 12).' for the cluster.', null, $bundle['version']);
        }

        return ['version' => $bundle['version'], 'recorded' => $id !== null, 'bundle' => $bundle];
    }
}
