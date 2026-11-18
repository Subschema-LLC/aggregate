<?php

declare(strict_types=1);

namespace App\Features\TagManager\TagForm;

use App\Entities\TagManager\CustomCodeSyntax;
use App\Entities\TagManager\CustomDataProperties;
use App\Entities\TagManager\TagManager;

/**
 * Validates and extracts tag manager settings from form submissions.
 */
final class TagForm
{
    private const ALLOWED_TAG_KEYS = [
        'id', 'type', 'src', 'method', 'args_json', 'event_json',
        'code', 'enabled', 'consent', 'trigger', 'after', 'remove',
    ];
    private const TEXT_KEYS = [
        'type', 'src', 'method', 'args_json', 'event_json', 'code', 'consent', 'after',
    ];

    /**
     * @param array<string, mixed> $form
     * @param array<string, mixed> $settings
     * @param list<array>          $variableRows
     * @return array{0: array, 1: list<array>}
     */
    public static function draft(array $form, array $settings, array $variableRows): array
    {
        return TagFormDraft::draft($form, $settings, $variableRows);
    }

    /** @param array<string, mixed> $row */
    public static function isBlankRow(array $row): bool
    {
        return TagFormDraft::isBlankRow($row);
    }

    public static function blankEvent(string $event): bool
    {
        return TagFormDraft::blankEvent($event);
    }

    /**
     * @param array<string, mixed> $submitted
     * @return array{enabled: bool, variables: array<string, string>, tags: list<array>}
     */
    public static function settings(
        array $submitted,
        ?CustomDataProperties $properties = null,
        ?CustomCodeSyntax $syntax = null,
        ?string $codeCacheDirectory = null,
    ): array {
        if (array_diff(
            array_keys($submitted),
            ['_csrf_token', 'site', 'enabled', 'variables', 'tags'],
        ) !== []
            || !in_array($submitted['enabled'] ?? null, ['0', '1'], true)
            || !is_array($submitted['tags'] ?? null) || !array_is_list($submitted['tags'])
            || count($submitted['tags']) > TagManager::MAX_TAGS
        ) {
            throw new \InvalidArgumentException(
                'The tag manager form contained missing or unexpected settings. '
                .'Nothing was saved.',
            );
        }
        $variables = TagFormVariables::extract($submitted['variables'] ?? []);
        $tags = self::extractTags($submitted['tags']);

        $props = $properties ?? new class implements CustomDataProperties {
            public function isValidKey(string $key): bool { return true; }
            public function isBuiltin(string $key): bool { return false; }
        };

        return TagManager::validate(
            [
                'enabled' => $submitted['enabled'] === '1',
                'variables' => $variables,
                'tags' => $tags,
            ],
            $props,
            $syntax,
            $codeCacheDirectory,
        );
    }

    /**
     * @param list<mixed> $rows
     * @return list<array<string, mixed>>
     */
    private static function extractTags(array $rows): array
    {
        $tags = [];
        foreach ($rows as $tag) {
            if (!is_array($tag)
                || array_diff(array_keys($tag), self::ALLOWED_TAG_KEYS) !== []
                || !is_string($tag['id'] ?? null)
                || !in_array($tag['enabled'] ?? null, ['0', '1'], true)
                || (array_key_exists('remove', $tag) && $tag['remove'] !== '1')
            ) {
                throw new \InvalidArgumentException(
                    'Each tag needs a valid ID, action and availability setting.',
                );
            }
            foreach (self::TEXT_KEYS as $key) {
                if (array_key_exists($key, $tag) && !is_string($tag[$key])) {
                    throw new \InvalidArgumentException(
                        'Tag action and consent fields must be text.',
                    );
                }
            }
            if (($tag['remove'] ?? null) === '1' || self::isBlankRow($tag)) {
                continue;
            }
            $type = $tag['type'] ?? 'script';
            $src = $tag['src'] ?? '';
            $method = $tag['method'] ?? '';
            $args = trim($tag['args_json'] ?? '');
            $event = trim($tag['event_json'] ?? '');
            $code = $tag['code'] ?? '';
            if ($type !== 'event' && !self::blankEvent($event)) {
                throw new \InvalidArgumentException(
                    'Only a Send an Aggregate event action uses an event. '
                    .'Clear it or choose that action.',
                );
            }
            if ($type !== 'custom' && trim($code) !== '') {
                throw new \InvalidArgumentException(
                    'Only a Run custom JavaScript action uses code. '
                    .'Clear the code or choose that action.',
                );
            }
            $action = TagFormAction::extract($type, $tag, $src, $method, $args, $event, $code);
            $trigger = $tag['trigger'] ?? ['type' => 'dom_ready'];
            if (!is_array($trigger)
                || array_diff(array_keys($trigger), ['type', 'event']) !== []
                || !is_string($trigger['type'] ?? null)
                || (array_key_exists('event', $trigger) && !is_string($trigger['event']))
            ) {
                throw new \InvalidArgumentException(
                    'Each tag needs a valid trigger type and event name.',
                );
            }
            if (in_array($trigger['type'], ['dom_ready', 'window_load'], true)
                && ($trigger['event'] ?? '') === ''
            ) {
                unset($trigger['event']);
            }
            $entry = [
                'id' => $tag['id'],
                'type' => $type,
                ...$action,
                'enabled' => $tag['enabled'] === '1',
                'consent' => $tag['consent'] ?? 'analytics',
                'trigger' => $trigger,
            ];
            if (($tag['after'] ?? '') !== '') {
                $entry['after'] = $tag['after'];
            }
            $tags[] = $entry;
        }

        return $tags;
    }
}
