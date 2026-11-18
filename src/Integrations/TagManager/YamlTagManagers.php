<?php

declare(strict_types=1);

namespace App\Integrations\TagManager;

use App\Entities\TagManager\CustomCodeSyntax;
use App\Entities\TagManager\CustomDataProperties;
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
        private readonly CustomDataProperties $properties,
        private readonly CustomCodeSyntax $syntax,
        private readonly ?SiteScriptConfig $sites = null,
    ) {
    }

    public function all(?string $siteId = null): array
    {
        $config = $this->configuration($siteId);
        $config->assertHealthy();
        $values = $config->all();
        $raw = array_key_exists('tag_manager', $values)
            ? $values['tag_manager']
            : TagManager::DEFAULTS;

        return TagManager::validate($raw, $this->properties, $this->syntax);
    }

    public function save(array $settings, ?string $siteId = null): void
    {
        $config = $this->configuration($siteId);
        if ($siteId !== null && $this->sites !== null) {
            $this->sites->ensureDirectory();
        }
        $config->updateMany(function (array $current) use ($settings): array {
            // Hand-edited settings that no longer validate are never replaced.
            TagManager::validate(
                array_key_exists('tag_manager', $current)
                    ? $current['tag_manager']
                    : TagManager::DEFAULTS,
                $this->properties,
                $this->syntax,
            );

            return ['tag_manager' => $settings];
        });
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
