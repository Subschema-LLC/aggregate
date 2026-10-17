<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The tag manager page's form: turns a submission into settings that
 * TagManagerSettings has validated, and turns a rejected submission back into
 * the rows the page shows again. Only the dashboard submits this form; YAML
 * and the command line go to TagManagerSettings directly.
 */
final class TagManagerForm
{
    /**
     * Rebuilds the editor rows from a rejected form exactly as typed, including
     * values that failed validation. Nothing here is saved or served.
     *
     * @return array{0: array, 1: list<array>}
     */
    public static function draft(array $form, array $settings, array $variableRows): array
    {
        $text = static fn (mixed $value, int $limit = 4096): string => is_string($value) ? substr($value, 0, $limit) : '';
        $rows = static fn (mixed $value, int $limit): array => is_array($value) ? array_slice(array_values($value), 0, $limit) : [];

        if (in_array($form['enabled'] ?? null, ['0', '1'], true)) {
            $settings['enabled'] = $form['enabled'] === '1';
        }

        if (array_key_exists('variables', $form)) {
            $variableRows = [];
            foreach ($rows($form['variables'], TagManagerVariables::MAX_VARIABLES) as $row) {
                if (!is_array($row)) continue;
                $variable = ['alias' => $text($row['alias'] ?? null), 'path' => $text($row['path'] ?? null), 'remove' => ($row['remove'] ?? null) === '1'];
                if ($variable['alias'] !== '' || $variable['path'] !== '') $variableRows[] = $variable;
            }
        }

        if (array_key_exists('tags', $form)) {
            $tags = [];
            foreach ($rows($form['tags'], TagManagerSettings::MAX_TAGS) as $row) {
                if (!is_array($row)) continue;
                $trigger = is_array($row['trigger'] ?? null) ? $row['trigger'] : [];
                $tag = [
                    'id' => $text($row['id'] ?? null),
                    'type' => in_array($row['type'] ?? null, ['script', 'event', 'call', 'custom'], true) ? $row['type'] : 'script',
                    'src' => $text($row['src'] ?? null),
                    'method' => $text($row['method'] ?? null),
                    'args_json' => $text($row['args_json'] ?? null, 2_000_000),
                    'event_json' => $text($row['event_json'] ?? null, 2_000_000),
                    'code' => $text($row['code'] ?? null, 100_000),
                    'enabled' => ($row['enabled'] ?? '1') !== '0',
                    'consent' => $text($row['consent'] ?? null, 64),
                    'trigger' => ['type' => $text($trigger['type'] ?? null, 64) ?: 'dom_ready', 'event' => $text($trigger['event'] ?? null, 200)],
                    'after' => $text($row['after'] ?? null, 64),
                    'remove' => ($row['remove'] ?? null) === '1',
                ];
                // The trailing empty row comes back as the page's usual new-tag row.
                if ($tag['id'] === '' && $tag['src'] === '' && $tag['method'] === '' && trim($tag['code']) === '' && in_array(trim($tag['args_json']), ['', '[]'], true) && self::blankEvent($tag['event_json'])) continue;
                $tags[] = $tag;
            }
            $settings['tags'] = $tags;
        }

        return [$settings, $variableRows];
    }

    /** An event field left empty, or as the editor's untouched starting point. */
    private static function blankEvent(string $event): bool
    {
        return trim($event) === '' || preg_replace('/\s+/', '', $event) === '{"eventName":"","customData":{}}';
    }

    public static function settings(array $submitted): array
    {
        if (array_diff(array_keys($submitted), ['_csrf_token', 'site', 'enabled', 'variables', 'tags']) !== []
            || !in_array($submitted['enabled'] ?? null, ['0', '1'], true)
            || !is_array($submitted['tags'] ?? null) || !array_is_list($submitted['tags'])
            || count($submitted['tags']) > TagManagerSettings::MAX_TAGS) {
            throw new \InvalidArgumentException('The tag manager form contained missing or unexpected settings. Nothing was saved.');
        }
        $rows = $submitted['variables'] ?? [];
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > TagManagerVariables::MAX_VARIABLES) {
            throw new \InvalidArgumentException('Variables must be a list of aliases and data paths.');
        }
        $variables = [];
        foreach ($rows as $row) {
            if (!is_array($row) || array_diff(array_keys($row), ['alias', 'path', 'remove']) !== []
                || !is_string($row['alias'] ?? null) || !is_string($row['path'] ?? null)
                || (array_key_exists('remove', $row) && $row['remove'] !== '1')) {
                throw new \InvalidArgumentException('Each variable needs an alias and a data path.');
            }
            if (($row['remove'] ?? null) === '1' || ($row['alias'] === '' && $row['path'] === '')) continue;
            if (array_key_exists($row['alias'], $variables)) {
                throw new \InvalidArgumentException('Each variable alias must be unique.');
            }
            $variables[$row['alias']] = $row['path'];
        }

