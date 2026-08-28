<?php

namespace App\Service;

use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Service\ResetInterface;

class AggregateConfigLoader implements ResetInterface
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
                $this->config = $this->parseLockedFile($envFile) ?? [];
                return;
            }

            // Try main aggregate.yaml
            $mainFile = $this->projectDir . '/config/aggregate.yaml';
            if (!file_exists($mainFile)) {
                return;
            }

            $data = $this->parseLockedFile($mainFile) ?? [];

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

    private function parseLockedFile(string $path): mixed
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('The application configuration could not be opened.');
        }

        try {
            if (!flock($handle, LOCK_SH)) {
                throw new \RuntimeException('The application configuration could not be locked for reading.');
            }
            $yaml = stream_get_contents($handle);
            if (!is_string($yaml)) {
                throw new \RuntimeException('The application configuration could not be read.');
            }

            return $yaml !== '' ? Yaml::parse($yaml) : null;
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
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
            throw new \RuntimeException('Application configuration is invalid.');
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
        $this->setMany([$key => $value]);
    }

    /**
     * Persist related values as one configuration update.
     *
     * The configured file is locked and all values are written together before
     * the in-memory values are changed. Writing the opened file preserves
     * symlink targets, ownership, permissions, and filesystem ACLs.
     *
     * @param array<string, mixed> $values
     */
    public function setMany(array $values): void
    {
        if ($values === []) {
            return;
        }

        foreach (array_keys($values) as $key) {
            if (!is_string($key) || $key === '') {
                throw new \InvalidArgumentException('Configuration keys must be non-empty strings.');
            }
        }

        $this->load();
        $this->assertHealthy();

        $envFile = $this->projectDir.'/config/aggregate_'.$this->environment.'.yaml';
        $configFile = is_file($envFile)
            ? $envFile
            : $this->projectDir.'/config/aggregate.yaml';
        $configExists = is_file($configFile);
        $configHandle = @fopen($configFile, $configExists ? 'r+b' : 'x+b');
        if ($configHandle === false) {
            throw new \RuntimeException('The application configuration file is not writable.');
        }

        try {
            if (!flock($configHandle, LOCK_EX)) {
                throw new \RuntimeException('The application configuration could not be locked.');
            }

            if (!rewind($configHandle)) {
                throw new \RuntimeException('The application configuration could not be read.');
            }
            $originalYaml = stream_get_contents($configHandle);
            if (!is_string($originalYaml)) {
                throw new \RuntimeException('The application configuration could not be read.');
            }

            $data = $originalYaml !== '' ? (Yaml::parse($originalYaml) ?? []) : [];
            if (!is_array($data)) {
                throw new \RuntimeException('The application configuration root must be a mapping.');
            }

            if ($configFile === $envFile) {
                foreach ($values as $key => $value) {
                    $data[$key] = $value;
                }
                $effectiveConfig = $data;
            } elseif (array_key_exists('environments', $data)) {
                if (!is_array($data['environments'])) {
                    throw new \RuntimeException('The application environments configuration must be a mapping.');
                }

                $environmentData = $data['environments'][$this->environment] ?? [];
                if (!is_array($environmentData)) {
                    throw new \RuntimeException('The active application environment configuration must be a mapping.');
                }

                foreach ($values as $key => $value) {
                    $environmentData[$key] = $value;
                }
                $data['environments'][$this->environment] = $environmentData;

                $effectiveConfig = $data;
                unset($effectiveConfig['environments']);
                $effectiveConfig = array_merge($effectiveConfig, $environmentData);
            } else {
                foreach ($values as $key => $value) {
                    $data[$key] = $value;
                }
                $effectiveConfig = $data;
            }

            $yaml = Yaml::dump($data, 4, 2);
            $writeFailure = null;
            try {
                $written = $this->replaceLockedContents($configHandle, $yaml);
            } catch (\Throwable $e) {
                $written = false;
                $writeFailure = $e;
            }
            if (!$written) {
                try {
                    $restored = $this->replaceLockedContents($configHandle, $originalYaml);
                } catch (\Throwable) {
                    $restored = false;
                }
                throw new \RuntimeException($restored
                    ? 'The application configuration could not be written.'
                    : 'The application configuration write failed and its previous contents could not be restored.',
                    previous: $writeFailure,
                );
            }

            $this->config = $effectiveConfig;
        } finally {
            @flock($configHandle, LOCK_UN);
            fclose($configHandle);
        }
    }

    public function reset(): void
    {
        $this->config = [];
        $this->loaded = false;
        $this->loadFailed = false;
    }

    /** @param resource $handle */
    private function replaceLockedContents($handle, string $contents): bool
    {
        if (!@rewind($handle) || !@ftruncate($handle, 0)) {
            return false;
        }

        $length = strlen($contents);
        $offset = 0;
        while ($offset < $length) {
            $written = @fwrite($handle, substr($contents, $offset));
            if (!is_int($written) || $written < 1) {
                return false;
            }
            $offset += $written;
        }

        if (!@fflush($handle)) {
            return false;
        }

        return !function_exists('fsync') || @fsync($handle);
    }

    /**
     * Get a config value, checking both aggregate.yaml and environment variables.
     * Environment variables take precedence.
     */
    public function getWithEnvFallback(string $key, mixed $default = null, bool $allowEmpty = false): mixed
    {
        $envKey = strtoupper($key);
        foreach ([$_ENV, $_SERVER] as $source) {
            if (!array_key_exists($envKey, $source)) {
                continue;
            }

            $envValue = $source[$envKey];
            if ($envValue !== null && ($allowEmpty || $envValue !== '')) {
                return $envValue;
            }
        }

        return $this->get($key, $default);
    }

    public function hasEnvironmentOverride(string $key, bool $allowEmpty = false): bool
    {
        $envKey = strtoupper($key);
        foreach ([$_ENV, $_SERVER] as $source) {
            if (!array_key_exists($envKey, $source)) {
                continue;
            }

            $value = $source[$envKey];
            if ($value !== null && ($allowEmpty || $value !== '')) {
                return true;
            }
        }

        return false;
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
