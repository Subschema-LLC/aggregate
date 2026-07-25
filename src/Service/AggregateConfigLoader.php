<?php

namespace App\Service;

use Symfony\Component\Yaml\Yaml;

class AggregateConfigLoader
{
    private array $config = [];
    private bool $loaded = false;
    private bool $loadFailed = false;

    public function __construct(
        private readonly string $projectDir,
        private readonly string $environment
    ) {}

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;

        try {
            // Try environment-specific file first (e.g., aggregate_prod.yaml)
            $envFile = $this->projectDir . '/config/aggregate_' . $this->environment . '.yaml';
            if (file_exists($envFile)) {
                $this->config = Yaml::parseFile($envFile) ?? [];
                return;
            }

            // Try main aggregate.yaml
            $mainFile = $this->projectDir . '/config/aggregate.yaml';
            if (!file_exists($mainFile)) {
                return;
            }

            $data = Yaml::parseFile($mainFile) ?? [];

            // Check if using environment-specific structure
            if (isset($data['environments'][$this->environment])) {
                $envConfig = $data['environments'][$this->environment];
                unset($data['environments']);
                $this->config = array_merge($data, $envConfig);
            } elseif (isset($data['environments'])) {
                unset($data['environments']);
                $this->config = $data;
            } else {
                unset($data['environments']);
                $this->config = $data;
            }
        } catch (\Throwable) {
            // Consumers of privacy-sensitive settings can inspect this state
            // and fail closed without exposing parser or filesystem details.
            $this->config = [];
            $this->loadFailed = true;
        }
    }

    public function hasLoadError(): bool
    {
        $this->load();

        return $this->loadFailed || !$this->hasValidIngestionPrivacySettings();
    }

    public function assertHealthy(): void
    {
        if ($this->hasLoadError()) {
            throw new \RuntimeException('Aggregate configuration is invalid.');
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->load();
        return $this->config[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        $this->load();
        return isset($this->config[$key]);
    }

    public function all(): array
    {
        $this->load();
        return $this->config;
    }

    /**
     * Persist a key/value into the config file and in-memory config.
     * Handles both flat and environments-nested yaml structures.
     */
    public function set(string $key, mixed $value): void
    {
        $this->load();
        $this->assertHealthy();
        $this->config[$key] = $value;

        // Prefer environment-specific file if it was the source
        $envFile = $this->projectDir . '/config/aggregate_' . $this->environment . '.yaml';
        if (file_exists($envFile)) {
            $data = Yaml::parseFile($envFile) ?? [];
            $data[$key] = $value;
            file_put_contents($envFile, Yaml::dump($data, 4, 2));
            return;
        }

        $mainFile = $this->projectDir . '/config/aggregate.yaml';
        $data = file_exists($mainFile) ? (Yaml::parseFile($mainFile) ?? []) : [];

        if (isset($data['environments'][$this->environment])) {
            $data['environments'][$this->environment][$key] = $value;
        } elseif (isset($data['environments'])) {
            $data['environments'][$this->environment][$key] = $value;
        } else {
            $data[$key] = $value;
        }

        file_put_contents($mainFile, Yaml::dump($data, 4, 2));
    }

    /**
     * Get a config value, checking both aggregate.yaml and environment variables.
     * Environment variables take precedence.
     */
    public function getWithEnvFallback(string $key, mixed $default = null): mixed
    {
        $envKey = strtoupper($key);
        foreach ([$_ENV, $_SERVER] as $source) {
            if (!array_key_exists($envKey, $source)) {
                continue;
            }

            $envValue = $source[$envKey];
            if ($envValue !== null && $envValue !== '') {
                return $envValue;
            }
        }

        return $this->get($key, $default);
    }

    public function getBoolWithEnvFallback(string $key, bool $default = false): bool
    {
        $value = $this->getWithEnvFallback($key, $default);

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }

            if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }

        return $default;
    }

    public function isDashboardEnabled(): bool
    {
        return $this->getBoolWithEnvFallback('dashboard_enabled', true);
    }

    private function hasValidIngestionPrivacySettings(): bool
    {
        $enabled = $this->getWithEnvFallback('anonymous_tracking_enabled', true);
        if (!$this->isBooleanLike($enabled)) {
            return false;
        }

        $excludedPaths = $this->getWithEnvFallback('anonymous_excluded_paths', []);
        if (is_string($excludedPaths)) {
            $excludedPaths = $excludedPaths === '' ? [] : explode(',', $excludedPaths);
        }
        if (!is_array($excludedPaths)) {
            return false;
        }

        foreach ($excludedPaths as $path) {
            if (!is_string($path)) {
                return false;
            }

            $path = trim($path);
            if ($path === ''
                || strlen($path) > 512
                || !str_starts_with($path, '/')
                || str_contains($path, '?')
                || str_contains($path, '#')) {
                return false;
            }
        }

        return true;
    }

    private function isBooleanLike(mixed $value): bool
    {
        if (is_bool($value)) {
            return true;
        }
        if (is_int($value)) {
            return $value === 0 || $value === 1;
        }
        if (!is_string($value)) {
            return false;
        }

        return in_array(strtolower(trim($value)), [
            '0', '1', 'false', 'true', 'no', 'yes', 'off', 'on',
        ], true);
    }
}
