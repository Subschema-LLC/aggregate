<?php

declare(strict_types=1);

namespace App\Entities\TagManager;

/**
 * Projects tag manager settings into runtime browser configuration and served tags.
 */
final class TagBrowserConfig
{
    /**
     * @param array{enabled: bool, tags: list<array>} $settings
     * @return list<array>
     */
    public static function servedTags(array $settings, bool $customScriptsEnabled): array
    {
        if (!$settings['enabled']) {
            return [];
        }
        $served = array_filter(
            $settings['tags'],
            static fn (array $t): bool => $t['enabled']
                && ($customScriptsEnabled || $t['type'] !== 'custom'),
        );
        do {
            $ids = array_flip(array_column($served, 'id'));
            $before = count($served);
            $served = array_filter(
                $served,
                static fn (array $t): bool => !isset($t['after']) || isset($ids[$t['after']]),
            );
        } while (count($served) !== $before);

        return array_values($served);
    }

    /**
     * @param array{enabled: bool, tags: list<array>} $settings
     * @return list<string>
     */
    public static function consentCategories(array $settings, bool $customScriptsEnabled): array
    {
        $categories = ['analytics' => true];
        foreach (self::servedTags($settings, $customScriptsEnabled) as $tag) {
            if ($tag['consent'] !== 'none') {
                $categories[$tag['consent']] = true;
            }
        }
        unset($categories['analytics']);
        $names = array_keys($categories);
        sort($names);

        return ['analytics', ...$names];
    }

    /**
     * @param array{enabled: bool, variables: array<string, string>, tags: list<array>} $settings
     * @return array{
     *     enabled: bool,
     *     variables: array<string, string>,
     *     tags: list<array>,
     *     namespace?: string
     * }
     */
    public static function toBrowserConfig(
        array $settings,
        bool $customScriptsEnabled,
        ?string $trackerNamespace = null,
    ): array {
        $tags = self::servedTags($settings, $customScriptsEnabled);
        $config = [
            'enabled' => $settings['enabled'],
            'variables' => $settings['enabled'] ? $settings['variables'] : [],
            'tags' => array_map(
                static fn (array $t): array => array_diff_key(
                    $t,
                    ['enabled' => true, 'code' => true],
                ),
                $tags,
            ),
        ];
        if ($trackerNamespace !== null
            && array_intersect(['custom', 'event'], array_column($tags, 'type')) !== []
        ) {
            $config['namespace'] = $trackerNamespace;
        }

        return $config;
    }

    /**
     * @param array{enabled: bool, tags: list<array>} $settings
     * @return array<string, string>
     */
    public static function customScripts(array $settings, bool $customScriptsEnabled): array
    {
        $scripts = [];
        foreach (self::servedTags($settings, $customScriptsEnabled) as $tag) {
            if ($tag['type'] === 'custom') {
                $scripts[$tag['id']] = $tag['code'];
            }
        }

        return $scripts;
    }
}
