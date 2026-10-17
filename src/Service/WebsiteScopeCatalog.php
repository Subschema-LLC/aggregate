<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Named website groups and the word used for them.
 *
 * The catalog is config/website-scopes.yaml. Each group or website that
 * overrides a setting has its own file under config/website-scopes/. A missing
 * catalog means every website uses the deployment settings. An invalid catalog
 * fails closed: collection does not guess which websites share a group.
 * WebsiteScopeFiles reads and writes the files; this class keeps the groups,
 * changes them and reports whether the setting files are valid.
 */
final class WebsiteScopeCatalog implements ResetInterface
{
    public const FILE = 'website-scopes.yaml';
    public const DIRECTORY = 'website-scopes';
    public const DEFAULT_SINGULAR = 'Group';
    public const DEFAULT_PLURAL = 'Groups';
    public const MAX_NAME = 80;
    public const MAX_LABEL = 40;
    public const MAX_GROUPS = 200;
    public const MAX_MEMBERS = 500;
    private readonly ParsedYamlCache $parsedYaml;
    private readonly WebsiteScopeFiles $files;
    private bool $loaded = false;
    private bool $loadFailed = false;

    /** @var array{singular: string, plural: string} */
    private array $label = ['singular' => self::DEFAULT_SINGULAR, 'plural' => self::DEFAULT_PLURAL];

    /** @var array<string, array{name: string, members: list<string>}> */
    private array $groups = [];

    /** @var array<string, string> */
    private array $tokenIndex = [];

    /**
     * The last health result, reused only while every overlay file is unchanged.
     *
     * @var array{fingerprint: string, invalid: bool}|null
     */
    private ?array $overlayHealth = null;

    public function __construct(
        string $projectDir,
        string $environment = 'prod',
        ?ParsedYamlCache $parsedYaml = null,
    ) {
        $this->parsedYaml = $parsedYaml ?? ParsedYamlCache::forProject($projectDir, $environment);
        $this->files = new WebsiteScopeFiles($projectDir, $this->parsedYaml);
    }

    public function hasLoadError(): bool
    {
        $this->load();

        return $this->loadFailed;
    }

    /**
     * @return array{singular: string, plural: string}
     */
    public function label(): array
    {
        $this->load();

        return $this->label;
    }

    /**
     * @return array<string, array{name: string, members: list<string>}>
     */
    public function groups(): array
    {
        $this->load();

        return $this->groups;
    }

    public function hasGroup(string $id): bool
    {
        $this->load();

        return isset($this->groups[$id]);
    }

    public function groupIdForToken(string $token): ?string
    {
        $this->load();

        return $this->tokenIndex[$token] ?? null;
    }

    public function groupName(string $id): ?string
    {
        $this->load();

        return $this->groups[$id]['name'] ?? null;
    }

    /**
     * @return array{ok: bool, values: array<string, mixed>}
     */
    public function readOverlay(string $kind, string $id): array
    {
        return $this->files->readOverlay($kind, $id);
    }

    public function hasInvalidOverlay(): bool
    {
        if ($this->hasLoadError()) {
            return true;
        }
        // A monitor polls this check. Reading and locking every overlay on
        // every poll does not scale with the number of websites. A file's
        // name, size and modification time are enough to notice an edit,
        // including one made outside this process; only a change reads the
        // files again.
        [$fingerprint, $newest] = $this->files->fingerprint();
        if ($this->overlayHealth !== null && $this->overlayHealth['fingerprint'] === $fingerprint) {
            return $this->overlayHealth['invalid'];
        }
        $check = function (): array {
            foreach ($this->overlayDocuments() as $document) {
                if (!$document['ok'] || ScopeKeys::errors($document['values']) !== null) {
                    return ['invalid' => true];
                }
            }

            return ['invalid' => false];
        };
        // Under PHP-FPM every request builds a new catalog, so the result is
        // also kept beside the parsed YAML, keyed by the fingerprint. Times
        // have whole seconds: while a file is under two seconds old, a second
        // edit in the same second could keep its size and time, so the result
        // is not stored for later requests until the files have settled.
        $result = time() - $newest >= 2
            ? $this->parsedYaml->remember('website-scope-health', $fingerprint, static fn (): array => $check())
            : $check();
        $invalid = ($result['invalid'] ?? true) !== false;
        $this->overlayHealth = ['fingerprint' => $fingerprint, 'invalid' => $invalid];

        return $invalid;
    }

    public function reset(): void
    {
        $this->loaded = false;
        $this->loadFailed = false;
        $this->label = ['singular' => self::DEFAULT_SINGULAR, 'plural' => self::DEFAULT_PLURAL];
        $this->groups = [];
        $this->tokenIndex = [];
        $this->overlayHealth = null;
    }

