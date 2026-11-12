<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Yaml\Yaml;

/**
 * The application settings as the kernel needs them before it has a
 * container: the server role and the dashboard switch, both compiled into
 * the cache. They are read as AggregateConfigLoader reads every setting, so
 * a value saved by the dashboard or a command is the value the next build
 * uses, wherever the loader saved it.
 */
final class BootSettings
{
    /**
     * The effective settings, read as AggregateConfigLoader reads them:
     * aggregate_<env>.yaml when it exists, otherwise aggregate.yaml with its
     * `environments.<env>` values over the top level. A file that cannot be
     * read gives no settings, so the role is `all`.
     *
     * @return array<string, mixed>
     */
    public static function read(string $projectDir, string $environment): array
    {
        $environmentFile = $projectDir.'/config/aggregate_'.$environment.'.yaml';
        $mainFile = $projectDir.'/config/aggregate.yaml';
        try {
            if (is_file($environmentFile)) {
                $data = self::parseLocked($environmentFile);

                return is_array($data) ? $data : [];
            }
            if (!is_file($mainFile)) {
                return [];
            }
            $data = self::parseLocked($mainFile);
            if (!is_array($data)) {
                return [];
            }
            $environments = $data['environments'] ?? null;
            unset($data['environments']);
            if (is_array($environments) && is_array($environments[$environment] ?? null)) {
                $data = array_merge($data, $environments[$environment]);
            }

            return $data;
        } catch (\Throwable) {
            return [];
        }
    }

    private static function parseLocked(string $path): mixed
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

            return is_string($yaml) && trim($yaml) !== '' ? Yaml::parse($yaml) : null;
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
