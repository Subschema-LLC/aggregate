<?php

declare(strict_types=1);

namespace App\Service\Update;

/**
 * Replaces installation files one at a time while keeping a restorable copy of
 * everything it changes. Each change is recorded before it happens, so an
 * interrupted update can still be rolled back, and re-running an interrupted
 * apply never overwrites the original backup copy.
 *
 * Only files are recovered. Database changes are never reversed here.
 */
final class FileTransaction
{
    private const LOG = 'changes.jsonl';

    /** @var array<string, true> */
    private array $recorded = [];
    /** @var resource|null */
    private $log = null;

    public function __construct(private readonly string $projectDir, private readonly string $backupDir)
    {
        foreach ($this->records() as $record) {
            $this->recorded[$record['op'] === 'mkdir' ? 'dir:'.$record['path'] : $record['path']] = true;
        }
    }

    public function backupDirectory(): string
    {
        return $this->backupDir;
    }

    public function put(string $relative, string $sourceFile, int $mode): void
    {
        $this->replace($relative, $mode, static function (string $temporary) use ($sourceFile): bool {
            return @copy($sourceFile, $temporary);
        });
    }

    public function putContents(string $relative, string $contents, int $mode): void
    {
        $this->replace($relative, $mode, static function (string $temporary) use ($contents): bool {
            return @file_put_contents($temporary, $contents) === strlen($contents);
        });
    }

    public function delete(string $relative): void
    {
        $target = $this->target($relative);
        if (!is_file($target)) {
            return;
        }
        $this->backup($relative, $target, 'deleted');
        if (!@unlink($target) && file_exists($target)) {
            throw new \RuntimeException('Could not remove '.$relative.'. Check file permissions.');
        }
    }

    /**
     * Restore every recorded change in reverse order. Safe to run repeatedly.
     *
     * @return array{restored: int, removed: int}
     */
    public function rollback(): array
    {
        $this->closeLog();
        $first = [];
        $directories = [];
        foreach ($this->records() as $record) {
            if ($record['op'] === 'mkdir') {
                $directories[] = $record['path'];
            } elseif (!isset($first[$record['path']])) {
                $first[$record['path']] = $record;
            }
        }
        $restored = 0;
        $removed = 0;
        foreach (array_reverse($first) as $relative => $record) {
            $target = $this->target($relative);
            if ($record['op'] === 'created') {
                if (is_file($target) || is_link($target)) {
                    if (!@unlink($target) && file_exists($target)) {
                        throw new \RuntimeException('Rollback could not remove '.$relative.'. Check file permissions and run the rollback again.');
                    }
                    ++$removed;
                }
                continue;
            }
            $copy = $this->backupDir.'/files/'.$relative;
            if (!is_file($copy)) {
                throw new \RuntimeException('The backup copy of '.$relative.' is missing. Restore it from your own backup.');
            }
            $this->ensureParent($target, false);
            $temporary = $this->temporaryPath($target);
            if (!@copy($copy, $temporary) || !@chmod($temporary, (int) $record['mode']) || !@rename($temporary, $target)) {
                @unlink($temporary);
                throw new \RuntimeException('Rollback could not restore '.$relative.'. Check file permissions and run the rollback again.');
            }
            ++$restored;
        }
        rsort($directories);
        foreach ($directories as $directory) {
            // Only directories this update created, and only once they are empty again.
            @rmdir($this->target($directory));
        }

        return ['restored' => $restored, 'removed' => $removed];
    }

    /** @return list<array{op: string, path: string, mode: int}> */
    public function records(): array
    {
        $raw = @file_get_contents($this->backupDir.'/'.self::LOG);
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $records = [];
        foreach (explode("\n", $raw) as $line) {
            if ($line === '') {
                continue;
            }
            $record = json_decode($line, true);
            // A torn final line from an interruption carries no completed change.
            if (is_array($record) && is_string($record['op'] ?? null) && is_string($record['path'] ?? null)
                && is_int($record['mode'] ?? null) && self::isSafeRelative($record['path'])) {
                $records[] = $record;
            }
        }

        return $records;
    }

