<?php

declare(strict_types=1);

namespace App\Integrations\ConsentManager;

use App\Entities\ConsentManager\ConsentManagers;
use App\Service\SiteScriptConfig;

/**
 * Persists and loads consent manager settings from website YAML configurations.
 */
final class YamlConsentManagers implements ConsentManagers
{
    public function __construct(private readonly SiteScriptConfig $sites)
    {
    }

    public function consent(string $siteId): array
    {
        return $this->sites->consent($siteId);
    }

    public function saveConsent(string $siteId, array $consent): void
    {
        $this->sites->saveConsent($siteId, $consent);
    }

    public function previewConsent(string $siteId, array $changes): array
    {
        return $this->sites->previewConsent($siteId, $changes);
    }
}