    /**
     * @return list<array{kind: string, id: string, label: string, ok: bool, values: array<string, mixed>}>
     */
    public function overlayDocuments(): array
    {
        $this->load();
        $documents = [];
        foreach ($this->groups as $id => $group) {
            $overlay = $this->readOverlay('groups', $id);
            $documents[] = [
                'kind' => 'group',
                'id' => $id,
                'label' => $group['name'],
                'ok' => $overlay['ok'],
                'values' => $overlay['values'],
            ];
        }
        foreach ($this->files->websiteOverlayIds() as $id) {
            $overlay = $this->readOverlay('websites', $id);
            $documents[] = [
                'kind' => 'website',
                'id' => $id,
                'label' => $id,
                'ok' => $overlay['ok'],
                'values' => $overlay['values'],
            ];
        }

        return $documents;
    }

    public function setLabel(string $singular, string $plural): void
    {
        $this->assertWritable();
        $this->label = [
            'singular' => $this->normalizeLabel($singular, self::DEFAULT_SINGULAR),
            'plural' => $this->normalizeLabel($plural, self::DEFAULT_PLURAL),
        ];
        $this->writeCatalog();
    }

    public function createGroup(string $name): string
    {
        $this->assertWritable();
        if (count($this->groups) >= self::MAX_GROUPS) {
            throw new \InvalidArgumentException('At most '.self::MAX_GROUPS.' website groups can be saved.');
        }
        $name = $this->normalizeName($name);
        $id = $this->uniqueSlug($name);
        $this->groups[$id] = ['name' => $name, 'members' => []];
        $this->writeCatalog();

        return $id;
    }

    public function renameGroup(string $id, string $name): void
    {
        $this->assertWritable();
        if (!isset($this->groups[$id])) {
            throw new \InvalidArgumentException('That group does not exist.');
        }
        $this->groups[$id]['name'] = $this->normalizeName($name);
        $this->writeCatalog();
    }

    public function deleteGroup(string $id): void
    {
        $this->assertWritable();
        if (!isset($this->groups[$id])) {
            throw new \InvalidArgumentException('That group does not exist.');
        }
        unset($this->groups[$id]);
        $this->rebuildIndex();
        $this->writeCatalog();
        $path = $this->files->overlayPath('groups', $id);
        if (is_file($path) && !@unlink($path)) {
            throw new \RuntimeException('The group was removed, but its setting file could not be deleted.');
        }
    }

    public function assign(string $token, ?string $groupId): void
    {
        $this->assertWritable();
        $this->assertToken($token);
        if ($groupId !== null && !isset($this->groups[$groupId])) {
            throw new \InvalidArgumentException('That group does not exist.');
        }
        foreach ($this->groups as $id => $group) {
            $this->groups[$id]['members'] = array_values(array_filter(
                $group['members'],
                static fn (string $member): bool => $member !== $token,
            ));
        }
        if ($groupId !== null) {
            if (count($this->groups[$groupId]['members']) >= self::MAX_MEMBERS) {
                throw new \InvalidArgumentException('A group can contain at most '.self::MAX_MEMBERS.' websites.');
            }
            $this->groups[$groupId]['members'][] = $token;
        }
        $this->rebuildIndex();
        $this->writeCatalog();
    }

