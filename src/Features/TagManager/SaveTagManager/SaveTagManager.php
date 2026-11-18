<?php

declare(strict_types=1);

namespace App\Features\TagManager\SaveTagManager;

use App\Entities\TagManager\CustomCodeSyntax;
use App\Entities\TagManager\CustomDataProperties;
use App\Entities\TagManager\TagManager;
use App\Entities\TagManager\TagManagers;

/**
 * Validates and saves tag manager settings for a website or shared configuration.
 */
final class SaveTagManager
{
    public function __construct(
        private readonly TagManagers $store,
        private readonly CustomDataProperties $properties,
        private readonly ?CustomCodeSyntax $syntax = null,
        private readonly ?string $codeCacheDirectory = null,
    ) {
    }

    /**
     * @param array<string, mixed> $submitted
     * @param list<string>         $trackerNamespaces
     * @return list<string> warnings about tags that emit events with problematic structure
     */
    public function save(
        array $submitted,
        ?string $siteId = null,
        bool $customScriptsEnabled = true,
        array $trackerNamespaces = ['Aggregate'],
    ): array {
        $validated = TagManager::validate(
            $submitted,
            $this->properties,
            $this->syntax,
            $this->codeCacheDirectory,
        );

        if (!$customScriptsEnabled) {
            $this->assertNoNewCustomCode($validated, $siteId);
        }

        $this->store->save($validated, $siteId);

        return TagManager::emitWarnings($validated, $trackerNamespaces);
    }

    /**
     * @param array{tags: list<array>} $settings
     */
    private function assertNoNewCustomCode(array $settings, ?string $siteId): void
    {
        $saved = [];
        try {
            $current = $this->store->all($siteId);
            foreach ($current['tags'] as $tag) {
                if (($tag['type'] ?? '') === 'custom' && isset($tag['id'], $tag['code'])) {
                    $saved[$tag['id']] = $tag['code'];
                }
            }
        } catch (\Throwable) {
            // Unreadable existing settings are handled by the store.
        }

        foreach ($settings['tags'] as $tag) {
            if ($tag['type'] === 'custom' && ($saved[$tag['id']] ?? null) !== $tag['code']) {
                throw new \InvalidArgumentException(
                    'Custom JavaScript tags are turned off for this installation on the Feature '
                    .'flags page. Existing ones are kept but not served, and none can be added '
                    .'or changed.',
                );
            }
        }
    }
}