        $tags = [];
        foreach ($submitted['tags'] as $tag) {
            if (!is_array($tag) || array_diff(array_keys($tag), ['id', 'type', 'src', 'method', 'args_json', 'event_json', 'code', 'enabled', 'consent', 'trigger', 'after', 'remove']) !== []
                || !is_string($tag['id'] ?? null) || !in_array($tag['enabled'] ?? null, ['0', '1'], true)
                || (array_key_exists('remove', $tag) && $tag['remove'] !== '1')) {
                throw new \InvalidArgumentException('Each tag needs a valid ID, action and availability setting.');
            }
            foreach (['type', 'src', 'method', 'args_json', 'event_json', 'code', 'consent', 'after'] as $key) {
                if (array_key_exists($key, $tag) && !is_string($tag[$key])) {
                    throw new \InvalidArgumentException('Tag action and consent fields must be text.');
                }
            }
            if (($tag['remove'] ?? null) === '1') continue;
            $type = $tag['type'] ?? 'script';
            $src = $tag['src'] ?? '';
            $method = $tag['method'] ?? '';
            $args = trim($tag['args_json'] ?? '');
            $event = trim($tag['event_json'] ?? '');
            $code = $tag['code'] ?? '';
            if ($tag['id'] === '' && $src === '' && $method === '' && trim($code) === '' && in_array($args, ['', '[]'], true) && self::blankEvent($event)) continue;
            if ($type !== 'event' && !self::blankEvent($event)) {
                throw new \InvalidArgumentException('Only a Send an Aggregate event action uses an event. Clear it or choose that action.');
            }
            if ($type !== 'custom' && trim($code) !== '') {
                throw new \InvalidArgumentException('Only a Run custom JavaScript action uses code. Clear the code or choose that action.');
            }
            if ($type === 'script') {
                if ($method !== '' || !in_array($args, ['', '[]'], true)) {
                    throw new \InvalidArgumentException('A script action uses its HTTPS URL. Clear the method and arguments or choose Call a library method.');
                }
                $action = ['src' => $src];
            } elseif ($type === 'call') {
                if ($src !== '') throw new \InvalidArgumentException('A method action must not also contain a script URL.');
                try {
                    if (strlen($args) > 2_000_000 || ($args !== '' && !str_starts_with($args, '['))) {
                        throw new \InvalidArgumentException('Method arguments must be a JSON array.');
                    }
                    $decoded = $args === '' ? [] : json_decode($args, true, 32, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    throw new \InvalidArgumentException('Method arguments must be valid JSON, for example ["purchase", {"total_minor": 1299}].');
                }
                $action = ['method' => $method, 'args' => $decoded];
            } elseif ($type === 'event') {
                if ($src !== '' || $method !== '' || !in_array($args, ['', '[]'], true)) {
                    throw new \InvalidArgumentException('A Send an Aggregate event action uses only its event. Clear the script URL, method and arguments.');
                }
                try {
                    if (strlen($event) > 2_000_000 || !str_starts_with($event, '{')) {
                        throw new \JsonException();
                    }
                    $decoded = json_decode($event, true, 32, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    throw new \InvalidArgumentException('Tag '.$tag['id'].': the event must be a JSON object, for example {"eventName": "login", "customData": {"method": "password"}}.');
                }
                $action = ['event' => $decoded];
            } elseif ($type === 'custom') {
                if ($src !== '' || $method !== '' || !in_array($args, ['', '[]'], true)) {
                    throw new \InvalidArgumentException('A custom JavaScript action uses only its code. Clear the script URL, method and arguments.');
                }
                $action = ['code' => $code];
            } else {
                throw new \InvalidArgumentException('Choose a script, Aggregate event, library method or custom JavaScript action.');
            }
            $trigger = $tag['trigger'] ?? ['type' => 'dom_ready'];
            if (!is_array($trigger) || array_diff(array_keys($trigger), ['type', 'event']) !== []
                || !is_string($trigger['type'] ?? null)
                || (array_key_exists('event', $trigger) && !is_string($trigger['event']))) {
                throw new \InvalidArgumentException('Each tag needs a valid trigger type and event name.');
            }
            if (in_array($trigger['type'], ['dom_ready', 'window_load'], true) && ($trigger['event'] ?? '') === '') unset($trigger['event']);
            $entry = ['id' => $tag['id'], 'type' => $type, ...$action, 'enabled' => $tag['enabled'] === '1', 'consent' => $tag['consent'] ?? 'analytics', 'trigger' => $trigger];
            if (($tag['after'] ?? '') !== '') $entry['after'] = $tag['after'];
            $tags[] = $entry;
        }

        return TagManagerSettings::validate(['enabled' => $submitted['enabled'] === '1', 'variables' => $variables, 'tags' => $tags]);
    }
}
