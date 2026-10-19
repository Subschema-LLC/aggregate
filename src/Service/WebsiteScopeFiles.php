<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Operations\ConfigurationChanges;
use Symfony\Component\Yaml\Yaml;

/**
 * The files behind website groups: config/website-scopes.yaml and the setting
 * files under config/website-scopes/. Reads take a shared lock and are capped
 * in size; writes take an exclusive lock. Parsed YAML is cached by content.
 * WebsiteScopeCatalog decides what the files mean.
 */
final class WebsiteScopeFiles
{
    private const MAX_BYTES = 262144;

    public function __construct(
        private readonly string $projectDir,
        private readonly ParsedYamlCache $parsedYaml,
    ) {
    }

    public function catalogPath(): string
    {
        return $this->projectDir.'/config/'.WebsiteScopeCatalog::FILE;
    }

    public function overlayPath(string $kind, string $id): string
    {
        return $this->projectDir.'/config/'.WebsiteScopeCatalog::DIRECTORY.'/'.$kind.'/'.$id.'.yaml';
    }

    /** Group ids are slugs; website ids are the 24-character file id of a token. */
    public function validOverlayId(string $kind, string $id): bool
    {
        return match ($kind) {
            'groups' => preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/D', $id) === 1,
            'websites' => preg_match('/^[a-f0-9]{24}$/D', $id) === 1,
            default => false,
        };
    }

    /**
     * @return array{ok: bool, values: array<string, mixed>}
     */
    public function readOverlay(string $kind, string $id): array
    {
        if (!$this->validOverlayId($kind, $id)) {
            return ['ok' => false, 'values' => []];
        }
        $path = $this->overlayPath($kind, $id);
        if (!is_file($path)) {
            return ['ok' => true, 'values' => []];
        }
        try {
            $data = $this->read($path, 'scope-'.$kind.'-'.$id);
        } catch (\Throwable) {
            return ['ok' => false, 'values' => []];
        }

        return ['ok' => true, 'values' => $data];
    }

    /**
     * Writes a group's or website's settings, or removes its file when none
     * are left. The caller has validated the values.
     *
     * @param array<string, mixed> $values
     */
    public function writeOverlay(string $kind, string $id, array $values): void
    {
        $path = $this->overlayPath($kind, $id);
        if ($values === []) {
            if (is_file($path) && !@unlink($path)) {
                throw new \RuntimeException('The website or group setting file could not be removed.');
            }

            return;
        }
        $this->write($path, Yaml::dump($values, 6, 2));
    }

    /**
     * A YAML mapping read under a shared lock, parsed once per content and
     * cached under $cacheKey; an empty file is an empty mapping.
     *
     * @return array<string, mixed>
     */
    public function read(string $path, string $cacheKey): array
    {
        if (is_file($path) && filesize($path) > self::MAX_BYTES) {
            throw new \RuntimeException('The website group configuration is too large.');
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('The website group configuration could not be opened.');
        }
        try {
            if (!flock($handle, LOCK_SH)) {
                throw new \RuntimeException('The website group configuration could not be locked.');
            }
            $yaml = stream_get_contents($handle);
            if (!is_string($yaml)) {
                throw new \RuntimeException('The website group configuration could not be read.');
            }
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
        $parsed = $this->parsedYaml->remember($cacheKey, $yaml, static function (string $document): array {
            $content = preg_replace('/^\s*(?:#.*|---|\.\.\.)\s*$/m', '', $document);
            if (trim((string) $content) === '') {
                return [];
            }
            $data = Yaml::parse($document);
            if (!is_array($data) || ($data !== [] && array_is_list($data))) {
                throw new \RuntimeException('The website group configuration must be a mapping.');
            }

            return $data;
        });
        if ($parsed !== [] && array_is_list($parsed)) {
            throw new \RuntimeException('The website group configuration must be a mapping.');
        }

        return $parsed;
    }

    /** Replaces a file's content under an exclusive lock, creating its directory if needed. */
    public function write(string $path, string $yaml): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('The website group configuration directory could not be created.');
        }
        $handle = @fopen($path, is_file($path) ? 'c+b' : 'x+b');
        if ($handle === false) {
            $handle = @fopen($path, 'c+b');
        }
        if ($handle === false) {
            throw new \RuntimeException('The website group configuration file is not writable.');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('The website group configuration could not be locked.');
            }
            $before = ConfigurationChanges::isRecording() ? self::parsedOrEmpty(stream_get_contents($handle)) : [];
            if (!ftruncate($handle, 0) || !rewind($handle) || fwrite($handle, $yaml) === false || !fflush($handle)) {
                throw new \RuntimeException('The website group configuration could not be saved.');
            }
            if (ConfigurationChanges::isRecording()) {
                $relative = str_starts_with($path, $this->projectDir.'/') ? substr($path, strlen($this->projectDir) + 1) : basename($path);
                ConfigurationChanges::recordMapping($relative, $before, self::parsedOrEmpty($yaml));
            }
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @return array<mixed> the mapping in a YAML document, or [] when there is none */
    private static function parsedOrEmpty(string|false $yaml): array
    {
        if (!is_string($yaml) || trim($yaml) === '') {
            return [];
        }
        try {
            $parsed = Yaml::parse($yaml);
        } catch (\Throwable) {
            return [];
        }

        return is_array($parsed) ? $parsed : [];
    }

    /**
     * Names, sizes and modification times of the catalog and the overlay
     * files, not their contents, and the newest of those times. A changed,
     * added or removed file changes the fingerprint.
     *
     * @return array{0: string, 1: int}
     */
    public function fingerprint(): array
    {
        $paths = [$this->catalogPath()];
        foreach (['groups', 'websites'] as $kind) {
            $directory = $this->projectDir.'/config/'.WebsiteScopeCatalog::DIRECTORY.'/'.$kind;
            if (!is_dir($directory)) {
                continue;
            }
            foreach (scandir($directory) ?: [] as $file) {
                if (str_ends_with($file, '.yaml')) {
                    $paths[] = $directory.'/'.$file;
                }
            }
        }
        $files = [];
        $newest = 0;
        foreach ($paths as $path) {
            $modified = @filemtime($path);
            $size = @filesize($path);
            if (is_int($modified)) {
                $newest = max($newest, $modified);
            }
            $files[] = substr($path, strlen($this->projectDir)).':'
                .(is_int($modified) ? $modified : 'missing').':'
                .(is_int($size) ? $size : 'missing');
        }
        sort($files);

        return [implode("\n", $files), $newest];
    }

    /**
     * The ids of the website setting files present, in directory order. They
     * are file names, not yet checked; readOverlay() checks them.
     *
     * @return list<string>
     */
    public function websiteOverlayIds(): array
    {
        $directory = $this->projectDir.'/config/'.WebsiteScopeCatalog::DIRECTORY.'/websites';
        if (!is_dir($directory)) {
            return [];
        }
        $ids = [];
        foreach (scandir($directory) ?: [] as $file) {
            if (str_ends_with($file, '.yaml')) {
                $ids[] = substr($file, 0, -5);
            }
        }

        return $ids;
    }
}
