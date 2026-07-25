<?php

namespace App\Security;

use App\Service\AggregateConfigLoader;

class IpRateLimiter
{
    private ?AggregateConfigLoader $configLoader = null;

    public function __construct(
        private readonly string $storageDir,
        private readonly string $hashSecret,
        private readonly int $maxPerMinute = 100,
    ) {
        if (!is_dir($this->storageDir)) {
            @mkdir($this->storageDir, 0700, true);
        }
    }

    public function setConfigLoader(AggregateConfigLoader $configLoader): void
    {
        $this->configLoader = $configLoader;
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
        $window = (int) floor($now / 60);
        $this->cleanupExpiredBuckets($now);
        $bucket = $this->getBucketPath($ip, $window);
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

    private function getBucketPath(string $ip, int $window): string
    {
        // Including the one-minute window prevents the on-disk identifier from
        // linking the same address across rate-limit windows.
        $bucketId = hash_hmac('sha256', $window."\0".$ip, $this->hashSecret);

        return rtrim($this->storageDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $bucketId . '.json';
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
                    if ($modifiedAt !== false && $modifiedAt < $now - 120) {
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
