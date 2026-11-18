<?php

declare(strict_types=1);

namespace App\Entities\ConsentManager;

/**
 * Storage contract for loading, persisting and previewing consent manager configurations.
 */
interface ConsentManagers
{
    /**
     * @return array<string, mixed>
     */
    public function consent(string $siteId): array;

    /**
     * @param array<string, mixed> $consent
     */
    public function saveConsent(string $siteId, array $consent): void;

    /**
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    public function previewConsent(string $siteId, array $changes): array;
}
