<?php

declare(strict_types=1);

namespace App\Service\Cluster;

use Symfony\Component\Yaml\Yaml;

/**
 * The configuration the administration server hands the other servers of a
 * cluster: the files collection and queue handling read, and its version, a
 * SHA-256 over every file's path and content.
 *
 * Included: config/aggregate*.yaml, websites.yaml, website-scopes.yaml and
 * website-scopes/, goals.local.yaml, the tag manager's per-site files and
 * var/branding/. Never: config/secrets/, .env files, users, or files only the
 * dashboard reads.
 *
 * A server applying a bundle writes each file beside its target and renames
 * it into place, removes the files the bundle no longer has, and keeps its
 * own server_role, cluster_server_name and administration_url, so it never
 * takes on the administration server's role and can always pull again.
 *
 * @phpstan-type BundleFile array{path: string, sha256: string, content: string}
 * @phpstan-type Bundle array{format: int, version: string, files: list<BundleFile>}
 */
final class ConfigBundle
{
    public const FORMAT = 1;
    public const MAX_FILES = 2000;
    public const MAX_BYTES = 33554432;

    /** Paths in the bundle, relative to the installation. */
    private const PATTERNS = [
        '~^config/aggregate(?:_[A-Za-z0-9-]+)?\.yaml$~D',
        '~^config/websites\.yaml$~D',
        '~^config/website-scopes\.yaml$~D',
        '~^config/website-scopes/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+\.yaml$~D',
        '~^config/goals\.local\.yaml$~D',
        '~^config/tag-manager/sites/[A-Za-z0-9_-]+\.yaml$~D',
        '~^var/branding/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+(?:\.[A-Za-z0-9]+)?$~D',
    ];

    /** Folders scanned for bundle files. */
    private const ROOTS = ['config', 'config/website-scopes', 'config/tag-manager/sites', 'var/branding'];

    /**
     * Keys a server keeps from its own configuration, whatever the bundle
     * says: its role, its name, and the administration server's address,
     * which it needs before its first pull and for every pull after.
     */
    public const LOCAL_KEYS = [ServerRole::CONFIG_KEY, ClusterSettings::SERVER_NAME_KEY, ServerRole::ADMINISTRATION_URL_KEY];

    public static function isBundlePath(string $path): bool
    {
        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every bundle file on this installation, by path.
     *
     * @return array<string, string> path => content
     */
    public static function files(string $projectDir): array
    {
        $files = [];
        $bytes = 0;
        foreach (self::ROOTS as $root) {
            $directory = $projectDir.'/'.$root;
            if (!is_dir($directory)) {
                continue;
            }
            $iterator = $root === 'config'
                ? new \ArrayIterator(array_map(static fn (string $name): \SplFileInfo => new \SplFileInfo($directory.'/'.$name), scandir($directory) ?: []))
                : new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                $path = substr($file->getPathname(), strlen($projectDir) + 1);
                if (!$file->isFile() || !self::isBundlePath($path) || isset($files[$path])) {
                    continue;
                }
                $content = @file_get_contents($file->getPathname());
                if ($content === false) {
                    throw new \RuntimeException($path.' could not be read.');
                }
                $bytes += strlen($content);
                if (count($files) >= self::MAX_FILES || $bytes > self::MAX_BYTES) {
                    throw new \RuntimeException('The configuration to share is larger than '.self::MAX_FILES.' files or '.(self::MAX_BYTES >> 20).' MB.');
                }
                $files[$path] = $content;
            }
        }
        ksort($files, \SORT_STRING);

        return $files;
    }

    /** @param array<string, string> $files path => content */
    public static function version(array $files): string
    {
        ksort($files, \SORT_STRING);
        $manifest = '';
        foreach ($files as $path => $content) {
            $manifest .= $path."\0".hash('sha256', $content)."\n";
        }

        return hash('sha256', $manifest);
    }

    /** @return Bundle */
    public static function build(string $projectDir): array
    {
        $files = self::files($projectDir);
        $entries = [];
        foreach ($files as $path => $content) {
            $entries[] = ['path' => $path, 'sha256' => hash('sha256', $content), 'content' => base64_encode($content)];
        }

        return ['format' => self::FORMAT, 'version' => self::version($files), 'files' => $entries];
    }

    /**
     * Checks a bundle as received and returns its files, refusing any path
     * outside the bundle's, any file whose content does not match its hash,
     * and a version that does not match the files.
     *
     * @return array<string, string> path => content
     */
    public static function decode(mixed $bundle): array
    {
        if (!is_array($bundle) || ($bundle['format'] ?? null) !== self::FORMAT || !is_string($bundle['version'] ?? null) || !is_array($bundle['files'] ?? null)) {
            throw new \RuntimeException('The administration server sent a configuration this release cannot read.');
        }
        if (count($bundle['files']) > self::MAX_FILES) {
            throw new \RuntimeException('The configuration has too many files.');
        }
        $files = [];
        $bytes = 0;
        foreach ($bundle['files'] as $entry) {
            $path = is_array($entry) ? ($entry['path'] ?? null) : null;
            if (!is_string($path) || !self::isBundlePath($path) || isset($files[$path])) {
                throw new \RuntimeException('The configuration names a file it may not change: '.(is_string($path) ? $path : 'unnamed').'.');
            }
            $content = is_string($entry['content'] ?? null) ? base64_decode($entry['content'], true) : false;
            if ($content === false || !hash_equals((string) ($entry['sha256'] ?? ''), hash('sha256', $content))) {
                throw new \RuntimeException('The configuration file '.$path.' arrived damaged.');
            }
            $bytes += strlen($content);
            if ($bytes > self::MAX_BYTES) {
                throw new \RuntimeException('The configuration is too large.');
            }
            $files[$path] = $content;
        }
        if (!hash_equals($bundle['version'], self::version($files))) {
            throw new \RuntimeException('The configuration does not match its version.');
        }

        return $files;
    }

