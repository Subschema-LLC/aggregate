<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * "Test this tag" on the tag manager page: runs one tag, as edited and before
 * it is saved, against a sample event in the administrator's browser.
 *
 * The test runs the real public/tag-manager.js in a Web Worker
 * (assets/tag_manager/preview_worker.js) with stand-ins for the page, the
 * tracker and libraries, so nothing is loaded, sent or saved. No code an
 * administrator wrote runs on the dashboard's origin: a custom JavaScript tag
 * is replaced by a stand-in that only records that it would have run, and
 * every other action is data the runtime reads.
 *
 * Variables are checked by the runtime itself: each variable the tag uses gets
 * a probe, a method tag on the same trigger that passes the variable to a
 * recorder. A probe that was not called names a variable the runtime could not
 * read from the sample.
 */
final class TagManagerPreview
{
    /** The method probes call; the worker records its arguments. */
    public const PROBE_METHOD = 'AggregateTagPreview.read';
    /** Stand-in script URL for the tag a tested tag runs after. */
    public const STAND_IN_HOST = 'https://preview.invalid/';

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir = __DIR__.'/../..',
    ) {
    }

    /**
     * Browser settings for one tag of validated settings: the tag itself,
     * enabled; a stand-in for the tag it runs after, if any; and its probes.
     *
     * @param array{enabled: bool, variables: array<string, string>, tags: list<array>} $settings validated (TagManagerSettings::validate)
     * @return array{config: array, custom: list<string>, probes: array<string, array{alias: string, from: string, path: string}>, notes: list<string>, tag: array}
     *
     * @throws \InvalidArgumentException when $tagId is not among the settings' tags
     */
    public function plan(array $settings, string $tagId, string $namespace, bool $customScriptsEnabled = true): array
    {
        $tag = null;
        foreach ($settings['tags'] as $candidate) {
            if ($candidate['id'] === $tagId) {
                $tag = $candidate;
                break;
            }
        }
        if ($tag === null) {
            throw new \InvalidArgumentException('Choose an action and fill in this tag before testing it.');
        }

        $notes = [];
        if (!$settings['enabled']) {
            $notes[] = 'The tag manager is disabled for this website, so no tags run there until you enable it and save.';
        }
        if (!$tag['enabled']) {
            $notes[] = 'This tag is disabled, so it does not run on the website until you enable it. The test runs it anyway.';
        }
        if ($tag['type'] === 'custom') {
            $notes[] = 'The test does not run custom JavaScript; it shows whether the tag would run and which variables it can read.';
            if (!$customScriptsEnabled) {
                $notes[] = 'Custom JavaScript is turned off for this installation, so this tag is not served to visitors.';
            }
        }

        $served = array_diff_key($tag, ['enabled' => true, 'code' => true]);
        $tags = [];
        if (isset($tag['after'])) {
            // The test assumes the tag this one waits for has run and finished.
            $tags[] = ['id' => $tag['after'], 'type' => 'script', 'src' => self::STAND_IN_HOST.$tag['after'].'.js', 'consent' => 'none', 'trigger' => ['type' => 'dom_ready']];
            $notes[] = sprintf('This tag runs after %s. The test assumes %s has run and finished.', $tag['after'], $tag['after']);
        }
        $tags[] = $served;

        $ids = array_flip(array_column($tags, 'id'));
        $probes = [];
        $references = self::references($tag);
        foreach ($references as $index => [$alias, $from]) {
            if (count($tags) >= TagManagerSettings::MAX_TAGS) {
                $notes[] = sprintf('The test checks the first %d variables this tag uses.', $index);
                break;
            }
            $id = 'aggregate-preview-probe-'.($index + 1);
            while (isset($ids[$id])) {
                $id .= '-x';
            }
            $ids[$id] = true;
            $reference = $from === 'event' ? ['$var' => $alias, 'from' => 'event'] : ['$var' => $alias];
            $tags[] = ['id' => $id, 'type' => 'call', 'method' => self::PROBE_METHOD, 'args' => [$id, $reference], 'consent' => 'none', 'trigger' => $tag['trigger']];
            $probes[$id] = ['alias' => $alias, 'from' => $from, 'path' => (string) ($settings['variables'][$alias] ?? '')];
        }

        return [
            'config' => ['enabled' => true, 'variables' => $settings['variables'], 'tags' => $tags, 'namespace' => $namespace],
            'custom' => $tag['type'] === 'custom' ? [$tag['id']] : [],
            'probes' => $probes,
            'notes' => $notes,
            'tag' => ['id' => $tag['id'], 'type' => $tag['type'], 'consent' => $tag['consent'], 'trigger' => $tag['trigger'], 'method' => $tag['method'] ?? null],
        ];
    }

    /**
     * public/tag-manager.js with a plan's settings, and with each custom tag
     * replaced by a stand-in that records that it ran.
     *
     * @param list<string> $customIds
     *
     * @throws TagManagerScriptUnavailable when the source cannot be used
     */
    public function script(array $config, array $customIds): string
    {
        $path = $this->projectDir.'/public/tag-manager.js';
        $source = is_readable($path) ? file_get_contents($path) : false;
        if (!is_string($source) || substr_count($source, TagManagerScript::SOURCE_DEFAULTS) !== 1 || substr_count($source, TagManagerScript::CUSTOM_DEFAULTS) !== 1) {
            throw new TagManagerScriptUnavailable('Tag manager source is unavailable.');
        }
        $json = json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        $standIns = array_map(
            static fn (string $id): string => json_encode($id, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR)
                .": function (tag) { 'use strict'; if (typeof self.AggregateTagPreviewCustom === 'function') self.AggregateTagPreviewCustom(tag.id); }",
            $customIds,
        );
        $custom = $standIns === [] ? '{}' : '{'.implode(",\n", $standIns).'}';

        return strtr($source, [
            TagManagerScript::SOURCE_DEFAULTS => 'var tagManagerConfig = '.$json.';',
            TagManagerScript::CUSTOM_DEFAULTS => 'var customScripts = '.$custom.';',
        ]);
    }

    /**
     * The variables a tag reads, as [alias, "recent"|"event"] pairs in order,
     * without repeats. Custom JavaScript reads variables with tag.get(), which
     * the test cannot see, so it has none.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function references(array $tag): array
    {
        $found = [];
        $walk = static function (mixed $value) use (&$walk, &$found): void {
            if (!is_array($value)) {
                return;
            }
            if (!array_is_list($value) && array_key_exists('$var', $value) && is_string($value['$var'])) {
                $found[] = [$value['$var'], ($value['from'] ?? 'recent') === 'event' ? 'event' : 'recent'];

                return;
            }
            foreach ($value as $member) {
                $walk($member);
            }
        };
        if ($tag['type'] === 'script' && is_string($tag['src'] ?? null)) {
            preg_match_all('/\{\{([A-Za-z][A-Za-z0-9_]{0,63})\}\}/', $tag['src'], $matches);
            foreach ($matches[1] as $alias) {
                $found[] = [$alias, 'recent'];
            }
        } elseif ($tag['type'] === 'call') {
            $walk($tag['args'] ?? []);
        } elseif ($tag['type'] === 'event') {
            $walk($tag['event'] ?? []);
        }

        $unique = [];
        foreach ($found as $reference) {
            $unique[$reference[0].'|'.$reference[1]] = $reference;
        }

        return array_values($unique);
    }
}
