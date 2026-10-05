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
        private readonly string $environment,
        private readonly string $configurationName = 'aggregate',
    ) {
        if (preg_match('~^(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+$~D', $configurationName) !== 1) {
            throw new \InvalidArgumentException('Configuration names must use safe relative path components.');
        }
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;

        try {
            // Try environment-specific file first (e.g., aggregate_prod.yaml)
            $envFile = $this->projectDir . '/config/'.$this->configurationName.'_' . $this->environment . '.yaml';
            if (file_exists($envFile)) {
                $this->config = $this->parseLockedFile($envFile);
                return;
            }

            // Try main aggregate.yaml
            $mainFile = $this->projectDir . '/config/'.$this->configurationName.'.yaml';
            if (!file_exists($mainFile)) {
                return;
            }

            $data = $this->parseLockedFile($mainFile);

            // Check if using environment-specific structure
            if (array_key_exists('environments', $data)) {
                if (!is_array($data['environments']) || ($data['environments'] !== [] && array_is_list($data['environments']))) {
                    throw new \RuntimeException('Application environments must be a mapping.');
                }
                if (array_key_exists($this->environment, $data['environments'])
                    && (!is_array($data['environments'][$this->environment])
                        || ($data['environments'][$this->environment] !== [] && array_is_list($data['environments'][$this->environment])))) {
                    throw new \RuntimeException('The active application environment must be a mapping.');
                }
            }
            if (array_key_exists($this->environment, $data['environments'] ?? [])) {
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

    private function parseLockedFile(string $path): array
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

            return $this->parseDocument($yaml);
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function parseDocument(string $yaml): array
    {
        // A missing/blank/comments-only document has defaults; an explicit
        // null or malformed collection must never silently enable defaults.
        $content = preg_replace('/^\s*(?:#.*|---|\.\.\.)\s*$/m', '', $yaml);
        if (trim((string) $content) === '') {
            return [];
        }
        $data = Yaml::parse($yaml);
        if (!is_array($data) || ($data !== [] && array_is_list($data))
            || ($data === [] && !(Yaml::parse($yaml, Yaml::PARSE_OBJECT_FOR_MAP) instanceof \stdClass))) {
            throw new \RuntimeException('The application configuration root must be a mapping.');
        }

        return $data;
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

        $this->validateUpdateKeys($values);
        $this->updateMany(static fn (array $current): array => $values);
    }

    /**
     * Derive top-level replacements from the current effective configuration.
     *
     * The callback runs after rereading the file under its exclusive lock, so
     * partial updates to a nested mapping preserve other writers' changes.
     * Return an empty array to leave the file unchanged. The callback must not
     * write configuration or try to acquire this file's lock again.
     *
     * @param callable(array<string, mixed>): array<string, mixed> $updater
     */
    public function updateMany(callable $updater): void
    {
        $this->load();
        $this->assertHealthy();

        $envFile = $this->projectDir.'/config/'.$this->configurationName.'_'.$this->environment.'.yaml';
        $configFile = is_file($envFile)
            ? $envFile
            : $this->projectDir.'/config/'.$this->configurationName.'.yaml';
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

            $data = $this->parseDocument($originalYaml);

            $nestedEnvironment = $configFile !== $envFile && array_key_exists('environments', $data);
            if ($nestedEnvironment) {
                if (!is_array($data['environments']) || ($data['environments'] !== [] && array_is_list($data['environments']))) {
                    throw new \RuntimeException('The application environments configuration must be a mapping.');
                }

                $environmentData = array_key_exists($this->environment, $data['environments']) ? $data['environments'][$this->environment] : [];
                if (!is_array($environmentData) || ($environmentData !== [] && array_is_list($environmentData))) {
                    throw new \RuntimeException('The active application environment configuration must be a mapping.');
                }

                $effectiveConfig = $data;
                unset($effectiveConfig['environments']);
                $effectiveConfig = array_merge($effectiveConfig, $environmentData);
            } else {
                $effectiveConfig = $data;
            }

            if (!$this->hasValidIngestionPrivacySettings($effectiveConfig)) {
                throw new \RuntimeException('Application configuration is invalid.');
            }
            $values = $updater($effectiveConfig);
            if (!is_array($values)) {
                throw new \InvalidArgumentException('Configuration updates must return a mapping.');
            }
            $this->validateUpdateKeys($values);
            if ($values === []) {
                return;
            }

            if ($nestedEnvironment) {
                $data['environments'][$this->environment] = array_replace($environmentData, $values);
            } else {
                $data = array_replace($data, $values);
            }
            $effectiveConfig = array_replace($effectiveConfig, $values);

            // Multi-line text, such as custom JavaScript, stays readable as a literal block.
            $yaml = Yaml::dump($data, 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
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

    private function validateUpdateKeys(array $values): void
    {
        foreach (array_keys($values) as $key) {
            if (!is_string($key) || $key === '') {
                throw new \InvalidArgumentException('Configuration keys must be non-empty strings.');
            }
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

    private function hasValidIngestionPrivacySettings(?array $config = null): bool
    {
        $config ??= $this->all();
        $enabled = $this->hasEnvironmentOverride('anonymous_tracking_enabled')
            ? $this->getWithEnvFallback('anonymous_tracking_enabled', true)
            : ($config['anonymous_tracking_enabled'] ?? true);
        if (!$this->isBooleanLike($enabled)) {
            return false;
        }

        $excludedPaths = $this->hasEnvironmentOverride('anonymous_excluded_paths')
            ? $this->getWithEnvFallback('anonymous_excluded_paths', [])
            : ($config['anonymous_excluded_paths'] ?? []);
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

        // An unrecognized profile must not silently fall back to the broader
        // standard collection; treat it like other invalid collection controls.
        $profile = $this->hasEnvironmentOverride(CollectionProfile::KEY)
            ? $this->getWithEnvFallback(CollectionProfile::KEY, CollectionProfile::STANDARD)
            : ($config[CollectionProfile::KEY] ?? CollectionProfile::STANDARD);

        return CollectionProfile::isValid($profile);
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
