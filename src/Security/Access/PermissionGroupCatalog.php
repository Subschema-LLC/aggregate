<?php

declare(strict_types=1);

namespace App\Security\Access;

use App\Service\ParsedYamlCache;
use App\Service\WebsiteScopeFiles;
use Symfony\Component\Yaml\Yaml;

/**
 * Permission groups (roles): named sets of website and page grants that
 * administrators assign to standard users, in config/permission-groups.yaml.
 *
 *     permission_groups:
 *         client-editors:
 *             name: 'Client editors'
 *             description: 'Edit tags and consent for the Brand A websites.'
 *             websites: { groups: [brand-a] }
 *             pages: { websites: view, tag_manager: edit, consent_manager: edit }
 *
 * The Users page, `app:permission-groups` and hand edits share this file and
 * its validation. An invalid file grants nothing until it is corrected, and
 * the dashboard refuses to save over it.
 */
final class PermissionGroupCatalog
{
    public const FILE = 'permission-groups.yaml';
    public const MAX_GROUPS = 100;
    public const MAX_NAME = 80;
    public const MAX_DESCRIPTION = 300;

    private const ID = '/^[a-z0-9][a-z0-9-]{0,62}$/D';

    private readonly WebsiteScopeFiles $files;

    /** @var array<string, array{name: string, description: string, websites: array{all: bool, groups: list<string>, sites: list<string>}, pages: array<string, string>}>|null */
    private ?array $groups = null;
    private ?string $loadError = null;

    public function __construct(
        private readonly string $projectDir,
        string $environment = 'prod',
        ?ParsedYamlCache $parsedYaml = null,
    ) {
        // The same locked, size-capped YAML reads and audited writes as website groups.
        $this->files = new WebsiteScopeFiles($projectDir, $parsedYaml ?? ParsedYamlCache::forProject($projectDir, $environment));
    }

    public function path(): string
    {
        return $this->projectDir.'/config/'.self::FILE;
    }

    public function hasLoadError(): bool
    {
        $this->load();

        return $this->loadError !== null;
    }

    public function loadError(): ?string
    {
        $this->load();

        return $this->loadError;
    }

    /** @return array<string, array{name: string, description: string, websites: array{all: bool, groups: list<string>, sites: list<string>}, pages: array<string, string>}> */
    public function groups(): array
    {
        $this->load();

        return $this->groups ?? [];
    }

    /** @return array{name: string, description: string, websites: array{all: bool, groups: list<string>, sites: list<string>}, pages: array<string, string>}|null */
    public function group(string $id): ?array
    {
        return $this->groups()[$id] ?? null;
    }

    public function has(string $id): bool
    {
        return $this->group($id) !== null;
    }

    /** Rereads the file on next use. */
    public function reset(): void
    {
        $this->groups = null;
        $this->loadError = null;
    }

    /** @param array<string, mixed> $grant an AccessGrant document */
    public function create(string $name, string $description, array $grant): string
    {
        $groups = $this->writable();
        if (count($groups) >= self::MAX_GROUPS) {
            throw new \InvalidArgumentException('At most '.self::MAX_GROUPS.' permission groups can be saved.');
        }
        $name = $this->name($name);
        $this->assertUniqueName($groups, $name, null);
        $id = $this->uniqueId($groups, $name);
        $groups[$id] = ['name' => $name, 'description' => $this->description($description)] + AccessGrant::normalize($grant);
        $this->write($groups);

        return $id;
    }

    /** @param array<string, mixed> $grant an AccessGrant document */
    public function update(string $id, string $name, string $description, array $grant): void
    {
        $groups = $this->writable();
        if (!isset($groups[$id])) {
            throw new \InvalidArgumentException('That permission group does not exist.');
        }
        $name = $this->name($name);
        $this->assertUniqueName($groups, $name, $id);
        $groups[$id] = ['name' => $name, 'description' => $this->description($description)] + AccessGrant::normalize($grant);
        $this->write($groups);
    }

    public function delete(string $id): void
    {
        $groups = $this->writable();
        if (!isset($groups[$id])) {
            throw new \InvalidArgumentException('That permission group does not exist.');
        }
        unset($groups[$id]);
        $this->write($groups);
    }

