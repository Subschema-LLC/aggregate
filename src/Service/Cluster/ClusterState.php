<?php

declare(strict_types=1);

namespace App\Service\Cluster;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * What a collection or worker server knows about its configuration, in
 * var/cluster_state.json, written by app:cluster:sync every minute:
 *
 *  - version: the configuration version it has, as the administration server
 *    named it when it was pulled;
 *  - verified_at: when it last confirmed that version is the newest;
 *  - behind_since: when it first saw a newer version it could not fetch.
 *
 * /api/receive and /api/health read only this file, never the database, to
 * decide whether to pause.
 *
 * @phpstan-type State array{version: ?string, verified_at: ?int, behind_since: ?int, pulled_at: ?int, problem: ?string}
 */
class ClusterState
{
    public const FILE = 'var/cluster_state.json';

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /** @return State */
    public function read(): array
    {
        $empty = ['version' => null, 'verified_at' => null, 'behind_since' => null, 'pulled_at' => null, 'problem' => null];
        $raw = @file_get_contents($this->path(), false, null, 0, 65536);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            return $empty;
        }

        return [
            'version' => is_string($data['version'] ?? null) ? $data['version'] : null,
            'verified_at' => is_int($data['verified_at'] ?? null) ? $data['verified_at'] : null,
            'behind_since' => is_int($data['behind_since'] ?? null) ? $data['behind_since'] : null,
            'pulled_at' => is_int($data['pulled_at'] ?? null) ? $data['pulled_at'] : null,
            'problem' => is_string($data['problem'] ?? null) ? $data['problem'] : null,
        ];
    }

    /** @param State $state */
    public function write(array $state): void
    {
        $path = $this->path();
        if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0775, true) && !is_dir(dirname($path))) {
            throw new \RuntimeException('var/ could not be created.');
        }
        $temporary = $path.'.'.bin2hex(random_bytes(6)).'.tmp';
        $json = json_encode($state, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n";
        if (@file_put_contents($temporary, $json) !== strlen($json) || !@rename($temporary, $path)) {
            @unlink($temporary);
            throw new \RuntimeException(self::FILE.' could not be written.');
        }
    }

    /**
     * Why this server must not collect now, or null when it may: it has
     * never verified its configuration, has not verified it for longer than
     * the limit (its timer may have stopped), or has been behind a newer
     * version for longer than the limit.
     *
     * @param State|null $state
     */
    public function pauseReason(int $maxAgeMinutes, int $now, ?array $state = null): ?string
    {
        $state ??= $this->read();
        $limit = $maxAgeMinutes * 60;
        if ($state['verified_at'] === null) {
            return 'This server has not verified its configuration with the administration server yet (app:cluster:sync).';
        }
        if ($state['behind_since'] !== null && $now - $state['behind_since'] > $limit) {
            return sprintf('This server has been behind a newer configuration for %d minutes and cannot fetch it.', intdiv($now - $state['behind_since'], 60));
        }
        if ($now - $state['verified_at'] > $limit) {
            return sprintf('This server has not verified its configuration for %d minutes; check that app:cluster:sync runs every minute.', intdiv($now - $state['verified_at'], 60));
        }

        return null;
    }

    private function path(): string
    {
        return $this->projectDir.'/'.self::FILE;
    }
}