    /**
     * Drops a deleted website from its group and removes its setting file.
     * An invalid catalog is left untouched so a website deletion cannot
     * overwrite a file the operator still needs to repair.
     */
    public function forgetWebsite(string $token): void
    {
        if ($token === '' || strlen($token) > 191) {
            return;
        }
        if (!$this->hasLoadError() && is_file($this->files->catalogPath()) && $this->groupIdForToken($token) !== null) {
            $this->assign($token, null);
        }
        try {
            $path = $this->files->overlayPath('websites', SiteScriptConfig::idForToken($token));
        } catch (\Throwable) {
            return;
        }
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * @param array<string, mixed> $set
     * @param list<string> $remove
     */
    public function saveOverlay(string $kind, string $id, array $set, array $remove): void
    {
        if (!$this->files->validOverlayId($kind, $id)) {
            throw new \InvalidArgumentException('That website or group setting file is not valid.');
        }
        $current = $this->readOverlay($kind, $id);
        $values = $current['ok'] ? $this->repairable($current['values']) : [];
        foreach ($remove as $key) {
            unset($values[$key]);
        }
        foreach ($set as $key => $value) {
            $values[$key] = $value;
        }
        $error = ScopeKeys::errors($values);
        if ($error !== null) {
            throw new \InvalidArgumentException($error);
        }
        $this->files->writeOverlay($kind, $id, $values);
        $this->overlayHealth = null;
    }

    /**
     * @param array<mixed> $values a parsed overlay, which may have keys that are not settings
     *
     * @return array<string, mixed>
     */
    private function repairable(array $values): array
    {
        $kept = [];
        foreach ($values as $key => $value) {
            if (!is_string($key) || !in_array($key, ScopeKeys::allowed(), true)) {
                continue;
            }
            if (ScopeKeys::errors([$key => $value]) === null) {
                $kept[$key] = $value;
            }
        }

        return $kept;
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;
        $path = $this->files->catalogPath();
        if (!is_file($path)) {
            return;
        }
        try {
            $data = $this->files->read($path, 'website-scopes');
            if ($data === []) {
                return;
            }
            $this->applyCatalog($data);
        } catch (\Throwable) {
            $this->loadFailed = true;
            $this->label = ['singular' => self::DEFAULT_SINGULAR, 'plural' => self::DEFAULT_PLURAL];
            $this->groups = [];
            $this->tokenIndex = [];
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function applyCatalog(array $data): void
    {
        if (array_diff(array_keys($data), ['label', 'groups']) !== []) {
            throw new \RuntimeException('The website group file contains an unknown setting.');
        }
        $label = $data['label'] ?? [];
        if (!is_array($label) || ($label !== [] && array_is_list($label)) || array_diff(array_keys($label), ['singular', 'plural']) !== []) {
            throw new \RuntimeException('The website group word must be a mapping of singular and plural.');
        }
        $this->label = [
            'singular' => $this->normalizeLabel((string) ($label['singular'] ?? self::DEFAULT_SINGULAR), self::DEFAULT_SINGULAR),
            'plural' => $this->normalizeLabel((string) ($label['plural'] ?? self::DEFAULT_PLURAL), self::DEFAULT_PLURAL),
        ];
        $groups = $data['groups'] ?? [];
        if (!is_array($groups) || ($groups !== [] && array_is_list($groups)) || count($groups) > self::MAX_GROUPS) {
            throw new \RuntimeException('Website groups must be a mapping.');
        }
        $parsed = [];
        $index = [];
        foreach ($groups as $id => $group) {
            if (!is_string($id) || preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/D', $id) !== 1
                || !is_array($group) || array_is_list($group)
                || array_diff(array_keys($group), ['name', 'members']) !== []
                || !is_string($group['name'] ?? null)) {
                throw new \RuntimeException('A website group is not valid.');
            }
            $name = $this->normalizeName($group['name']);
            $members = $group['members'] ?? [];
            if (!is_array($members) || ($members !== [] && !array_is_list($members)) || count($members) > self::MAX_MEMBERS) {
                throw new \RuntimeException('A website group membership list is not valid.');
            }
            $tokens = [];
            foreach ($members as $token) {
                if (!is_string($token) || $token === '' || strlen($token) > 191 || preg_match('/\s/', $token) === 1 || isset($index[$token])) {
                    throw new \RuntimeException('A website can belong to only one group, and its token must be valid.');
                }
                $tokens[] = $token;
                $index[$token] = $id;
            }
            $parsed[$id] = ['name' => $name, 'members' => $tokens];
        }
        $this->groups = $parsed;
        $this->tokenIndex = $index;
    }

    private function assertWritable(): void
    {
        $this->load();
        if ($this->loadFailed) {
            throw new \RuntimeException('Website group configuration is invalid. Correct config/website-scopes.yaml before saving.');
        }
    }

    private function writeCatalog(): void
    {
        $groups = [];
        foreach ($this->groups as $id => $group) {
            $groups[$id] = ['name' => $group['name']];
            if ($group['members'] !== []) {
                $groups[$id]['members'] = $group['members'];
            }
        }
        $data = ['label' => $this->label];
        if ($groups !== []) {
            $data['groups'] = $groups;
        }
        $this->files->write($this->files->catalogPath(), Yaml::dump($data, 4, 2));
        $this->loaded = true;
        $this->loadFailed = false;
        $this->overlayHealth = null;
        $this->rebuildIndex();
    }

    private function rebuildIndex(): void
    {
        $this->tokenIndex = [];
        foreach ($this->groups as $id => $group) {
            foreach ($group['members'] as $token) {
                $this->tokenIndex[$token] = $id;
            }
        }
    }

    private function uniqueSlug(string $name): string
    {
        $base = strtolower($name);
        $base = preg_replace('/[^a-z0-9]+/', '-', $base) ?? '';
        $base = trim($base, '-');
        if ($base === '') {
            $base = 'group';
        }
        $base = substr($base, 0, 60);
        $id = $base;
        $suffix = 2;
        while (isset($this->groups[$id])) {
            $id = substr($base, 0, 57).'-'.$suffix;
            ++$suffix;
        }

        return $id;
    }

    private function normalizeName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || strlen($name) > self::MAX_NAME || preg_match('/\p{C}/u', $name) === 1) {
            throw new \InvalidArgumentException('Enter a name of at most '.self::MAX_NAME.' characters, without control characters.');
        }

        return $name;
    }

    private function normalizeLabel(string $value, string $fallback): string
    {
        $value = trim($value);
        if ($value === '') {
            return $fallback;
        }
        if (strlen($value) > self::MAX_LABEL || preg_match('/^[A-Za-z][A-Za-z0-9 ._-]{0,39}$/D', $value) !== 1) {
            throw new \InvalidArgumentException('The group word must start with a letter and use only letters, numbers, spaces, periods, hyphens or underscores.');
        }

        return $value;
    }

    private function assertToken(string $token): void
    {
        if ($token === '' || strlen($token) > 191 || preg_match('/\s/', $token) === 1) {
            throw new \InvalidArgumentException('That website token is not valid.');
        }
    }

}
