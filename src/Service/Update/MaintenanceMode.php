<?php

declare(strict_types=1);

namespace App\Service\Update;

/**
 * Writes the marker read by config/maintenance.php. Web requests receive a 503
 * page while it is active; console commands are not affected.
 */
class MaintenanceMode
{
    /** A running update refreshes this lease at every step. */
    public const LEASE_SECONDS = 1800;

    public function __construct(private readonly string $projectDir)
    {
    }

    /** Active until disabled, the lease expires, or indefinitely when $ttl is null. */
    public function enable(string $reason, ?int $ttl = self::LEASE_SECONDS): void
    {
        $current = $this->read();
        $now = time();
        $this->write([
            'since' => is_int($current['since'] ?? null) ? $current['since'] : $now,
            'expires_at' => $ttl === null ? null : $now + $ttl,
            'reason' => $reason,
        ]);
    }

    /** Keep the site paused until an administrator resolves a failed update. */
    public function hold(string $reason): void
    {
        $this->enable($reason, null);
    }

    public function refresh(): void
    {
        $current = $this->read();
        if ($current !== null && ($current['expires_at'] ?? null) !== null) {
            $this->enable((string) ($current['reason'] ?? 'update'));
        }
    }

    public function disable(): void
    {
        $path = $this->path();
        if ((is_file($path) || is_link($path)) && !@unlink($path) && file_exists($path)) {
            throw new \RuntimeException('Maintenance mode could not be turned off. Remove var/maintenance.json as the deployment user.');
        }
    }

    /** @return array{active: bool, since: ?int, expires_at: ?int, reason: ?string}|null */
    public function status(): ?array
    {
        if (!is_file($this->path())) {
            return null;
        }
        $current = $this->read() ?? [];
        $expires = is_int($current['expires_at'] ?? null) ? $current['expires_at'] : null;

        return [
            'active' => $expires === null || $expires >= time(),
            'since' => is_int($current['since'] ?? null) ? $current['since'] : null,
            'expires_at' => $expires,
            'reason' => is_string($current['reason'] ?? null) ? $current['reason'] : null,
        ];
    }

    private function path(): string
    {
        return $this->projectDir.'/'.UpdatePaths::MAINTENANCE_FILE;
    }

    /** @return array<string, mixed>|null */
    private function read(): ?array
    {
        $raw = @file_get_contents($this->path(), false, null, 0, 65536);
        $data = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($data) ? $data : null;
    }

    /** @param array<string, mixed> $data */
    private function write(array $data): void
    {
        $directory = dirname($this->path());
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Maintenance mode could not be turned on. Check write access to var/.');
        }
        $temporary = $directory.'/.maintenance-'.bin2hex(random_bytes(6)).'.tmp';
        if (@file_put_contents($temporary, json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n") === false
            || !@rename($temporary, $this->path())) {
            @unlink($temporary);
            throw new \RuntimeException('Maintenance mode could not be turned on. Check write access to var/.');
        }
    }
}
