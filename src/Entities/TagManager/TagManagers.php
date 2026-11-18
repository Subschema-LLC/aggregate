<?php

declare(strict_types=1);

namespace App\Entities\TagManager;

/**
 * Storage contract for loading and persisting tag manager configurations.
 */
interface TagManagers
{
    /**
     * @return array{enabled: bool, variables: array<string, string>, tags: list<array>}
     */
    public function all(?string $siteId = null): array;

    /**
     * @param array{enabled: bool, variables: array<string, string>, tags: list<array>} $settings
     */
    public function save(array $settings, ?string $siteId = null): void;
}
