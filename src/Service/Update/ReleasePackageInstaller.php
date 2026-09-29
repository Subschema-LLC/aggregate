<?php

declare(strict_types=1);

namespace App\Service\Update;

/**
 * Applies a verified release ZIP to the existing installation in place.
 *
 * The package's release-files.json inventory lists every shipped file and its
 * SHA-256. Comparing it with the installed inventory tells unchanged shipped
 * files apart from operator edits: operator paths are never touched, edited
 * configuration defaults move to their .local override, and files a newer
 * release no longer ships are removed so stale code cannot load.
 */
class ReleasePackageInstaller
{
    private const STAGED_MARKER = '.aggregate-staged.json';
    private const MAX_LISTED = 20;

    public function __construct(
        private readonly string $projectDir,
        private readonly LocalConfigOverrides $overrides,
    ) {
    }

    /**
     * Extract a package that ReleasePackageVerifier has already verified.
     *
     * @return array{version: string, files: array<string, string>}
     */
    public function stage(string $archivePath, string $stagingDir): array
    {
        $marker = $stagingDir.'/'.self::STAGED_MARKER;
        if (is_file($marker)) {
            return $this->inventory($stagingDir);
        }
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('The PHP zip extension is required to install release packages.');
        }
        $this->removeTree($stagingDir);
        if (!@mkdir($stagingDir, 0775, true) && !is_dir($stagingDir)) {
            throw new \RuntimeException('The update staging directory could not be created. Check write access to var/updates.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($archivePath, \ZipArchive::RDONLY) !== true) {
            throw new \RuntimeException('The release package could not be opened.');
        }
        $modes = [];
        try {
            for ($index = 0; $index < $zip->numFiles; ++$index) {
                $name = (string) $zip->getNameIndex($index);
                if (str_ends_with($name, '/')) {
                    continue;
                }
                $mode = 0644;
                if ($zip->getExternalAttributesIndex($index, $system, $attributes) && $system === \ZipArchive::OPSYS_UNIX) {
                    $mode = (($attributes >> 16) & 0111) !== 0 ? 0755 : 0644;
                }
                $modes[$name] = $mode;
            }
            if (!$zip->extractTo($stagingDir)) {
                throw new \RuntimeException('The release package could not be extracted. Check free disk space in var/updates.');
            }
        } finally {
            $zip->close();
        }
        $inventory = $this->inventory($stagingDir);
        $staged = iterator_to_array($this->walk($stagingDir, '', false), false);
        $staged = array_values(array_diff($staged, [UpdatePaths::INVENTORY]));
        $listed = array_keys($inventory['files']);
        sort($staged);
        sort($listed);
        if ($staged !== $listed) {
            throw new \RuntimeException('The release package contents do not match its file inventory.');
        }
        foreach ($inventory['files'] as $relative => $hash) {
            if (UpdatePaths::isProtected($relative)) {
                throw new \RuntimeException('The release package contains an operator-owned path ('.$relative.'). It will not be installed.');
            }
            if (!hash_equals($hash, (string) hash_file('sha256', $stagingDir.'/'.$relative))) {
                throw new \RuntimeException('A staged file does not match the release inventory. Download the package again.');
            }
        }
        $json = json_encode(['modes' => $modes], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (@file_put_contents($marker, $json) === false) {
            throw new \RuntimeException('The update staging directory could not be written.');
        }

        return $inventory;
    }

    /**
     * Work out every change without touching the installation.
     *
     * @return array<string, mixed>
     */
    public function plan(string $stagingDir): array
    {
        $new = $this->inventory($stagingDir);
        $modes = $this->stagedModes($stagingDir);
        $old = $this->installedInventory();
        $plan = [
            'version' => $new['version'],
            'legacy' => $old === null,
            'writes' => [],
            'overrides' => [],
            'env_append' => '',
            'env_keys' => [],
            'kept' => [],
            'replaced_modified' => [],
            'deletes' => [],
            'kept_unlisted' => [],
            'unwritable' => [],
        ];

        foreach ($new['files'] as $relative => $hash) {
            if ($relative === UpdatePaths::RELEASE_METADATA || $relative === UpdatePaths::INVENTORY) {
                continue;
            }
            $target = $this->projectDir.'/'.$relative;
            $installedHash = is_file($target) && !is_link($target) ? hash_file('sha256', $target) : null;
            $shippedHash = $old['files'][$relative] ?? null;
            $modified = $installedHash !== null && $installedHash !== ($shippedHash ?? $hash);

            if ($relative === UpdatePaths::TRUST_ANCHOR) {
                if ($installedHash !== $hash) {
                    $plan['kept'][$relative] = 'Not replaced: a release cannot change the key it is verified with. The installation keeps its own trusted key.';
                }
                continue;
            }
            if ($relative === UpdatePaths::ENVIRONMENT_DEFAULTS) {
                if ($installedHash !== null) {
                    [$plan['env_append'], $plan['env_keys']] = $this->environmentAdditions(
                        (string) file_get_contents($target),
                        (string) file_get_contents($stagingDir.'/'.$relative),
                    );
                    if ($plan['env_append'] !== '') {
                        $this->checkWritable($relative, $plan);
                    }
                }
                continue;
            }
            if (isset(UpdatePaths::CUSTOMIZABLE_CONFIG[$relative]) && $modified) {
                $edited = (string) file_get_contents($target);
                $content = $this->overrides->contentToSave($relative, $edited);
                if ($content !== null) {
                    $plan['overrides'][$relative] = $content;
                    $this->checkWritable($this->overrides->overridePath($relative), $plan);
                }
            } elseif (in_array($relative, UpdatePaths::KEEP_WHEN_MODIFIED, true) && $modified && $installedHash !== $hash) {
                $plan['kept'][$relative] = 'Kept your modified file. The release version is saved in the update backup for comparison.';
                continue;
            } elseif ($modified && $shippedHash !== null && $installedHash !== $hash) {
                $plan['replaced_modified'][] = $relative;
            }

            $mode = $modes[$relative] ?? 0644;
            $installedMode = $installedHash !== null ? (@fileperms($target) & 0777) : null;
            if ($installedHash !== $hash || ($installedMode !== null && ($installedMode & 0111) !== ($mode & 0111))) {
                $plan['writes'][] = ['path' => $relative, 'mode' => $mode];
                $this->checkWritable($relative, $plan);
            }
        }

        $delete = function (string $relative) use (&$plan): void {
            if (!in_array($relative, $plan['deletes'], true)) {
                $plan['deletes'][] = $relative;
                $this->checkWritable($relative, $plan);
            }
        };
        foreach ($old['files'] ?? [] as $relative => $hash) {
            if (isset($new['files'][$relative]) || UpdatePaths::isProtected($relative)
                || in_array($relative, [UpdatePaths::ENVIRONMENT_DEFAULTS, UpdatePaths::TRUST_ANCHOR], true)
                || isset(UpdatePaths::CUSTOMIZABLE_CONFIG[$relative])) {
                continue;
            }
            $target = $this->projectDir.'/'.$relative;
            if (!is_file($target) || is_link($target)) {
                continue;
            }
            if (UpdatePaths::isInOwnedTree($relative) || hash_file('sha256', $target) === $hash) {
                $delete($relative);
            } else {
                $plan['kept_unlisted'][] = $relative;
            }
        }
        // Stale code anywhere in a release-owned tree can still be autoloaded or
        // registered as a service, so it is removed even without an old inventory.
        foreach (UpdatePaths::OWNED_TREES as $tree) {
            if ($tree === 'var/browser' || !is_dir($this->projectDir.'/'.$tree) || is_link($this->projectDir.'/'.$tree)) {
                continue;
            }
            foreach ($this->walk($this->projectDir.'/'.$tree, $tree) as $relative) {
                if (!isset($new['files'][$relative])) {
                    $delete($relative);
                }
            }
        }
        sort($plan['deletes']);

        // Vendor first so application code never runs against older dependencies.
        usort($plan['writes'], static fn (array $a, array $b): int => [!str_starts_with($a['path'], 'vendor/'), $a['path']] <=> [!str_starts_with($b['path'], 'vendor/'), $b['path']]);
        foreach ([UpdatePaths::INVENTORY, UpdatePaths::RELEASE_METADATA] as $metadata) {
            $this->checkWritable($metadata, $plan);
        }

        return $plan;
    }

    /**
     * @param array<string, mixed> $plan
     * @param callable(string): void|null $progress
     * @return array<string, mixed> A summary for the operator
     */
    public function apply(array $plan, string $stagingDir, FileTransaction $files, ?callable $progress = null): array
    {
        if ($plan['unwritable'] !== []) {
            throw new \RuntimeException('The update cannot write: '.implode(', ', array_slice($plan['unwritable'], 0, 5)).'. Run it as the user that owns the application files.');
        }
        foreach ($plan['overrides'] as $default => $content) {
            $files->putContents($this->overrides->overridePath($default), $content, 0644);
        }
        foreach ($plan['kept'] as $relative => $reason) {
            if (in_array($relative, UpdatePaths::KEEP_WHEN_MODIFIED, true)) {
                $copy = $files->backupDirectory().'/incoming/'.$relative;
                @mkdir(dirname($copy), 0775, true);
                @copy($stagingDir.'/'.$relative, $copy);
            }
        }
        $count = 0;
        foreach ($plan['writes'] as $write) {
            $files->put($write['path'], $stagingDir.'/'.$write['path'], $write['mode']);
            if ($progress !== null && ++$count % 500 === 0) {
                $progress(sprintf('Installed %d of %d changed files.', $count, count($plan['writes'])));
            }
        }
        if ($plan['env_append'] !== '') {
            $current = (string) file_get_contents($this->projectDir.'/'.UpdatePaths::ENVIRONMENT_DEFAULTS);
            [$append] = $this->environmentAdditions($current, (string) file_get_contents($stagingDir.'/.env'));
            if ($append !== '') {
                $mode = @fileperms($this->projectDir.'/.env');
                $files->putContents(UpdatePaths::ENVIRONMENT_DEFAULTS, rtrim($current, "\n")."\n".$append, is_int($mode) ? $mode & 0777 : 0644);
            }
        }
        foreach ($plan['deletes'] as $relative) {
            $files->delete($relative);
        }
        // Written last: an interrupted update still reports the previous version.
        foreach ([UpdatePaths::INVENTORY, UpdatePaths::RELEASE_METADATA] as $metadata) {
            $files->put($metadata, $stagingDir.'/'.$metadata, 0644);
        }

        return $this->summary($plan);
    }

    /** @param array<string, mixed> $plan @return array<string, mixed> */
    public function summary(array $plan): array
    {
        return [
            'version' => $plan['version'],
            'files_written' => count($plan['writes']),
            'files_removed' => count($plan['deletes']),
            'removed_sample' => array_slice($plan['deletes'], 0, self::MAX_LISTED),
            'overrides_created' => array_values(array_map(fn (string $default): string => $this->overrides->overridePath($default), array_keys($plan['overrides']))),
            'kept' => $plan['kept'],
            'replaced_modified' => array_slice($plan['replaced_modified'], 0, self::MAX_LISTED),
            'replaced_modified_count' => count($plan['replaced_modified']),
            'kept_unlisted' => array_slice($plan['kept_unlisted'], 0, self::MAX_LISTED),
            'env_keys_added' => $plan['env_keys'],
            'legacy' => $plan['legacy'],
        ];
    }

    /** @return array{version: string, files: array<string, string>}|null */
    public function installedInventory(): ?array
    {
        if (!is_file($this->projectDir.'/'.UpdatePaths::INVENTORY)) {
            return null;
        }
        try {
            return $this->parseInventory((string) file_get_contents($this->projectDir.'/'.UpdatePaths::INVENTORY));
        } catch (\RuntimeException) {
            // A damaged installed inventory is treated like a legacy installation.
            return null;
        }
    }

    public function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }

