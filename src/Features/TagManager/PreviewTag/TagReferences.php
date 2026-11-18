<?php

declare(strict_types=1);

namespace App\Features\TagManager\PreviewTag;

/**
 * Extracts variable references read by a tag for testing.
 */
final class TagReferences
{
    /**
     * @param array<string, mixed> $tag
     * @return list<array{0: string, 1: string}> pairs of [alias, "recent"|"event"]
     */
    public static function references(array $tag): array
    {
        $found = [];
        $walk = static function (mixed $value) use (&$walk, &$found): void {
            if (!is_array($value)) {
                return;
            }
            if (!array_is_list($value) && array_key_exists('$var', $value)
                && is_string($value['$var'])
            ) {
                $found[] = [
                    $value['$var'],
                    ($value['from'] ?? 'recent') === 'event' ? 'event' : 'recent',
                ];

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
