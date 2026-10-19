<?php

declare(strict_types=1);

namespace App\Service\Operations;

/**
 * What one administrator action changed in the configuration files: which
 * files, and which settings in them, by name only. The audit trail records it
 * with the action; values are never kept, so a secret, a password or a
 * submitted value cannot reach the audit trail this way.
 *
 * Recording is off until start(), so ordinary requests and background work
 * collect nothing; finish() returns what was recorded and turns it off again.
 * The writers (AggregateConfigLoader, WebsiteConfigManager, WebsiteScopeFiles,
 * LocalEnvironmentFile, BigQuerySecrets, BrandingLogoManager) report every
 * file they change. It is process state, like the request being handled: one
 * PHP process handles one request or command at a time.
 */
final class ConfigurationChanges
{
    /** Settings nested deeper than this are reported by their parent's name. */
    private const DEPTH = 2;
    private const MAX_NAMES = 50;

    private static bool $recording = false;
    /** @var array<string, array<string, true>> file => setting names */
    private static array $changes = [];

    public static function start(): void
    {
        self::$recording = true;
        self::$changes = [];
    }

    /**
     * What was recorded since start(), and stops recording.
     *
     * @return array<string, list<string>> file (relative to the application folder) => changed setting names, sorted
     */
    public static function finish(): array
    {
        $changes = [];
        foreach (self::$changes as $file => $names) {
            $names = array_keys($names);
            sort($names);
            $changes[$file] = $names;
        }
        ksort($changes);
        self::$recording = false;
        self::$changes = [];

        return $changes;
    }

    public static function isRecording(): bool
    {
        return self::$recording;
    }

    /**
     * A file was written. $names are the settings that changed in it, when
     * the writer knows them; a file without names is reported as changed.
     *
     * @param list<string> $names
     */
    public static function record(string $file, array $names = []): void
    {
        if (!self::$recording) {
            return;
        }
        self::$changes[$file] ??= [];
        foreach ($names as $name) {
            if (count(self::$changes[$file]) >= self::MAX_NAMES) {
                break;
            }
            self::$changes[$file][$name] = true;
        }
    }

    /**
     * A YAML mapping was replaced: records the settings whose values differ,
     * with nested mappings reported one level down ("feature_flags.updates").
     * Saving the same values again records nothing.
     *
     * @param array<mixed> $before
     * @param array<mixed> $after
     */
    public static function recordMapping(string $file, array $before, array $after): void
    {
        if (!self::$recording) {
            return;
        }
        $names = self::changedNames($before, $after);
        if ($names !== []) {
            self::record($file, $names);
        }
    }

    /**
     * The names of the settings whose values differ between two mappings.
     *
     * @param array<mixed> $before
     * @param array<mixed> $after
     *
     * @return list<string>
     */
    public static function changedNames(array $before, array $after, string $prefix = '', int $depth = 1): array
    {
        $names = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
            $name = $prefix.$key;
            $old = $before[$key] ?? null;
            $new = $after[$key] ?? null;
            if (array_key_exists($key, $before) === array_key_exists($key, $after) && self::normalized($old) === self::normalized($new)) {
                continue;
            }
            if ($depth < self::DEPTH && self::isMapping($old) && self::isMapping($new)) {
                $nested = self::changedNames($old, $new, $name.'.', $depth + 1);
                array_push($names, ...($nested === [] ? [$name] : $nested));
                continue;
            }
            $names[] = $name;
        }

        return $names;
    }

    /**
     * One line for the audit trail: "aggregate.yaml: a, b; .env.local: NAME".
     *
     * @param array<string, list<string>> $changes from finish()
     */
    public static function describe(array $changes): ?string
    {
        $parts = [];
        foreach ($changes as $file => $names) {
            $label = str_starts_with($file, 'config/') ? substr($file, 7) : $file;
            $parts[] = $names === [] ? $label : $label.': '.implode(', ', $names);
        }

        return $parts === [] ? null : 'Changed '.implode('; ', $parts);
    }

    /** The value with every mapping's keys sorted, so a reordered mapping is not a change. */
    private static function normalized(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $value = array_map(self::normalized(...), $value);
        if (!array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    private static function isMapping(mixed $value): bool
    {
        return is_array($value) && ($value === [] || !array_is_list($value));
    }
}
