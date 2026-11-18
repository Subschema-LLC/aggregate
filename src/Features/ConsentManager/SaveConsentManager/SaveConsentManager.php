<?php

declare(strict_types=1);

namespace App\Features\ConsentManager\SaveConsentManager;

use App\Entities\ConsentManager\ConsentManagers;

/**
 * Validates and saves consent manager settings for a website.
 */
final class SaveConsentManager
{
    public function __construct(private readonly ConsentManagers $store)
    {
    }

    /**
     * @param array<string, mixed> $consent
     */
    public function save(string $siteId, array $consent): void
    {
        $this->store->saveConsent($siteId, $consent);
    }
}