    private function load(): void
    {
        if ($this->groups !== null || $this->loadError !== null) {
            return;
        }
        $this->groups = [];
        if (!is_file($this->path())) {
            return;
        }
        try {
            $data = $this->files->read($this->path(), 'permission-groups');
            $this->groups = $this->parse($data);
        } catch (\Throwable $error) {
            $this->groups = null;
            $this->loadError = $error instanceof \InvalidArgumentException
                ? $error->getMessage()
                : 'config/'.self::FILE.' could not be read as YAML.';
        }
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, array{name: string, description: string, websites: array{all: bool, groups: list<string>, sites: list<string>}, pages: array<string, string>}>
     */
    private function parse(array $data): array
    {
        if ($data === []) {
            return [];
        }
        if (array_diff(array_keys($data), ['permission_groups']) !== []) {
            throw new \InvalidArgumentException('config/'.self::FILE.' may only contain permission_groups.');
        }
        // An empty "permission_groups:" is null, which means no groups.
        $raw = $data['permission_groups'] ?? [];
        if (!is_array($raw) || ($raw !== [] && array_is_list($raw)) || count($raw) > self::MAX_GROUPS) {
            throw new \InvalidArgumentException('permission_groups must be a mapping of at most '.self::MAX_GROUPS.' groups.');
        }
        $groups = [];
        $names = [];
        foreach ($raw as $id => $group) {
            $id = (string) $id;
            if (preg_match(self::ID, $id) !== 1) {
                throw new \InvalidArgumentException(sprintf('Permission group id "%s" must use lowercase letters, numbers and hyphens.', $id));
            }
            if (!is_array($group) || ($group !== [] && array_is_list($group))) {
                throw new \InvalidArgumentException(sprintf('Permission group "%s" must be a mapping.', $id));
            }
            $unknown = array_diff(array_keys($group), ['name', 'description', 'websites', 'pages']);
            if ($unknown !== []) {
                throw new \InvalidArgumentException(sprintf('Permission group "%s" has an unknown setting "%s".', $id, (string) reset($unknown)));
            }
            try {
                $name = $this->name(is_string($group['name'] ?? null) ? $group['name'] : '');
                $description = $this->description(is_string($group['description'] ?? '') ? (string) ($group['description'] ?? '') : "\0");
                $grant = AccessGrant::normalize(array_intersect_key($group, ['websites' => true, 'pages' => true]));
            } catch (\InvalidArgumentException $error) {
                throw new \InvalidArgumentException(sprintf('Permission group "%s": %s', $id, $error->getMessage()));
            }
            if (isset($names[strtolower($name)])) {
                throw new \InvalidArgumentException(sprintf('Two permission groups are named "%s".', $name));
            }
            $names[strtolower($name)] = true;
            $groups[$id] = ['name' => $name, 'description' => $description] + $grant;
        }

        return $groups;
    }

    /** @return array<string, array{name: string, description: string, websites: array{all: bool, groups: list<string>, sites: list<string>}, pages: array<string, string>}> */
    private function writable(): array
    {
        $this->reset();
        $this->load();
        if ($this->loadError !== null) {
            throw new \RuntimeException('config/'.self::FILE.' is invalid: '.$this->loadError.' Correct it before saving.');
        }

        return $this->groups ?? [];
    }

    /** @param array<string, array{name: string, description: string, websites: array{all: bool, groups: list<string>, sites: list<string>}, pages: array<string, string>}> $groups */
    private function write(array $groups): void
    {
        $document = [];
        foreach ($groups as $id => $group) {
            $entry = ['name' => $group['name']];
            if ($group['description'] !== '') {
                $entry['description'] = $group['description'];
            }
            $websites = array_filter([
                'all' => $group['websites']['all'] ?: null,
                'groups' => $group['websites']['groups'] ?: null,
                'sites' => $group['websites']['sites'] ?: null,
            ], static fn (mixed $value): bool => $value !== null);
            if ($websites !== []) {
                $entry['websites'] = $websites;
            }
            if ($group['pages'] !== []) {
                $entry['pages'] = $group['pages'];
            }
            $document[$id] = $entry;
        }
        try {
            $this->files->write($this->path(), "# Permission groups: see docs/USERS-AND-PERMISSIONS.md.\n".Yaml::dump(['permission_groups' => $document], 5, 2));
        } catch (\RuntimeException) {
            throw new \RuntimeException('config/'.self::FILE.' could not be saved. Check that the config directory is writable.');
        }
        $this->groups = $groups;
        $this->loadError = null;
    }

    private function name(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > self::MAX_NAME || preg_match('/\p{C}/u', $name) === 1) {
            throw new \InvalidArgumentException('Enter a name of at most '.self::MAX_NAME.' characters, without control characters.');
        }

        return $name;
    }

    private function description(string $description): string
    {
        $description = trim($description);
        if (mb_strlen($description) > self::MAX_DESCRIPTION || preg_match('/[\x00-\x09\x0b-\x1f\x7f]/', $description) === 1) {
            throw new \InvalidArgumentException('Keep the description to '.self::MAX_DESCRIPTION.' characters, without control characters.');
        }

        return $description;
    }

    /** @param array<string, array{name: string}> $groups */
    private function assertUniqueName(array $groups, string $name, ?string $except): void
    {
        foreach ($groups as $id => $group) {
            if ($id !== $except && strcasecmp($group['name'], $name) === 0) {
                throw new \InvalidArgumentException(sprintf('A permission group is already named "%s".', $name));
            }
        }
    }

    /** @param array<string, mixed> $groups */
    private function uniqueId(array $groups, string $name): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
        $base = substr($base === '' ? 'group' : $base, 0, 60);
        $id = $base;
        for ($suffix = 2; isset($groups[$id]); ++$suffix) {
            $id = substr($base, 0, 57).'-'.$suffix;
        }

        return $id;
    }
}
