<?php

declare(strict_types=1);

namespace App\Features\TagManager\PreviewTag;

use App\Entities\TagManager\TagManager;

/**
 * Plans the isolated preview run for a single tag in a web worker.
 */
final class PreviewTag
{
    public const PROBE_METHOD = 'AggregateTagPreview.read';
    public const STAND_IN_HOST = 'https://preview.invalid/';

    /**
     * @param array{enabled: bool, variables: array<string, string>, tags: list<array>} $settings
     * @return array{
     *     config: array,
     *     custom: list<string>,
     *     probes: array<string, array{alias: string, from: string, path: string}>,
     *     notes: list<string>,
     *     tag: array
     * }
     */
    public function plan(
        array $settings,
        string $tagId,
        string $namespace,
        bool $customScriptsEnabled = true,
    ): array {
        $tag = null;
        foreach ($settings['tags'] as $candidate) {
            if ($candidate['id'] === $tagId) {
                $tag = $candidate;
                break;
            }
        }
        if ($tag === null) {
            throw new \InvalidArgumentException(
                'Choose an action and fill in this tag before testing it.',
            );
        }

        $notes = [];
        if (!$settings['enabled']) {
            $notes[] = 'The tag manager is disabled for this website, so no tags run there '
                .'until you enable it and save.';
        }
        if (!$tag['enabled']) {
            $notes[] = 'This tag is disabled, so it does not run on the website until you '
                .'enable it. The test runs it anyway.';
        }
        if ($tag['type'] === 'custom') {
            $notes[] = 'The test does not run custom JavaScript; it shows whether the tag '
                .'would run and which variables it can read.';
            if (!$customScriptsEnabled) {
                $notes[] = 'Custom JavaScript is turned off for this installation, so this '
                    .'tag is not served to visitors.';
            }
        }

        $served = array_diff_key($tag, ['enabled' => true, 'code' => true]);
        $tags = [];
        if (isset($tag['after'])) {
            $tags[] = [
                'id' => $tag['after'],
                'type' => 'script',
                'src' => self::STAND_IN_HOST.$tag['after'].'.js',
                'consent' => 'none',
                'trigger' => ['type' => 'dom_ready'],
            ];
            $notes[] = sprintf(
                'This tag runs after %s. The test assumes %s has run and finished.',
                $tag['after'],
                $tag['after'],
            );
        }
        $tags[] = $served;

        $ids = array_flip(array_column($tags, 'id'));
        $probes = [];
        $references = TagReferences::references($tag);
        foreach ($references as $index => [$alias, $from]) {
            if (count($tags) >= TagManager::MAX_TAGS) {
                $notes[] = sprintf('The test checks the first %d variables this tag uses.', $index);
                break;
            }
            $id = 'aggregate-preview-probe-'.($index + 1);
            while (isset($ids[$id])) {
                $id .= '-x';
            }
            $ids[$id] = true;
            $reference = $from === 'event'
                ? ['$var' => $alias, 'from' => 'event']
                : ['$var' => $alias];
            $tags[] = [
                'id' => $id,
                'type' => 'call',
                'method' => self::PROBE_METHOD,
                'args' => [$id, $reference],
                'consent' => 'none',
                'trigger' => $tag['trigger'],
            ];
            $probes[$id] = [
                'alias' => $alias,
                'from' => $from,
                'path' => (string) ($settings['variables'][$alias] ?? ''),
            ];
        }

        return [
            'config' => [
                'enabled' => true,
                'variables' => $settings['variables'],
                'tags' => $tags,
                'namespace' => $namespace,
            ],
            'custom' => $tag['type'] === 'custom' ? [$tag['id']] : [],
            'probes' => $probes,
            'notes' => $notes,
            'tag' => [
                'id' => $tag['id'],
                'type' => $tag['type'],
                'consent' => $tag['consent'],
                'trigger' => $tag['trigger'],
                'method' => $tag['method'] ?? null,
            ],
        ];
    }
}
