<?php

declare(strict_types=1);

namespace App\Service;

/** Reads package identity independently of Git; local deployment changes are never cached. */
final class InstalledRelease
{
    public function __construct(private readonly string $projectDir)
    {
    }

    public function present(): bool
    {
        $path = $this->projectDir.'/release.json';

        return file_exists($path) || is_link($path);
    }

    /** @return ?array<string, mixed> */
    public function read(): ?array
    {
        if (!$this->present()) {
            return null;
        }
        $path = $this->projectDir.'/release.json';
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException('Installed release.json is not a readable release metadata file. Restore it from the installation package.');
        }
        $contents = @file_get_contents($path, false, null, 0, ReleaseMetadata::MAX_BYTES + 1);
        if ($contents === false) {
            throw new \RuntimeException('Installed release.json could not be read. Check its file permissions.');
        }
        try {
            return ReleaseMetadata::parse($contents);
        } catch (\InvalidArgumentException $e) {
            throw new \RuntimeException('Installed release.json is invalid: '.$e->getMessage().' Restore it from the installation package.', previous: $e);
        }
    }
}
