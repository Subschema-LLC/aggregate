<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * Resolves the minimum required PHP version with cascading configuration:
 * 1. config/aggregate.yaml (active environment or root, allowing operator overrides)
 * 2. config/release.yaml (shipped software release baseline)
 * 3. Default fallback (8.2.0)
 *
 * Any PHP version greater than or equal to the resolved minimum is supported.
 */
class PhpRequirementResolver
{
    public const DEFAULT_MINIMUM_PHP_VERSION = '8.2.0';

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        private readonly ?AggregateConfigLoader $config = null,
    ) {
    }

    /**
     * Resolves the minimum required PHP version in normalized semantic format (e.g. "8.2.0").
     */
    public function minimumVersion(): string
    {
        // 1. Check config/aggregate.yaml via AggregateConfigLoader if available
        if ($this->config !== null) {
            try {
                $values = $this->config->all();
                if (!empty($values['minimum_php_version']) && is_string($values['minimum_php_version'])) {
                    return self::normalizeVersion($values['minimum_php_version']);
                }
            } catch (\Throwable) {
                // If config loader is not ready, continue to file checks
            }
        }

        // Direct check of config/aggregate.yaml if loader is unavailable or did not have the key
        $aggregateVersion = $this->readFromYamlFile($this->projectDir.'/config/aggregate.yaml');
        if ($aggregateVersion !== null) {
            return self::normalizeVersion($aggregateVersion);
        }

        // 2. Fall back to config/release.yaml
        $releaseVersion = $this->readFromYamlFile($this->projectDir.'/config/release.yaml');
        if ($releaseVersion !== null) {
            return self::normalizeVersion($releaseVersion);
        }

        // 3. Fall back to default
        return self::DEFAULT_MINIMUM_PHP_VERSION;
    }

    /**
     * Human-readable display version (e.g. "8.2" instead of "8.2.0").
     */
    public function displayVersion(): string
    {
        return self::formatDisplayVersion($this->minimumVersion());
    }

    /**
     * Check current runtime against the resolved minimum.
     *
     * @return array{ok: bool, current: string, minimum: string, display_minimum: string, detail: string}
     */
    public function check(?string $runtimeVersion = null): array
    {
        $current = $runtimeVersion ?? PHP_VERSION;
        $minimum = $this->minimumVersion();
        $display = $this->displayVersion();
        $ok = version_compare($current, $minimum, '>=');

        return [
            'ok' => $ok,
            'current' => $current,
            'minimum' => $minimum,
            'display_minimum' => $display,
            'detail' => $ok
                ? sprintf('PHP %s (%s, %s or newer supported)', $current, PHP_SAPI, $display)
                : sprintf('PHP %s is too old; PHP %s or newer is required', $current, $display),
        ];
    }

    public static function normalizeVersion(string $version): string
    {
        $trimmed = trim(preg_replace('/^[>=<\s^~v]+/', '', $version) ?? '');
        if ($trimmed === '' || preg_match('/^\d+(\.\d+)*$/', $trimmed) !== 1) {
            return self::DEFAULT_MINIMUM_PHP_VERSION;
        }

        $parts = explode('.', $trimmed);
        while (count($parts) < 3) {
            $parts[] = '0';
        }

        return implode('.', $parts);
    }

    public static function formatDisplayVersion(string $version): string
    {
        $parts = explode('.', $version);
        if (count($parts) >= 3 && $parts[2] === '0') {
            return $parts[0].'.'.$parts[1];
        }

        return $version;
    }

    private function readFromYamlFile(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }

        try {
            $data = Yaml::parseFile($path);
            if (!is_array($data)) {
                return null;
            }

            if (!empty($data['minimum_php_version']) && is_string($data['minimum_php_version'])) {
                return $data['minimum_php_version'];
            }

            if (!empty($data['requirements']['php']) && is_string($data['requirements']['php'])) {
                return $data['requirements']['php'];
            }

            if (!empty($data['environments']) && is_array($data['environments'])) {
                foreach (['dev', 'prod', 'test'] as $env) {
                    if (!empty($data['environments'][$env]['minimum_php_version'])
                        && is_string($data['environments'][$env]['minimum_php_version'])) {
                        return $data['environments'][$env]['minimum_php_version'];
                    }
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }
}
