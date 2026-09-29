<?php

declare(strict_types=1);

namespace App\Service\Update;

/**
 * Durable progress of the most recent update in var/updates/state.json, plus the
 * exclusive lock that prevents concurrent updates. The format is versioned so a
 * newer release can resume an update started by the release it replaces.
 */
class UpdateJournal
{
    public const SCHEMA = 1;
    private const MAX_LOG = 300;

    /** @var resource|null */
    private $lock = null;

    public function __construct(private readonly string $projectDir)
    {
    }

    public function directory(): string
    {
        return $this->projectDir.'/'.UpdatePaths::WORK_DIRECTORY;
    }

    public function ensureDirectory(string $relative = ''): string
    {
        $path = rtrim($this->directory().'/'.$relative, '/');
        if (!is_dir($path) && !@mkdir($path, 0775, true) && !is_dir($path)) {
            throw new \RuntimeException('The update work directory could not be created. Check write access to var/updates.');
        }

        return $path;
    }

    /** Take the update lock or fail immediately when another update holds it. */
    public function acquire(): void
    {
        if ($this->lock !== null) {
            return;
        }
        $this->ensureDirectory();
        $handle = @fopen($this->directory().'/update.lock', 'c');
        if ($handle === false) {
            throw new \RuntimeException('The update lock could not be opened. Run the update as the deployment user with write access to var/updates.');
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new \RuntimeException('Another application update is running. Wait for it to finish, then check php bin/console app:updates:apply --status.');
        }
        $this->lock = $handle;
    }

    public function release(): void
    {
        if ($this->lock !== null) {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
            $this->lock = null;
        }
    }

    public function isLocked(): bool
    {
        if ($this->lock !== null) {
            return true;
        }
        $handle = @fopen($this->directory().'/update.lock', 'r');
        if ($handle === false) {
            return false;
        }
        $free = flock($handle, LOCK_SH | LOCK_NB);
        if ($free) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);

        return !$free;
    }

    /** @return array<string, mixed>|null */
    public function read(): ?array
    {
        $raw = @file_get_contents($this->directory().'/state.json', false, null, 0, 4194304);
        if (!is_string($raw)) {
            return null;
        }
        $state = json_decode($raw, true);
        if (!is_array($state) || ($state['schema'] ?? null) !== self::SCHEMA) {
            return null;
        }

        return $state;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public function write(array $state): array
    {
        $state['schema'] = self::SCHEMA;
        $state['updated_at'] = time();
        if (count($state['log'] ?? []) > self::MAX_LOG) {
            $state['log'] = array_slice($state['log'], -self::MAX_LOG);
        }
        $directory = $this->ensureDirectory();
        $temporary = $directory.'/.state-'.bin2hex(random_bytes(6)).'.tmp';
        $json = json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (@file_put_contents($temporary, $json."\n") === false || !@rename($temporary, $directory.'/state.json')) {
            @unlink($temporary);
            throw new \RuntimeException('Update progress could not be saved. Check write access to var/updates.');
        }

        return $state;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public function log(array $state, string $message, string $level = 'info'): array
    {
        $state['log'][] = ['at' => time(), 'level' => $level, 'message' => $message];

        return $this->write($state);
    }
}