    /**
     * Writes a received bundle into this installation: changed files are
     * written beside their targets and renamed into place one by one, files
     * the bundle no longer has are removed, and this server's own role, name
     * and administration address are kept in its settings.
     *
     * @param array<string, string> $files as decode() returns them
     * @return array{written: list<string>, removed: list<string>}
     */
    public static function apply(string $projectDir, string $environment, array $files): array
    {
        $local = self::localValues($projectDir, $environment);
        $files = self::withLocalValues($files, $local, $environment);
        $written = [];
        foreach ($files as $path => $content) {
            $target = $projectDir.'/'.$path;
            if (is_file($target) && @file_get_contents($target) === $content) {
                continue;
            }
            self::writeAtomically($target, $content);
            $written[] = $path;
        }
        $removed = [];
        foreach (array_keys(self::files($projectDir)) as $path) {
            if (!array_key_exists($path, $files)) {
                if (!@unlink($projectDir.'/'.$path) && is_file($projectDir.'/'.$path)) {
                    throw new \RuntimeException($path.' could not be removed.');
                }
                $removed[] = $path;
            }
        }

        return ['written' => $written, 'removed' => $removed];
    }

    /**
     * This server's own values of LOCAL_KEYS, from its settings as they are
     * read now: null for a key it does not set.
     *
     * @return array<string, mixed>
     */
    private static function localValues(string $projectDir, string $environment): array
    {
        $settings = [];
        $environmentFile = $projectDir.'/config/aggregate_'.$environment.'.yaml';
        $mainFile = $projectDir.'/config/aggregate.yaml';
        try {
            if (is_file($environmentFile)) {
                $settings = (array) Yaml::parseFile($environmentFile);
            } elseif (is_file($mainFile)) {
                $data = (array) Yaml::parseFile($mainFile);
                $environments = $data['environments'] ?? null;
                unset($data['environments']);
                $settings = is_array($environments) && is_array($environments[$environment] ?? null) ? array_merge($data, $environments[$environment]) : $data;
            }
        } catch (\Throwable) {
            $settings = [];
        }
        $values = [];
        foreach (self::LOCAL_KEYS as $key) {
            $values[$key] = $settings[$key] ?? null;
        }

        return $values;
    }

    /**
     * The bundle's settings files with LOCAL_KEYS removed everywhere and this
     * server's values set where the active environment reads them.
     *
     * @param array<string, string> $files
     * @param array<string, mixed> $local
     * @return array<string, string>
     */
    private static function withLocalValues(array $files, array $local, string $environment): array
    {
        $environmentPath = 'config/aggregate_'.$environment.'.yaml';
        $effective = isset($files[$environmentPath]) ? $environmentPath : 'config/aggregate.yaml';
        foreach ($files as $path => $content) {
            if (preg_match('~^config/aggregate(?:_[A-Za-z0-9-]+)?\.yaml$~D', $path) !== 1) {
                continue;
            }
            $hasKey = false;
            foreach (self::LOCAL_KEYS as $key) {
                $hasKey = $hasKey || preg_match('/^\s*'.preg_quote($key, '/').'\s*:/m', $content) === 1;
            }
            $setsValue = $path === $effective && array_filter($local, static fn ($value): bool => $value !== null) !== [];
            if (!$hasKey && !$setsValue) {
                continue;
            }
            $data = trim($content) === '' ? [] : Yaml::parse($content);
            if (!is_array($data)) {
                continue;
            }
            foreach (self::LOCAL_KEYS as $key) {
                unset($data[$key]);
                foreach (is_array($data['environments'] ?? null) ? array_keys($data['environments']) : [] as $name) {
                    if (is_array($data['environments'][$name])) {
                        unset($data['environments'][$name][$key]);
                    }
                }
            }
            if ($path === $effective) {
                foreach ($local as $key => $value) {
                    if ($value === null) {
                        continue;
                    }
                    if ($path === 'config/aggregate.yaml' && is_array($data['environments'][$environment] ?? null)) {
                        $data['environments'][$environment][$key] = $value;
                    } else {
                        $data[$key] = $value;
                    }
                }
            }
            $files[$path] = Yaml::dump($data, 6, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
        }
        if (!isset($files[$effective]) && array_filter($local, static fn ($value): bool => $value !== null) !== []) {
            $files[$effective] = Yaml::dump(array_filter($local, static fn ($value): bool => $value !== null), 2, 2);
        }

        return $files;
    }

    private static function writeAtomically(string $target, string $content): void
    {
        $directory = dirname($target);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('The folder '.$directory.' could not be created.');
        }
        $temporary = $directory.'/.'.basename($target).'.cluster-'.bin2hex(random_bytes(6));
        try {
            if (@file_put_contents($temporary, $content, LOCK_EX) !== strlen($content)) {
                throw new \RuntimeException($target.' could not be written.');
            }
            $mode = is_file($target) ? (fileperms($target) & 0777) : 0664;
            @chmod($temporary, $mode);
            if (!@rename($temporary, $target)) {
                throw new \RuntimeException($target.' could not be replaced.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }
}
