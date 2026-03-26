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
            return;
        }

        $data = Yaml::parseFile($mainFile);

        // Check if using environment-specific structure
        if (isset($data['environments'][$this->environment])) {
            $this->config = $data['environments'][$this->environment];
        } elseif (isset($data['environments'])) {
            $this->config = [];
        } else {
            unset($data['environments']);
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
     * Persist a key/value into the config file and in-memory config.
     * Handles both flat and environments-nested yaml structures.
     */
    public function set(string $key, mixed $value): void
    {
        $this->load();
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

        if (isset($_ENV[$envKey])) {
            return $_ENV[$envKey];
        }

        return $this->get($key, $default);
    }
}
