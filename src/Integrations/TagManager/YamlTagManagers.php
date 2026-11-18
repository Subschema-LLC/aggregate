<?php

declare(strict_types=1);

namespace App\Integrations\TagManager;

use App\Entities\TagManager\TagManager;
use App\Entities\TagManager\TagManagers;
use App\Service\AggregateConfigLoader;
use App\Service\SiteScriptConfig;

/**
 * Persists and loads tag manager configuration from YAML files.
 */
final class YamlTagManagers implements TagManagers
{
    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly ?SiteScriptConfig $sites = null,
    ) {
    }

    public function all(?string $siteId = null): array
    {
        $config = $this->configuration($siteId);
        $config->assertHealthy();
        $values = $config->all();

        return array_key_exists('tag_manager', $values) && is_array($values['tag_manager'])
            ? $values['tag_manager']
            : TagManager::DEFAULTS;
    }

    public function save(array $settings, ?string $siteId = null): void
    {
        $config = $this->configuration($siteId);
        if ($siteId !== null && $this->sites !== null) {
            $this->sites->ensureDirectory();
        }
        $config->updateMany(static fn (array $current): array => [
            'tag_manager' => $settings,
        ]);
    }

    private function configuration(?string $siteId): AggregateConfigLoader
    {
        if ($siteId === null) {
            return $this->config;
        }
        if ($this->sites === null) {
            throw new \InvalidArgumentException('Website script configuration is unavailable.');
        }

        return $this->sites->configuration($siteId);
    }
}
