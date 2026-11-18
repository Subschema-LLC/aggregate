<?php

declare(strict_types=1);

namespace App\Entities\TagManager;

/**
 * Validates tag chaining ("Run after") relationships and loop prevention.
 */
final class TagChains
{
    /**
     * @param list<array<string, mixed>> $tags
     * @return list<array<string, mixed>>
     */
    public static function validate(array $tags): array
    {
        $byId = array_column($tags, null, 'id');
        foreach ($tags as $tag) {
            if (!array_key_exists('after', $tag)) {
                continue;
            }
            $after = $tag['after'];
            if (!is_string($after)) {
                throw new \InvalidArgumentException(sprintf(
                    'Tag %s: tag chaining must name the ID of another tag to run after.',
                    $tag['id'],
                ));
            }
            if (!isset($byId[$after])) {
                throw new \InvalidArgumentException(sprintf(
                    'Tag %s runs after %s, but there is no tag with that ID.',
                    $tag['id'],
                    $after,
                ));
            }
            if ($after === $tag['id']) {
                throw new \InvalidArgumentException(sprintf(
                    'Tag %s cannot run after itself.',
                    $tag['id'],
                ));
            }
            $first = $byId[$after];
            if ($first['type'] !== 'script'
                && !in_array($first['trigger']['type'], ['dom_ready', 'window_load'], true)
            ) {
                throw new \InvalidArgumentException(sprintf(
                    'Tag %s runs after %s, which can run many times. Choose a tag '
                    .'that runs once per page: a script, or an action triggered '
                    .'on Document ready or Window finished loading.',
                    $tag['id'],
                    $after,
                ));
            }
            $chain = [$tag['id']];
            for ($cur = $after; $cur !== null; $cur = $byId[$cur]['after'] ?? null) {
                $pos = array_search($cur, $chain, true);
                if ($pos !== false) {
                    $loop = array_slice($chain, $pos);
                    throw new \InvalidArgumentException(sprintf(
                        'Tags %s wait for each other, so none of them would run. '
                        .'Remove the tag chaining from one of them.',
                        count($loop) === 2 ? implode(' and ', $loop) : implode(', ', $loop),
                    ));
                }
                $chain[] = $cur;
            }
        }

        return $tags;
    }
}
