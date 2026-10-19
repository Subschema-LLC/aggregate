<?php

declare(strict_types=1);

namespace App\Security\Access;

/**
 * What one user can do: the union of their own grants and their permission
 * groups, with where each grant came from. Administrators can do everything.
 * Built by UserAccessResolver.
 */
final class EffectiveAccess
{
    public const SOURCE_OWN = 'own';

    /**
     * @param array<string, string>             $pages        page => highest level
     * @param array<string, array<string, string>> $pageSources page => [source => level]
     * @param list<string>                      $allWebsitesSources
     * @param array<string, list<string>>       $groups       website group id => sources
     * @param array<string, list<string>>       $sites        website token => sources
     * @param array<string, string>             $permissionGroups permission group id => name, for sources
     * @param list<string>                      $missingPermissionGroups assigned ids that no longer exist
     * @param \Closure(string): ?string         $groupOfToken website token => its website group id
     */
    public function __construct(
        private readonly bool $admin,
        private readonly array $pages,
        private readonly array $pageSources,
        private readonly array $allWebsitesSources,
        private readonly array $groups,
        private readonly array $sites,
        private readonly array $permissionGroups,
        private readonly array $missingPermissionGroups,
        private readonly \Closure $groupOfToken,
    ) {
    }

    public static function administrator(): self
    {
        return new self(true, [], [], [], [], [], [], [], static fn (string $token): ?string => null);
    }

    public static function none(): self
    {
        return new self(false, [], [], [], [], [], [], [], static fn (string $token): ?string => null);
    }

    public function isAdmin(): bool
    {
        return $this->admin;
    }

    /** The level for a page, or null for no access. */
    public function level(string $page): ?string
    {
        if ($this->admin) {
            return DashboardPages::hasPage($page) ? DashboardPages::EDIT : null;
        }

        return $this->pages[$page] ?? null;
    }

    public function allows(string $page, string $level): bool
    {
        return DashboardPages::satisfies($this->level($page), $level);
    }

    /** Every website and group, including ones added later, and deployment-wide settings. */
    public function allowsEveryWebsite(): bool
    {
        return $this->admin || $this->allWebsitesSources !== [];
    }

    public function allowsGroup(string $groupId): bool
    {
        return $this->allowsEveryWebsite() || isset($this->groups[$groupId]);
    }

    public function allowsToken(string $token): bool
    {
        if ($this->allowsEveryWebsite() || isset($this->sites[$token])) {
            return true;
        }
        $group = ($this->groupOfToken)($token);

        return $group !== null && isset($this->groups[$group]);
    }

    /** True when the user can use at least one page. */
    public function hasAnyPage(): bool
    {
        return $this->admin || $this->pages !== [];
    }

    /**
     * For the profile and Users pages: each granted page with its level and
     * where it came from, and the websites and groups with theirs.
     *
     * @return array{
     *     admin: bool,
     *     pages: list<array{page: string, label: string, scope: string, level: string, sources: list<array{label: string, level: string}>}>,
     *     every_website: list<string>,
     *     groups: array<string, list<string>>,
     *     sites: array<string, list<string>>,
     *     missing_permission_groups: list<string>
     * }
     */
    public function summary(): array
    {
        $pages = [];
        foreach ($this->pages as $page => $level) {
            $sources = [];
            foreach ($this->pageSources[$page] ?? [] as $source => $sourceLevel) {
                $sources[] = ['label' => $this->sourceLabel($source), 'level' => $sourceLevel];
            }
            $pages[] = [
                'page' => $page,
                'label' => DashboardPages::label($page),
                'scope' => DashboardPages::PAGES[$page]['scope'],
                'level' => $level,
                'sources' => $sources,
            ];
        }
        $labels = fn (array $sources): array => array_map(fn (string $source): string => $this->sourceLabel($source), $sources);

        return [
            'admin' => $this->admin,
            'pages' => $pages,
            'every_website' => $labels($this->allWebsitesSources),
            'groups' => array_map($labels, $this->groups),
            'sites' => array_map($labels, $this->sites),
            'missing_permission_groups' => $this->missingPermissionGroups,
        ];
    }

    private function sourceLabel(string $source): string
    {
        return $source === self::SOURCE_OWN
            ? 'Assigned directly'
            : 'Permission group: '.($this->permissionGroups[$source] ?? $source);
    }
}
