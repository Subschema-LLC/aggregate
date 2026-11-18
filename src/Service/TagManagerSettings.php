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
        private readonly ?string $codeCacheDirectory = null,
        private readonly ?CustomDataProperties $properties = null,
        private readonly ?CustomCodeSyntax $syntax = null,
        private readonly ?TagManagers $store = null,
        private readonly ?SaveTagManager $saver = null,
    ) {
    }

    /** @return array{enabled: bool, variables: array<string, string>, tags: list<array>} */
    public function all(?string $siteId = null): array
    {
        $config = $this->configuration($siteId);
        $config->assertHealthy();
        $values = $config->all();

        return self::validate(
            array_key_exists('tag_manager', $values) ? $values['tag_manager'] : self::DEFAULTS,
            $this->codeCacheDirectory,
            $this->properties,
            $this->syntax,
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
        $saved = [];
        try {
            foreach ($this->all($siteId)['tags'] as $tag) {
                if ($tag['type'] === 'custom') {
                    $saved[$tag['id']] = $tag['code'];
                }
            }
        } catch (\Throwable) {
        }
        foreach ($settings['tags'] as $tag) {
            if ($tag['type'] === 'custom' && ($saved[$tag['id']] ?? null) !== $tag['code']) {
                throw new \InvalidArgumentException('Custom JavaScript tags are turned off for this installation on the Feature flags page. Existing ones are kept but not served, and none can be added or changed.');
            }
        }
    }

    public function save(array $submitted, ?string $siteId = null): void
    {
        $saver = $this->saver ?? new SaveTagManager(
            $this->store(),
            $this->properties ?? self::defaultProperties(),
            $this->syntax ?? self::defaultSyntax(),
            $this->codeCacheDirectory,
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

    private function store(): TagManagers
    {
        if ($this->store !== null) {
            return $this->store;
        }

        return new class($this->config, $this->sites) implements TagManagers {
            public function __construct(
                private readonly AggregateConfigLoader $config,
                private readonly ?SiteScriptConfig $sites,
            ) {
            }

            public function all(?string $siteId = null): array
            {
                $cfg = $siteId !== null && $this->sites !== null ? $this->sites->configuration($siteId) : $this->config;
                $cfg->assertHealthy();
                $values = $cfg->all();

                return array_key_exists('tag_manager', $values) && is_array($values['tag_manager'])
                    ? $values['tag_manager']
                    : EntityTagManager::DEFAULTS;
            }

            public function save(array $settings, ?string $siteId = null): void
            {
                $cfg = $siteId !== null && $this->sites !== null ? $this->sites->configuration($siteId) : $this->config;
                if ($siteId !== null && $this->sites !== null) {
                    $this->sites->ensureDirectory();
                }
                $cfg->updateMany(static function (array $current) use ($settings): array {
                    EntityTagManager::validate($current['tag_manager'] ?? EntityTagManager::DEFAULTS, new class implements CustomDataProperties {
                        public function isValidKey(string $key): bool { return true; }
                        public function isBuiltin(string $key): bool { return false; }
                    });

                    return ['tag_manager' => $settings];
                });
            }
        };
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
        ?string $codeCacheDirectory = null,
        ?CustomDataProperties $properties = null,
        ?CustomCodeSyntax $syntax = null,
    ): array {
        return EntityTagManager::validate(
            $submitted,
            $properties ?? self::defaultProperties(),
            $syntax ?? self::defaultSyntax(),
            $codeCacheDirectory,
        );
    }

    private static function defaultProperties(): CustomDataProperties
    {
        return new class implements CustomDataProperties {
            public function isValidKey(string $key): bool { return CustomDataSettings::isValidPropertyKey($key); }
            public function isBuiltin(string $key): bool { return isset(CustomDataSettings::BUILTIN_PROPERTIES[$key]); }
        };
    }

    private static function defaultSyntax(): CustomCodeSyntax
    {
        return new class implements CustomCodeSyntax {
            public function validateSyntax(string $code, string $path, ?string $cacheDirectory = null): void
            {
                TagManagerCustomCode::validate($code, $path, $cacheDirectory);
            }
        };
    }
}
