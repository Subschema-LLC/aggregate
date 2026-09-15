<?php

declare(strict_types=1);

namespace App\Service;

/** Verifies a downloaded release without extracting files or changing the installation. */
class ReleasePackageVerifier
{
    private const MAX_ARCHIVE_BYTES = 536870912;
    private const MAX_EXPANDED_BYTES = 2147483648;
    private const MAX_ENTRY_BYTES = 67108864;
    private const MAX_ENTRIES = 100000;

    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly UpdateSettings $settings,
        private readonly string $projectDir,
    ) {
    }

    /** @return array<string, mixed> The authenticated release manifest. */
    public function verify(string $archivePath, string $manifestPath, string $signaturePath): array
    {
        $this->assertLocalPath($archivePath);
        $manifestBytes = $this->readBoundedFile($manifestPath, ReleaseMetadata::MAX_BYTES, 'release manifest');
        $signature = $this->readBoundedFile($signaturePath, 256, 'release signature');
        $manifest = $this->verifyManifest($manifestBytes, $signature);
        if ($manifest['branch'] !== $this->settings->branch()) {
            throw new \RuntimeException('The signed release does not match updates_branch. Verify the intended release channel before proceeding.');
        }
        $errors = ReleaseMetadata::compatibilityErrors($manifest);
        if ($errors !== []) {
            throw new \RuntimeException('This PHP runtime is incompatible with the release. '.implode(' ', $errors));
        }
        if (!is_file($archivePath) || !is_readable($archivePath)) {
            throw new \RuntimeException('The release ZIP is not a readable local file.');
        }
        $size = filesize($archivePath);
        if ($size !== $manifest['package']['size'] || $size > self::MAX_ARCHIVE_BYTES) {
            throw new \RuntimeException('The release ZIP size does not match its signed manifest or exceeds the 512 MiB limit.');
        }
        $checksum = hash_file('sha256', $archivePath);
        if (!is_string($checksum) || !hash_equals($manifest['package']['sha256'], $checksum)) {
            throw new \RuntimeException('The release ZIP checksum does not match its signed manifest.');
        }
        $this->inspectArchive($archivePath, $manifest);

        return $manifest;
    }

    /** Signature validation deliberately precedes parsing or trusting any manifest fields. */
    public function verifyManifest(string $manifestBytes, string $signatureBase64): array
    {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            throw new \RuntimeException('The PHP sodium extension is required to verify release signatures.');
        }
        if (strlen($manifestBytes) > ReleaseMetadata::MAX_BYTES || strlen($signatureBase64) > 256) {
            throw new \RuntimeException('The release manifest or signature exceeds the permitted size.');
        }
        $signature = base64_decode(trim($signatureBase64), true);
        if (!is_string($signature) || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || !sodium_crypto_sign_verify_detached($signature, $manifestBytes, $this->publicKey())) {
            throw new \RuntimeException('The release manifest signature is invalid for the configured trusted public key.');
        }

        return ReleaseMetadata::parse($manifestBytes, requirePackage: true);
    }

    private function publicKey(): string
    {
        $this->config->assertHealthy();
        $values = $this->config->all();
        if (array_key_exists('updates_signing_public_key', $values)) {
            $encoded = $values['updates_signing_public_key'];
        } else {
            $keyPath = $this->projectDir.'/config/release-signing.pub';
            $encoded = is_file($keyPath) ? $this->readBoundedFile($keyPath, 256, 'release public key') : '';
        }
        if (!is_string($encoded) || strlen($encoded) > 256) {
            throw new \RuntimeException('updates_signing_public_key must contain a base64 Ed25519 public key.');
        }
        $key = base64_decode(trim($encoded), true);
        if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new \RuntimeException('Configure a trusted Ed25519 public key in config/release-signing.pub or updates_signing_public_key before verifying packages. Obtain it independently of the downloaded package.');
        }

        return $key;
    }

    private function inspectArchive(string $path, array $manifest): void
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('The PHP zip extension is required to inspect release packages.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::RDONLY | \ZipArchive::CHECKCONS) !== true) {
            throw new \RuntimeException('The release package is not a valid ZIP archive.');
        }
        try {
            if ($zip->numFiles < 1 || $zip->numFiles > self::MAX_ENTRIES) {
                throw new \RuntimeException('The release ZIP contains an unsupported number of entries.');
            }
            $names = [];
            $exactFiles = [];
            $expandedBytes = 0;
            for ($index = 0; $index < $zip->numFiles; ++$index) {
                $entry = $zip->statIndex($index);
                if (!is_array($entry) || !is_string($entry['name'] ?? null)) {
                    throw new \RuntimeException('The release ZIP contains an unreadable entry.');
                }
                $name = $entry['name'];
                $this->validateEntryName($name);
                $canonical = strtolower(rtrim($name, '/'));
                if (isset($names[$canonical])) {
                    throw new \RuntimeException('The release ZIP contains duplicate or case-conflicting paths.');
                }
                $directory = str_ends_with($name, '/');
                $names[$canonical] = $directory ? 'directory' : 'file';
                if (!$directory) {
                    $exactFiles[$name] = true;
                }
                $size = $entry['size'] ?? -1;
                if (!is_int($size) || $size < 0 || $size > self::MAX_ENTRY_BYTES
                    || ($expandedBytes += $size) > self::MAX_EXPANDED_BYTES
                    || ($entry['encryption_method'] ?? 0) !== 0) {
                    throw new \RuntimeException('The release ZIP contains an encrypted or oversized entry.');
                }
                if (!$zip->getExternalAttributesIndex($index, $system, $attributes)) {
                    throw new \RuntimeException('The release ZIP entry attributes could not be inspected.');
                }
                $type = ($attributes >> 16) & 0170000;
                if ($system === \ZipArchive::OPSYS_UNIX && !in_array($type, [0, 0100000, 0040000], true)) {
                    throw new \RuntimeException('The release ZIP contains a symbolic link or special file.');
                }
                if ($system === \ZipArchive::OPSYS_UNIX && $type !== 0
                    && (($type === 0040000) !== $directory)) {
                    throw new \RuntimeException('The release ZIP contains inconsistent file types.');
                }
            }
            foreach ($names as $name => $type) {
                $parts = explode('/', $name);
                array_pop($parts);
                while ($parts !== []) {
                    if (($names[implode('/', $parts)] ?? null) === 'file') {
                        throw new \RuntimeException('The release ZIP contains conflicting parent file paths.');
                    }
                    array_pop($parts);
                }
            }
            foreach (['release.json', 'composer.json', 'composer.lock', 'vendor/autoload.php', 'vendor/autoload_runtime.php', 'public/index.php', 'bin/console', 'LICENSE'] as $required) {
                if (!isset($exactFiles[$required])) {
                    throw new \RuntimeException('The release ZIP is missing a required application file.');
                }
            }
            $embedded = $zip->getFromName('release.json', ReleaseMetadata::MAX_BYTES + 1);
            if (!is_string($embedded)) {
                throw new \RuntimeException('The release ZIP has no readable release.json metadata.');
            }
            $identity = ReleaseMetadata::parse($embedded);
            $expected = $manifest;
            unset($expected['package']);
            if ($identity != $expected || array_key_exists('package', $identity)) {
                throw new \RuntimeException('The embedded release identity does not match the signed manifest.');
            }
        } finally {
            $zip->close();
        }
    }

    private function validateEntryName(string $name): void
    {
        if ($name === '' || strlen($name) > 1024 || preg_match('//u', $name) !== 1
            || preg_match('/[\x00-\x1F\x7F\\\\:]/', $name) === 1 || str_starts_with($name, '/')) {
            throw new \RuntimeException('The release ZIP contains an unsafe path.');
        }
        foreach (explode('/', rtrim($name, '/')) as $part) {
            if ($part === '' || in_array($part, ['.', '..'], true) || str_ends_with($part, '.') || str_ends_with($part, ' ')
                || strcasecmp($part, '.git') === 0
                || preg_match('/^(?:con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|$)/i', $part) === 1) {
                throw new \RuntimeException('The release ZIP contains an unsafe path component.');
            }
        }
    }

    private function readBoundedFile(string $path, int $limit, string $label): string
    {
        $this->assertLocalPath($path);
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException('The '.$label.' is not a readable local file.');
        }
        $contents = @file_get_contents($path, false, null, 0, $limit + 1);
        if (!is_string($contents) || strlen($contents) > $limit) {
            throw new \RuntimeException('The '.$label.' could not be read within its permitted size.');
        }

        return $contents;
    }

    private function assertLocalPath(string $path): void
    {
        if (preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $path) === 1
            || str_starts_with($path, '//') || str_starts_with($path, '\\\\')
            || !stream_is_local($path)) {
            throw new \RuntimeException('Release verification accepts local filesystem paths only. Download the release files before verifying them.');
        }
    }
}
