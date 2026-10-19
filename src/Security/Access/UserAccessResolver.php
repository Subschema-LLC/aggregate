<?php

declare(strict_types=1);

namespace App\Security\Access;

use App\Entity\User;
use App\Service\WebsiteScopeCatalog;

/**
 * Combines a user's own grants with their permission groups into
 * EffectiveAccess. A grant adds access; nothing takes it away, so a user has
 * the highest level any of their sources gives. Permission groups that no
 * longer exist, and website groups while their file is invalid, grant nothing.
 */
final class UserAccessResolver
{
    /** @var array<string, EffectiveAccess> */
    private array $cache = [];

    public function __construct(
        private readonly PermissionGroupCatalog $permissionGroups,
        private readonly WebsiteScopeCatalog $websiteGroups,
    ) {
    }

    public function forUser(User $user): EffectiveAccess
    {
        if ($user->isAdmin()) {
            return EffectiveAccess::administrator();
        }
        $access = $user->getAccess();
        $key = $user->getUserIdentifier()."\0".serialize($access);

        return $this->cache[$key] ??= $this->resolve($access);
    }

    /** Forgets resolved access, for example after a permission group changes. */
    public function reset(): void
    {
        $this->cache = [];
        $this->permissionGroups->reset();
    }

    /**
     * @param array{permission_groups: list<string>, websites: array{all: bool, groups: list<string>, sites: list<string>}, pages: array<string, string>} $access
     */
    private function resolve(array $access): EffectiveAccess
    {
        $sources = [EffectiveAccess::SOURCE_OWN => $access];
        $names = [];
        $missing = [];
        $groups = $this->permissionGroups->hasLoadError() ? [] : $this->permissionGroups->groups();
        foreach ($access['permission_groups'] as $id) {
            if (!isset($groups[$id])) {
                $missing[] = $id;
                continue;
            }
            $sources[$id] = $groups[$id];
            $names[$id] = $groups[$id]['name'];
        }

        $pages = [];
        $pageSources = [];
        $everyWebsite = [];
        $websiteGroups = [];
        $sites = [];
        foreach ($sources as $source => $grant) {
            $source = (string) $source;
            foreach ($grant['pages'] as $page => $level) {
                $pages[$page] = DashboardPages::higher($pages[$page] ?? null, $level) ?? $level;
                $pageSources[$page][$source] = $level;
            }
            if ($grant['websites']['all']) {
                $everyWebsite[] = $source;
            }
            foreach ($grant['websites']['groups'] as $group) {
                $websiteGroups[$group][] = $source;
            }
            foreach ($grant['websites']['sites'] as $token) {
                $sites[$token][] = $source;
            }
        }
        // In menu order, so summaries read like the menu.
        $pages = array_intersect_key(array_replace(array_fill_keys(array_keys(DashboardPages::PAGES), null), $pages), $pages);
        $catalog = $this->websiteGroups;

        return new EffectiveAccess(
            false,
            $pages,
            $pageSources,
            $everyWebsite,
            $websiteGroups,
            $sites,
            $names,
            $missing,
            static fn (string $token): ?string => $catalog->groupIdForToken($token),
        );
    }
}
