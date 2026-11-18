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
     * @param array<string, string> $variables
     * @param list<Tag>            $tags
     */
    private function __construct(
        public readonly bool $enabled,
        public readonly array $variables,
        public readonly array $tags,
    ) {
    }

    public static function fromInput(
        mixed $candidate,
        CustomDataProperties $properties,
        CustomCodeSyntax $syntax,
    ): self {
        if (!is_array($candidate)
            || array_diff(array_keys($candidate), ['enabled', 'variables', 'tags']) !== []
        ) {
            throw new \InvalidArgumentException(
                'tag_manager must contain only enabled, variables and tags.',
            );
        }
        $enabled = array_key_exists('enabled', $candidate) ? $candidate['enabled'] : false;
        $rawTags = array_key_exists('tags', $candidate) ? $candidate['tags'] : [];
        $variables = TagVariables::validate(
            array_key_exists('variables', $candidate) ? $candidate['variables'] : [],
        );
        if (!is_bool($enabled)) {
            throw new \InvalidArgumentException(
                'tag_manager.enabled must be a YAML boolean (true or false).',
            );
        }
        if (!is_array($rawTags) || !array_is_list($rawTags) || count($rawTags) > self::MAX_TAGS) {
            throw new \InvalidArgumentException(
                'tag_manager.tags must be a list of at most '.self::MAX_TAGS.' scripts.',
            );
        }

        $tags = [];
        $ids = [];
        $sources = [];
        $codeBytes = 0;
        foreach ($rawTags as $tagData) {
            if (!is_array($tagData)) {
                throw new \InvalidArgumentException(
                    'Each tag must contain only its ID, action, enabled setting, '
                    .'consent, trigger and, optionally, the tag it runs after.',
                );
            }
            $tag = Tag::fromInput($tagData, $variables, $properties, $syntax);
            $id = $tag->id();
            if (isset($ids[$id])) {
                throw new \InvalidArgumentException(
                    'Each tag needs a unique ID of 1–64 letters, digits, '
                    .'underscores or hyphens, beginning with a letter.',
                );
            }
            $ids[$id] = true;
            if ($tag->type() === 'script') {
                $src = $tag->src();
                if ($src !== null && isset($sources[$src])) {
                    throw new \InvalidArgumentException(
                        'Each script tag needs a unique HTTPS URL template.',
                    );
                }
                if ($src !== null) {
                    $sources[$src] = true;
                }
            } elseif ($tag->type() === 'custom') {
                $code = $tag->code();
                if ($code !== null) {
                    $codeBytes += strlen($code);
                    if ($codeBytes > TagCustomCode::MAX_TOTAL_BYTES) {
                        throw new \InvalidArgumentException(sprintf(
                            'Custom JavaScript for one website may total at most %s bytes, '
                            .'because every page downloads it with the tag manager. '
                            .'Load larger code with tag.loadScript().',
                            number_format(TagCustomCode::MAX_TOTAL_BYTES),
                        ));
                    }
                }
            }
            $tags[] = $tag;
        }

        $tagArrays = array_map(static fn (Tag $t): array => $t->toArray(), $tags);
        TagChains::validate($tagArrays);

        return new self($enabled, $variables, $tags);
    }

    /**
     * @return array{enabled: bool, variables: array<string, string>, tags: list<array>}
     */
    public static function validate(
        mixed $candidate,
        CustomDataProperties $properties,
        CustomCodeSyntax $syntax,
    ): array {
        return self::fromInput($candidate, $properties, $syntax)->toArray();
    }

    /**
     * @return array{enabled: bool, variables: array<string, string>, tags: list<array>}
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'variables' => $this->variables,
            'tags' => array_map(static fn (Tag $t): array => $t->toArray(), $this->tags),
        ];
    }

    /**
     * @param array{enabled: bool, tags: list<array>} $settings
     * @return list<array>
     */
    public static function servedTags(array $settings, bool $customScriptsEnabled): array
    {
        return TagBrowserConfig::servedTags($settings, $customScriptsEnabled);
    }

    /**
     * @param array{enabled: bool, tags: list<array>} $settings
     * @return list<string>
     */
    public static function consentCategories(array $settings, bool $customScriptsEnabled): array
    {
        return TagBrowserConfig::consentCategories($settings, $customScriptsEnabled);
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
        return TagBrowserConfig::toBrowserConfig(
            $settings,
            $customScriptsEnabled,
            $trackerNamespace,
        );
    }

    /**
     * @param array{enabled: bool, tags: list<array>} $settings
     * @return array<string, string>
     */
    public static function customScripts(array $settings, bool $customScriptsEnabled): array
    {
        return TagBrowserConfig::customScripts($settings, $customScriptsEnabled);
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