    public function __destruct()
    {
        $this->closeLog();
    }

    public static function isSafeRelative(string $relative): bool
    {
        if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '\\') || str_contains($relative, "\0")) {
            return false;
        }
        foreach (explode('/', $relative) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                return false;
            }
        }

        return true;
    }

    /** @param callable(string): bool $writer */
    private function replace(string $relative, int $mode, callable $writer): void
    {
        $target = $this->target($relative);
        if (is_link($target) || is_dir($target)) {
            throw new \RuntimeException($relative.' is a symbolic link or directory in the installation. Replace it with a regular file before updating.');
        }
        if (is_file($target)) {
            $this->backup($relative, $target, 'replaced');
        } else {
            $this->ensureParent($target, true);
            $this->record('created', $relative, 0);
        }
        $temporary = $this->temporaryPath($target);
        if (!$writer($temporary) || !@chmod($temporary, $mode) || !@rename($temporary, $target)) {
            @unlink($temporary);
            throw new \RuntimeException('Could not write '.$relative.'. Check file permissions and free disk space.');
        }
    }

    private function backup(string $relative, string $target, string $op): void
    {
        if (isset($this->recorded[$relative])) {
            return;
        }
        $copy = $this->backupDir.'/files/'.$relative;
        $mode = @fileperms($target);
        $mode = is_int($mode) ? $mode & 0777 : 0644;
        if (!is_file($copy)) {
            $this->ensureDirectory(dirname($copy));
            $temporary = $copy.'.partial';
            if (!@copy($target, $temporary) || !@rename($temporary, $copy)) {
                @unlink($temporary);
                throw new \RuntimeException('Could not back up '.$relative.'. Check free disk space and write access to var/updates.');
            }
        }
        $this->record($op, $relative, $mode);
    }

    private function record(string $op, string $relative, int $mode): void
    {
        $key = $op === 'mkdir' ? 'dir:'.$relative : $relative;
        if (isset($this->recorded[$key])) {
            return;
        }
        if ($this->log === null) {
            $this->ensureDirectory($this->backupDir);
            $handle = @fopen($this->backupDir.'/'.self::LOG, 'a');
            if ($handle === false) {
                throw new \RuntimeException('The update backup log could not be opened. Check write access to var/updates.');
            }
            $this->log = $handle;
        }
        $line = json_encode(['op' => $op, 'path' => $relative, 'mode' => $mode], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
        if (@fwrite($this->log, $line) !== strlen($line) || !@fflush($this->log)) {
            throw new \RuntimeException('The update backup log could not be written. Check free disk space.');
        }
        $this->recorded[$key] = true;
    }

    private function ensureParent(string $target, bool $record): void
    {
        $directory = dirname($target);
        if (is_dir($directory)) {
            return;
        }
        $missing = [];
        for ($current = $directory; !is_dir($current) && $current !== $this->projectDir; $current = dirname($current)) {
            $missing[] = $current;
        }
        foreach (array_reverse($missing) as $path) {
            if ($record) {
                $this->record('mkdir', substr($path, strlen($this->projectDir) + 1), 0);
            }
            $this->ensureDirectory($path);
        }
    }

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !@mkdir($path, 0775, true) && !is_dir($path)) {
            throw new \RuntimeException('Could not create '.$path.'. Check write access.');
        }
    }

    private function target(string $relative): string
    {
        if (!self::isSafeRelative($relative)) {
            throw new \RuntimeException('Refusing an unsafe update path.');
        }

        return $this->projectDir.'/'.$relative;
    }

    private function temporaryPath(string $target): string
    {
        return dirname($target).'/.'.basename($target).'.aggregate-'.bin2hex(random_bytes(4)).'.tmp';
    }

    private function closeLog(): void
    {
        if ($this->log !== null) {
            fclose($this->log);
            $this->log = null;
        }
    }
}
