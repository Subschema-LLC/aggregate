<?php

namespace App\Service;

use Symfony\Component\Yaml\Yaml;

class AggregateConfigLoader
{
    private array $config = [];
    private bool $loaded = false;

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

        // Try environment-specific file first (e.g., aggregate_prod.yaml)
        $envFile = $this->projectDir . '/config/aggregate_' . $this->environment . '.yaml';
        if (file_exists($envFile)) {
            $this->config = Yaml::parseFile($envFile);
            return;
        }

        // Try main aggregate.yaml
        $mainFile = $this->projectDir . '/config/aggregate.yaml';
        if (!file_exists($mainFile)) {
            // No config file, return empty config
            return;
        }

        $data = Yaml::parseFile($mainFile);

        // Check if using environment-specific structure
        if (isset($data['environments'][$this->environment])) {
            $this->config = $data['environments'][$this->environment];
        } elseif (isset($data['environments'])) {
            // environments key exists but current env not defined
            $this->config = [];
        } else {
            // Flat structure - use root level values
            unset($data['environments']); // Remove if accidentally present
            $this->config = $data;
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
     * Get a config value, checking both aggregate.yaml and environment variables.
     * Environment variables take precedence.
     */
    public function getWithEnvFallback(string $key, mixed $default = null): mixed
    {
        // Convert snake_case to UPPER_CASE for env var
        $envKey = strtoupper($key);

        // Check environment variable first
        if (isset($_ENV[$envKey])) {
            return $_ENV[$envKey];
        }

        // Fall back to aggregate.yaml
        return $this->get($key, $default);
    }
}
