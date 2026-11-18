<?php

declare(strict_types=1);

namespace App\Features\TagManager\TagForm;

use App\Entities\TagManager\TagManager;
use App\Entities\TagManager\TagVariables;

/**
 * Rebuilds editor rows from a rejected form submission.
 */
final class TagFormDraft
{
    /**
     * @param array<string, mixed> $form
     * @param array<string, mixed> $settings
     * @param list<array>          $variableRows
     * @return array{0: array, 1: list<array>}
     */
    public static function draft(array $form, array $settings, array $variableRows): array
    {
        $text = static fn (mixed $val, int $limit = 4096): string => is_string($val)
            ? substr($val, 0, $limit)
            : '';
        $rows = static fn (mixed $val, int $limit): array => is_array($val)
            ? array_slice(array_values($val), 0, $limit)
            : [];

        if (in_array($form['enabled'] ?? null, ['0', '1'], true)) {
            $settings['enabled'] = $form['enabled'] === '1';
        }

        if (array_key_exists('variables', $form)) {
            $variableRows = [];
            foreach ($rows($form['variables'], TagVariables::MAX_VARIABLES) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $variable = [
                    'alias' => $text($row['alias'] ?? null),
                    'path' => $text($row['path'] ?? null),
                    'remove' => ($row['remove'] ?? null) === '1',
                ];
                if ($variable['alias'] !== '' || $variable['path'] !== '') {
                    $variableRows[] = $variable;
                }
            }
        }

        if (array_key_exists('tags', $form)) {
            $tags = [];
            foreach ($rows($form['tags'], TagManager::MAX_TAGS) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $trigger = is_array($row['trigger'] ?? null) ? $row['trigger'] : [];
                $type = in_array(
                    $row['type'] ?? null,
                    ['script', 'event', 'call', 'custom'],
                    true,
                ) ? $row['type'] : 'script';
                $tag = [
                    'id' => $text($row['id'] ?? null),
                    'type' => $type,
                    'src' => $text($row['src'] ?? null),
                    'method' => $text($row['method'] ?? null),
                    'args_json' => $text($row['args_json'] ?? null, 2_000_000),
                    'event_json' => $text($row['event_json'] ?? null, 2_000_000),
                    'code' => $text($row['code'] ?? null, 100_000),
                    'enabled' => ($row['enabled'] ?? '1') !== '0',
                    'consent' => $text($row['consent'] ?? null, 64),
                    'trigger' => [
                        'type' => $text($trigger['type'] ?? null, 64) ?: 'dom_ready',
                        'event' => $text($trigger['event'] ?? null, 200),
                    ],
                    'after' => $text($row['after'] ?? null, 64),
                    'remove' => ($row['remove'] ?? null) === '1',
                ];
                if ($tag['id'] === '' && $tag['src'] === '' && $tag['method'] === ''
                    && trim($tag['code']) === ''
                    && in_array(trim($tag['args_json']), ['', '[]'], true)
                    && self::blankEvent($tag['event_json'])
                ) {
                    continue;
                }
                $tags[] = $tag;
            }
            $settings['tags'] = $tags;
        }

        return [$settings, $variableRows];
    }

    /** @param array<string, mixed> $row */
    public static function isBlankRow(array $row): bool
    {
        $text = static fn (string $key): string => is_string($row[$key] ?? null)
            ? $row[$key]
            : '';

        return $text('id') === '' && $text('src') === '' && $text('method') === ''
            && trim($text('code')) === ''
            && in_array(trim($text('args_json')), ['', '[]'], true)
            && self::blankEvent(trim($text('event_json')));
    }

    public static function blankEvent(string $event): bool
    {
        return trim($event) === ''
            || preg_replace('/\s+/', '', $event) === '{"eventName":"","customData":{}}';
    }
}