    /** @return array{version: string, files: array<string, string>} */
    private function inventory(string $stagingDir): array
    {
        $path = $stagingDir.'/'.UpdatePaths::INVENTORY;
        if (!is_file($path)) {
            throw new \RuntimeException('This release package has no file inventory, so it predates automatic updates. Install it manually as described in docs/RELEASES.md.');
        }

        return $this->parseInventory((string) file_get_contents($path));
    }

    /** @return array{version: string, files: array<string, string>} */
    private function parseInventory(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data) || ($data['schema'] ?? null) !== 1 || !is_string($data['version'] ?? null)
            || !is_array($data['files'] ?? null) || $data['files'] === []) {
            throw new \RuntimeException('The release file inventory is invalid.');
        }
        foreach ($data['files'] as $relative => $hash) {
            if (!is_string($relative) || !FileTransaction::isSafeRelative($relative)
                || !is_string($hash) || preg_match('/^[0-9a-f]{64}$/D', $hash) !== 1) {
                throw new \RuntimeException('The release file inventory contains an invalid entry.');
            }
        }

        return ['version' => $data['version'], 'files' => $data['files']];
    }

    /** @return array<string, int> */
    private function stagedModes(string $stagingDir): array
    {
        $data = json_decode((string) @file_get_contents($stagingDir.'/'.self::STAGED_MARKER), true);

        return is_array($data['modes'] ?? null) ? $data['modes'] : [];
    }

    /**
     * Keys present in the new release defaults but absent from the installed .env.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function environmentAdditions(string $installed, string $shipped): array
    {
        $pattern = '/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=/m';
        preg_match_all($pattern, $installed, $existing);
        $known = array_flip($existing[1]);
        $lines = [];
        $keys = [];
        foreach (preg_split('/\R/', $shipped) ?: [] as $line) {
            if (preg_match($pattern, $line, $match) === 1 && !isset($known[$match[1]])) {
                $lines[] = $line;
                $keys[] = $match[1];
                $known[$match[1]] = true;
            }
        }
        if ($lines === []) {
            return ['', []];
        }

        return ["\n# Added by an application update: new release defaults. Override them in .env.local.\n".implode("\n", $lines)."\n", $keys];
    }

    /** @param array<string, mixed> $plan */
    private function checkWritable(string $relative, array &$plan): void
    {
        $target = $this->projectDir.'/'.$relative;
        $directory = dirname($target);
        while (!is_dir($directory) && $directory !== $this->projectDir && $directory !== dirname($directory)) {
            $directory = dirname($directory);
        }
        if ((file_exists($target) && !is_writable($target)) || !is_writable($directory)) {
            $plan['unwritable'][] = $relative;
        }
    }

    /** @return \Generator<string> */
    private function walk(string $directory, string $prefix, bool $skipDotFiles = true): \Generator
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                static fn (\SplFileInfo $item): bool => !$item->isLink()
                    && (!$skipDotFiles || !str_starts_with($item->getFilename(), '.')),
            ),
        );
        foreach ($items as $item) {
            if ($item->isFile()) {
                $relative = substr($item->getPathname(), strlen($directory) + 1);
                yield ltrim($prefix.'/'.str_replace('\\', '/', $relative), '/');
            }
        }
    }
}
