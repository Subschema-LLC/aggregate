<?php

declare(strict_types=1);

namespace App\Entities\TagManager;

/**
 * Root entity for tag manager configuration, validation and projection.
 */
final class TagManager
{
    public const MAX_TAGS = 20;
    public const DEFAULTS = ['enabled' => false, 'variables' => [], 'tags' => []];

    /**
     * @return array{enabled: bool, variables: array<string, string>, tags: list<array>}
     */
    public static function validate(
        mixed $submitted,
        CustomDataProperties $properties,
        ?CustomCodeSyntax $syntax = null,
        ?string $cacheDirectory = null,
    ): array {
        if (!is_array($submitted)
            || array_diff(array_keys($submitted), ['enabled', 'variables', 'tags']) !== []
        ) {
            throw new \InvalidArgumentException(
                'tag_manager must contain only enabled, variables and tags.',
            );
        }
        $enabled = array_key_exists('enabled', $submitted) ? $submitted['enabled'] : false;
        $tags = array_key_exists('tags', $submitted) ? $submitted['tags'] : [];
        $variables = TagVariables::validate(
            array_key_exists('variables', $submitted) ? $submitted['variables'] : [],
        );
        if (!is_bool($enabled)) {
            throw new \InvalidArgumentException(
                'tag_manager.enabled must be a YAML boolean (true or false).',
            );
        }
        if (!is_array($tags) || !array_is_list($tags) || count($tags) > self::MAX_TAGS) {
            throw new \InvalidArgumentException(
                'tag_manager.tags must be a list of at most '.self::MAX_TAGS.' scripts.',
            );
        }

        $validated = [];
        $ids = [];
        $sources = [];
        $codeBytes = 0;
        foreach ($tags as $tag) {
            if (!is_array($tag)) {
                throw new \InvalidArgumentException(
                    'Each tag must contain only its ID, action, enabled setting, '
                    .'consent, trigger and, optionally, the tag it runs after.',
                );
            }
            $validated[] = Tag::validate(
                $tag,
                $variables,
                $properties,
                $ids,
                $sources,
                $syntax,
                $cacheDirectory,
                $codeBytes,
            );
        }

        return [
            'enabled' => $enabled,
            'variables' => $variables,
            'tags' => TagChains::validate($validated),
        ];
    }

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
     * @param array{
     *     enabled: bool,
     *     variables: array<string, string>,
     *     tags: list<array>
     * } $settings
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

    /**
     * @param array{tags: list<array>} $settings
     * @param list<string>             $trackerNamespaces
     * @return list<string>
     */
    public static function emitWarnings(array $settings, array $trackerNamespaces): array
    {
        return TagValues::emitWarnings($settings, $trackerNamespaces);
    }
}
