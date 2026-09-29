<?php

declare(strict_types=1);

namespace App\Service;

/** The versioned contract shared by installed ZIPs and their signed release manifests. */
final class ReleaseMetadata
{
    public const MAX_BYTES = 65536;

    /**
     * Parsing validates structure only. A manifest is trusted only after its detached
     * signature has been verified with a separately configured release public key.
     *
     * @return array{schema: int, version: string, repository: string, branch: string, commit: string, built_at: string,
     *     requirements: array{php: string, extensions: list<string>}, package?: array{filename: string, sha256: string, size: int}}
     */
    /**
     * @param ?string $repository The repository the metadata must name, or null to
     *        accept any GitHub owner/name (an installation's own identity).
     */
    public static function parse(string $json, bool $requirePackage = false, ?string $repository = ApplicationUpdateService::REPOSITORY): array
    {
        if (strlen($json) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Release metadata exceeds the permitted size.');
        }
        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('Release metadata must contain valid JSON.', previous: $e);
        }
        if (!is_array($data) || array_is_list($data)
            || ($data['schema'] ?? null) !== 1
            || array_diff(array_keys($data), ['schema', 'version', 'repository', 'branch', 'commit', 'built_at', 'requirements', 'package']) !== []) {
            throw new \InvalidArgumentException('Release metadata has an unsupported schema.');
        }
        if (!self::isVersion($data['version'] ?? null)) {
            throw new \InvalidArgumentException('Release metadata must identify a stable version in YYYY.MM.NN format.');
        }
        if ($repository === null) {
            try {
                UpdateSettings::validateRepository($data['repository'] ?? null);
            } catch (\InvalidArgumentException $e) {
                throw new \InvalidArgumentException('Release metadata contains an invalid repository.', previous: $e);
            }
        } elseif (!is_string($data['repository'] ?? null) || strcasecmp($data['repository'], $repository) !== 0) {
            throw new \InvalidArgumentException('Release metadata does not identify the configured update repository ('.$repository.').');
        }
        try {
            UpdateSettings::validateBranch($data['branch'] ?? null);
        } catch (\InvalidArgumentException $e) {
            throw new \InvalidArgumentException('Release metadata contains an invalid source branch.', previous: $e);
        }
        if (!is_string($data['commit'] ?? null) || preg_match('/^(?:[0-9a-f]{40}|[0-9a-f]{64})$/D', $data['commit']) !== 1) {
            throw new \InvalidArgumentException('Release metadata contains an invalid source commit.');
        }
        if (!is_string($data['built_at'] ?? null)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|\+00:00)$/D', $data['built_at']) !== 1) {
            throw new \InvalidArgumentException('Release metadata must contain a UTC build timestamp.');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $data['built_at']);
        $dateErrors = \DateTimeImmutable::getLastErrors();
        if ($date === false || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))) {
            throw new \InvalidArgumentException('Release metadata contains an invalid build timestamp.');
        }

        $requirements = $data['requirements'] ?? null;
        if (!is_array($requirements)
            || array_diff(array_keys($requirements), ['php', 'extensions']) !== []
            || !is_string($requirements['php'] ?? null)
            || preg_match('/^>=(?:[1-9]\d?)\.(?:0|[1-9]\d?)(?:\.(?:0|[1-9]\d?))?$/D', $requirements['php']) !== 1
            || !is_array($requirements['extensions'] ?? null) || !array_is_list($requirements['extensions'])
            || count($requirements['extensions']) > 100) {
            throw new \InvalidArgumentException('Release metadata must declare a PHP minimum and a list of required extensions.');
        }
        foreach ($requirements['extensions'] as $extension) {
            if (!is_string($extension) || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $extension) !== 1) {
                throw new \InvalidArgumentException('Release metadata contains an invalid PHP extension requirement.');
            }
        }
        if (count(array_unique($requirements['extensions'])) !== count($requirements['extensions'])) {
            throw new \InvalidArgumentException('Release metadata contains duplicate PHP extension requirements.');
        }

        if ($requirePackage || array_key_exists('package', $data)) {
            $package = $data['package'] ?? null;
            if (!is_array($package) || array_diff(array_keys($package), ['filename', 'sha256', 'size']) !== []
                || ($package['filename'] ?? null) !== 'aggregate-'.$data['version'].'.zip'
                || !is_string($package['sha256'] ?? null) || preg_match('/^[0-9a-f]{64}$/D', $package['sha256']) !== 1
                || !is_int($package['size'] ?? null) || $package['size'] <= 0) {
                throw new \InvalidArgumentException('Release metadata must contain the expected ZIP filename, SHA-256 checksum, and positive byte size.');
            }
        }

        return $data;
    }

    /**
     * Releases use calendar versions YYYY.MM.NN, where NN numbers that month's
     * releases from 01 (for example 2026.09.01). Plain X.Y.Z versions from
     * earlier packages remain readable so those installations can update;
     * version_compare() orders both, and every calendar version is newer.
     */
    public static function isVersion(mixed $version): bool
    {
        return is_string($version) && (self::isCalendarVersion($version)
            || preg_match('/^(?:0|[1-9]\d{0,8})\.(?:0|[1-9]\d{0,8})\.(?:0|[1-9]\d{0,8})$/D', $version) === 1);
    }

    public static function isCalendarVersion(mixed $version): bool
    {
        return is_string($version) && preg_match('/^20\d{2}\.(?:0[1-9]|1[0-2])\.(?:0[1-9]|[1-9]\d)$/D', $version) === 1;
    }

    /** @param array{requirements: array{php: string, extensions: list<string>}} $metadata
     *  @return list<string>
     */
    public static function compatibilityErrors(array $metadata): array
    {
        $errors = [];
        if (version_compare(PHP_VERSION, substr($metadata['requirements']['php'], 2), '<')) {
            $errors[] = 'Requires PHP '.$metadata['requirements']['php'].'; this runtime is '.PHP_VERSION.'.';
        }
        foreach ($metadata['requirements']['extensions'] as $extension) {
            if (!extension_loaded($extension)) {
                $errors[] = 'Requires the PHP '.$extension.' extension.';
            }
        }

        return $errors;
    }
}
