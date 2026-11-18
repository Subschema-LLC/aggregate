<?php

declare(strict_types=1);

namespace App\Service;

use App\Entities\TagManager\CustomCodeSyntax;
use App\Entities\TagManager\CustomDataProperties;
use App\Entities\TagManager\TagManager as EntityTagManager;
use App\Entities\TagManager\TagManagers;
use App\Features\TagManager\SaveTagManager\SaveTagManager;

/** Website script settings, with a legacy deployment-wide configuration. */
final class TagManagerSettings
{
    public const MAX_TAGS = EntityTagManager::MAX_TAGS;
    public const DEFAULTS = EntityTagManager::DEFAULTS;

    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly ?SiteScriptConfig $sites = null,
        private readonly ?FeatureFlags $features = null,
        private readonly ?CustomDataProperties $properties = null,
        private readonly ?CustomCodeSyntax $syntax = null,
        private readonly ?TagManagers $store = null,
        private readonly ?SaveTagManager $saver = null,
    ) {
    }

    /** @return array{enabled: bool, variables: array<string, string>, tags: list<array>} */
    public function all(?string $siteId = null): array
    {
        if ($this->store !== null) {
            $raw = $this->store->all($siteId);

            return self::validate($raw, $this->properties(), $this->syntax());
        }

        $config = $this->configuration($siteId);
        $config->assertHealthy();
        $values = $config->all();

        return self::validate(
            array_key_exists('tag_manager', $values) ? $values['tag_manager'] : self::DEFAULTS,
            $this->properties(),
            $this->syntax(),
        );
    }

    public function customScriptsEnabled(): bool
    {
        return $this->features === null || $this->features->isEnabled(TagManagerCustomCode::FEATURE);
    }

    /** @param array{tags: list<array>} $settings */
    public function assertNoNewCustomCode(array $settings, ?string $siteId = null): void
    {
        if ($this->customScriptsEnabled()) {
            return;
        }
        $saver = $this->saver ?? new SaveTagManager(
            $this->store(),
            $this->properties(),
            $this->syntax(),
        );
        $saver->assertNoNewCustomCode($settings, $siteId);
    }

    public function save(array $submitted, ?string $siteId = null): void
    {
        $saver = $this->saver ?? new SaveTagManager(
            $this->store(),
            $this->properties(),
            $this->syntax(),
        );
        $saver->save($submitted, $siteId, $this->customScriptsEnabled(), $this->trackerNamespaces());
    }

    public function toBrowserConfig(?string $siteId = null): array
    {
        return EntityTagManager::toBrowserConfig(
            $this->all($siteId),
            $this->customScriptsEnabled(),
            $this->trackerNamespace(),
        );
    }

    public function trackerNamespace(): string
    {
        return TrackerNamespace::of($this->config);
    }

    /** @return list<string> */
    public function trackerNamespaces(): array
    {
        return array_values(array_unique([$this->trackerNamespace(), TrackerNamespace::DEFAULT]));
    }

    /**
     * @param array{tags: list<array>} $settings
     * @return list<string>
     */
    public function emitWarnings(array $settings): array
    {
        return EntityTagManager::emitWarnings($settings, $this->trackerNamespaces());
    }

    /** @return array<string, string> */
    public function customScripts(?string $siteId = null): array
    {
        return EntityTagManager::customScripts($this->all($siteId), $this->customScriptsEnabled());
    }

    /** @return list<string> */
    public function consentCategories(?string $siteId = null): array
    {
        return EntityTagManager::consentCategories($this->all($siteId), $this->customScriptsEnabled());
    }

    public function properties(): CustomDataProperties
    {
        return $this->properties ?? throw new \LogicException('CustomDataProperties is required.');
    }

    public function syntax(): CustomCodeSyntax
    {
        return $this->syntax ?? throw new \LogicException('CustomCodeSyntax is required.');
    }

    private function store(): TagManagers
    {
        return $this->store ?? throw new \LogicException('TagManagers store is required.');
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

    /** @return array{enabled: bool, variables: array<string, string>, tags: list<array>} */
    public static function validate(
        mixed $submitted,
        CustomDataProperties $properties,
        CustomCodeSyntax $syntax,
    ): array {
        return EntityTagManager::validate($submitted, $properties, $syntax);
    }
}
