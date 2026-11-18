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
        private readonly ?SiteScriptConfig $sites = null,
        private readonly ?CustomDataProperties $properties = null,
        private readonly ?CustomCodeSyntax $syntax = null,
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

        if ($this->properties !== null && $this->syntax !== null) {
            return TagManager::validate($raw, $this->properties, $this->syntax);
        }

        if (!is_array($raw)) {
            throw new \InvalidArgumentException(
                'tag_manager must contain only enabled, variables and tags.',
            );
        }

        return $raw;
    }

    public function save(array $settings, ?string $siteId = null): void
    {
        $config = $this->configuration($siteId);
        if ($siteId !== null && $this->sites !== null) {
            $this->sites->ensureDirectory();
        }
        $config->updateMany(function (array $current) use ($settings): array {
            if ($this->properties !== null && $this->syntax !== null) {
                TagManager::validate(
                    array_key_exists('tag_manager', $current)
                        ? $current['tag_manager']
                        : TagManager::DEFAULTS,
                    $this->properties,
                    $this->syntax,
                );
            }

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
