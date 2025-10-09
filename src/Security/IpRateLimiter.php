<?php

namespace App\Security;

use App\Service\AggregateConfigLoader;

class IpRateLimiter
{
    private ?AggregateConfigLoader $configLoader = null;

    public function __construct(
        private readonly string $storageDir,
        private readonly int $maxPerMinute = 100
    ) {
        if (!is_dir($this->storageDir)) {
            @mkdir($this->storageDir, 0777, true);
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
        $bucket = $this->getBucketPath($ip);
        $now = time();
        $window = (int) floor($now / 60);
        $data = [ 'window' => $window, 'count' => 0 ];
        if (is_file($bucket)) {
            $raw = @file_get_contents($bucket);
            if ($raw !== false) {
                $decoded = @json_decode($raw, true);
                if (is_array($decoded) && isset($decoded['window'], $decoded['count'])) {
                    $data = $decoded;
                }
            }
        }
        if ($data['window'] !== $window) {
            $data = ['window' => $window, 'count' => 0];
        }
        $data['count']++;
        @file_put_contents($bucket, json_encode($data), LOCK_EX);
        return $data['count'] <= $this->getMaxPerMinute();
    }

    private function getBucketPath(string $ip): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_\.\-]/', '_', $ip);
        return rtrim($this->storageDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $safe . '.json';
    }
}
