<?php

namespace App\Security;

use App\Service\AggregateConfigLoader;

/**
 * Counts collection requests per client address in one-minute windows.
 *
 * Each window's bucket is named by an HMAC of the window and the address, so
 * the stored name cannot link an address across windows and the address is
 * never stored. Buckets live in APCu when it is shared by every PHP worker of
 * the server, and otherwise in files under var/rate_limit/. Both expire about
 * two minutes after their window.
 */
class IpRateLimiter
{
    /** APCu keys: this prefix and the window's bucket name. */
    public const APCU_PREFIX = 'aggregate.rate.';

    /**
     * Server APIs whose workers share one APCu memory. Under the others (CGI,
     * LiteSpeed) each process can have its own, which would split the counts.
     */
    public const APCU_SHARED_SAPIS = ['fpm-fcgi', 'apache2handler', 'frankenphp'];

    /** Seconds a bucket is kept: its own minute and the next. */
    private const BUCKET_TTL = 120;

    private ?AggregateConfigLoader $configLoader = null;
    private ?bool $apcu;
    private bool $storageDirChecked = false;

    /**
     * @param bool|null $useApcu null (the default) uses APCu when
     *                           {@see self::apcuShared()} says so; true uses
     *                           it whenever the extension is enabled, as tests
     *                           on the command line need; false never does
     */
    public function __construct(
        private readonly string $storageDir,
        private readonly string $hashSecret,
        private readonly int $maxPerMinute = 100,
        ?bool $useApcu = null,
    ) {
        $this->apcu = $useApcu === true ? self::apcuEnabled() : $useApcu;
    }

    public function setConfigLoader(AggregateConfigLoader $configLoader): void
    {
        $this->configLoader = $configLoader;
    }

    /** Whether this PHP process keeps rate-limit buckets in APCu. */
    public static function apcuShared(): bool
    {
        return in_array(PHP_SAPI, self::APCU_SHARED_SAPIS, true) && self::apcuEnabled();
    }

    private static function apcuEnabled(): bool
    {
        return function_exists('apcu_enabled') && apcu_enabled();
    }

    private function getMaxPerMinute(): int
    {
        if ($this->configLoader) {
            return (int) $this->configLoader->getWithEnvFallback('rate_limit_per_minute', $this->maxPerMinute);
        }
        return $this->maxPerMinute;
    }

    public function allow(string $ip): bool
    {
        $now = time();
        $window = intdiv($now, 60);
        // Including the one-minute window prevents the stored identifier from
        // linking the same address across rate-limit windows.
        $bucketId = hash_hmac('sha256', $window."\0".$ip, $this->hashSecret);

        if ($this->apcu ??= self::apcuShared()) {
            $count = $this->countInApcu($bucketId, $now);
            if ($count !== null) {
                return $count <= $this->getMaxPerMinute();
            }
        }

        return $this->allowWithFile($bucketId, $window, $now);
    }

    /** The bucket's new count, or null if APCu refused it (files are used). */
    private function countInApcu(string $bucketId, int $now): ?int
    {
        $success = false;
        // Creates the entry with the expiry when it does not exist yet.
        $count = apcu_inc(self::APCU_PREFIX.$bucketId, 1, $success, self::BUCKET_TTL);
        if (!$success || !is_int($count)) {
            return null;
        }

        // Files left from before APCu was enabled, or from a fallback, are
        // still removed: one worker a minute checks the directory.
        if (apcu_add(self::APCU_PREFIX.'sweep.'.hash('xxh128', $this->storageDir), 1, 60) && is_dir($this->storageDir)) {
            $this->cleanupExpiredBuckets($now);
        }

        return $count;
    }

    private function allowWithFile(string $bucketId, int $window, int $now): bool
    {
        if (!$this->storageDirChecked) {
            $this->storageDirChecked = true;
            if (!is_dir($this->storageDir)) {
                @mkdir($this->storageDir, 0700, true);
            }
        }

        $this->cleanupExpiredBuckets($now);
        $bucket = rtrim($this->storageDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$bucketId.'.json';
        $handle = @fopen($bucket, 'c+');
        if ($handle === false) {
            // Preserve availability if the optional local limiter storage is
            // temporarily unavailable. Upstream rate limiting is still advised.
            return true;
        }

        @chmod($bucket, 0600);
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);

            return true;
        }

        try {
            rewind($handle);
            $raw = stream_get_contents($handle);
            $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
            $data = is_array($decoded) ? $decoded : [];

            if (($data['window'] ?? null) !== $window) {
                $data = ['window' => $window, 'count' => 0];
            }
            $data['count'] = max(0, (int) ($data['count'] ?? 0)) + 1;

            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, json_encode($data, JSON_THROW_ON_ERROR));
            fflush($handle);

            return $data['count'] <= $this->getMaxPerMinute();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function cleanupExpiredBuckets(int $now): void
    {
        $markerPath = rtrim($this->storageDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'.cleanup';
        $marker = @fopen($markerPath, 'c+');
        if ($marker === false || !flock($marker, LOCK_EX | LOCK_NB)) {
            if (is_resource($marker)) {
                fclose($marker);
            }

            return;
        }

        try {
            @chmod($markerPath, 0600);
            $lastCleanup = (int) stream_get_contents($marker);
            if ($lastCleanup >= $now - 60) {
                return;
            }

            $files = @scandir($this->storageDir);
            if (is_array($files)) {
                foreach ($files as $file) {
                    if (!str_ends_with($file, '.json')) {
                        continue;
                    }

                    $path = $this->storageDir.DIRECTORY_SEPARATOR.$file;
                    $modifiedAt = @filemtime($path);
                    if ($modifiedAt !== false && $modifiedAt < $now - self::BUCKET_TTL) {
                        @unlink($path);
                    }
                }
            }

            rewind($marker);
            ftruncate($marker, 0);
            fwrite($marker, (string) $now);
            fflush($marker);
        } finally {
            flock($marker, LOCK_UN);
            fclose($marker);
        }
    }
}
