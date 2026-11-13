<?php

declare(strict_types=1);

namespace App\Tools\ArchCheck;

/**
 * The full FIDES rules, for code in the new zones under src/: Entities,
 * Features, Integrations, Delivery and Shared. Code outside them is held by
 * the Ratchet instead. See docs/FIDES-MIGRATION.md.
 */
final class ZoneRules
{
    public const ZONES = ['Entities', 'Features', 'Integrations', 'Delivery', 'Shared'];
    private const CORE = ['Entities', 'Features', 'Shared'];
    private const MAX_LINES = 200;
    private const MAX_DEPTH = 3;
    /** Packages the core may not import: they reach the database, files, network or environment. */
    private const IO_PACKAGES = [
        'Doctrine\\', 'Psr\\Http\\', 'Twig\\', 'Symfony\\Component\\HttpClient\\',
        'Symfony\\Contracts\\HttpClient\\', 'Symfony\\Component\\HttpFoundation\\',
        'Symfony\\Component\\Filesystem\\', 'Symfony\\Component\\Finder\\',
        'Symfony\\Component\\Mailer\\', 'Symfony\\Component\\Process\\', 'Symfony\\Component\\Yaml\\',
        'Symfony\\Component\\Dotenv\\', 'Symfony\\Component\\Console\\', 'Symfony\\Component\\Cache\\',
    ];

    /** The zone a file belongs to, or null for code that has not moved yet. */
    public static function zoneOf(string $path): ?string
    {
        $parts = explode('/', $path);

        return ($parts[0] ?? '') === 'src' && in_array($parts[1] ?? '', self::ZONES, true) ? $parts[1] : null;
    }

    /** @return list<string> */
    public function check(SourceFile $file): array
    {
        $zone = self::zoneOf($file->path);
        if ($zone === null) {
            return [];
        }
        $parts = explode('/', $file->path);
        $owner = count($parts) > 3 ? $parts[2] : '';
        $problems = [];
        if (count($parts) - 1 > self::MAX_DEPTH) {
            $problems[] = $file->path.': deeper than zone/owner/file';
        }
        if ($file->lineCount > self::MAX_LINES) {
            $problems[] = sprintf('%s: %d lines (max %d); a concept is hiding in it', $file->path, $file->lineCount, self::MAX_LINES);
        }
        foreach ($file->longLines as $line => $length) {
            $problems[] = sprintf('%s:%d: %d characters (max 100)', $file->path, $line, $length);
        }
        foreach ($file->classes as $class) {
            $problems = [...$problems, ...$this->checkClass($file->path, $zone, $class)];
        }
        foreach ($file->references as $reference) {
            $reason = $this->importProblem($zone, $owner, $reference['name']);
            if ($reason !== null) {
                $problems[] = sprintf('%s:%d: uses %s, but %s', $file->path, $reference['line'], $reference['name'], $reason);
            }
        }
        if (in_array($zone, self::CORE, true)) {
            foreach ($file->outside as $outside) {
                $problems[] = sprintf('%s:%d: %s; the core states what it needs as a contract', $file->path, $outside['line'], $outside['what']);
            }
        }

        return array_values(array_unique($problems));
    }

    /**
     * @param array{name: string, kind: string, abstract: bool, final: bool, extends: ?string, entity: bool, line: int} $class
     *
     * @return list<string>
     */
    private function checkClass(string $path, string $zone, array $class): array
    {
        $where = sprintf('%s:%d: %s', $path, $class['line'], $class['name']);
        $problems = [];
        if ($class['extends'] !== null && !self::isBuiltInThrowable($class['extends'])) {
            $problems[] = $where.' extends '.$class['extends'].'; compose instead';
        }
        if ($class['abstract']) {
            $problems[] = $where.' is abstract; compose instead';
        }
        $doctrineRecord = $class['entity'] && $zone === 'Integrations';
        if ($class['kind'] === 'class' && !$class['abstract'] && !$class['final'] && !$doctrineRecord) {
            $problems[] = $where.' is not final';
        }

        return $problems;
    }

    private function importProblem(string $zone, string $owner, string $name): ?string
    {
        if (!str_starts_with($name, 'App\\')) {
            $core = in_array($zone, self::CORE, true);
            foreach (self::IO_PACKAGES as $package) {
                if ($core && str_starts_with($name, $package)) {
                    return strtolower($zone).' must not touch the outside world';
                }
            }

            return null;
        }
        $parts = explode('\\', $name);
        $target = $parts[1] ?? '';
        $targetOwner = $parts[2] ?? '';
        $own = $target === $zone && ($zone === 'Shared' || $zone === 'Delivery' || $targetOwner === $owner);
        $legacy = !in_array($target, self::ZONES, true);
        $allowed = match ($zone) {
            'Shared' => $target === 'Shared',
            'Entities' => $own,
            'Features' => in_array($target, ['Entities', 'Shared'], true) || $own,
            'Integrations' => in_array($target, ['Entities', 'Shared'], true) || $own || $legacy,
            default => in_array($target, ['Features', 'Shared', 'Delivery'], true) || $legacy,
        };
        if ($allowed) {
            return null;
        }

        return $legacy
            ? strtolower($zone).' may not use code that has not moved to a zone; wrap it in an integration'
            : sprintf('%s%s may not use %s', strtolower($zone), $owner === '' ? '' : '/'.$owner, $target);
    }

    private static function isBuiltInThrowable(string $class): bool
    {
        if (!class_exists($class, false)) {
            return false;
        }
        $reflection = new \ReflectionClass($class);

        return $reflection->isInternal() && $reflection->implementsInterface(\Throwable::class);
    }
}
