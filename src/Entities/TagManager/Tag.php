<?php

declare(strict_types=1);

namespace App\Entities\TagManager;

/**
 * Validates and represents one tag in a tag manager configuration.
 */
final class Tag
{
    public const ALLOWED_FIELDS = [
        'id', 'type', 'src', 'method', 'args', 'event', 'code', 'enabled',
        'consent', 'trigger', 'after',
    ];
    public const TYPES = ['script', 'call', 'event', 'custom'];

    /**
     * @param array<string, mixed> $tag
     * @param array<string, string> $variables
     * @param array<string, true>   $seenIds
     * @param array<string, true>   $seenSources
     * @return array<string, mixed>
     */
    public static function validate(
        array $tag,
        array $variables,
        CustomDataProperties $properties,
        array &$seenIds,
        array &$seenSources,
        ?CustomCodeSyntax $syntax = null,
        ?string $cacheDirectory = null,
        int &$codeBytes = 0,
    ): array {
        if (array_diff(array_keys($tag), self::ALLOWED_FIELDS) !== []) {
            throw new \InvalidArgumentException(
                'Each tag must contain only its ID, action, enabled setting, '
                .'consent, trigger and, optionally, the tag it runs after.',
            );
        }
        $id = $tag['id'] ?? null;
        $type = array_key_exists('type', $tag) ? $tag['type'] : 'script';
        $tagEnabled = array_key_exists('enabled', $tag) ? $tag['enabled'] : true;
        $consent = array_key_exists('consent', $tag) ? $tag['consent'] : 'analytics';
        $trigger = TagTrigger::validate(
            array_key_exists('trigger', $tag) ? $tag['trigger'] : ['type' => 'dom_ready'],
        );

        if (!is_string($id)
            || preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/D', $id) !== 1
            || isset($seenIds[$id])
        ) {
            throw new \InvalidArgumentException(
                'Each tag needs a unique ID of 1–64 letters, digits, '
                .'underscores or hyphens, beginning with a letter.',
            );
        }
        if (!is_bool($tagEnabled)) {
            throw new \InvalidArgumentException(
                'Each tag enabled setting must be a YAML boolean (true or false).',
            );
        }
        if (!is_string($consent) || preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $consent) !== 1) {
            throw new \InvalidArgumentException(
                'Each tag consent category must contain 1–32 lowercase letters, '
                .'digits, underscores or hyphens, beginning with a letter. '
                .'Use none only for a tag that needs no consent.',
            );
        }

        $action = TagAction::validate(
            $type,
            $tag,
            $variables,
            $id,
            $properties,
            $seenSources,
            $syntax,
            $cacheDirectory,
            $codeBytes,
        );
        $seenIds[$id] = true;
        $entry = [
            'id' => $id,
            ...$action,
            'enabled' => $tagEnabled,
            'consent' => $consent,
            'trigger' => $trigger,
            'type' => $type,
        ];
        if (array_key_exists('after', $tag)) {
            $entry['after'] = $tag['after'];
        }

        return $entry;
    }
}
