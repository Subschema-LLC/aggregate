<?php

declare(strict_types=1);

namespace App\Entities\ConsentManager;

/**
 * Projects validated consent manager settings into browser script configuration.
 */
final class ConsentBrowserConfig
{
    /**
     * @param array<string, mixed> $validated
     * @return array<string, mixed>
     */
    public static function toBrowserConfig(array $validated): array
    {
        $browser = [];
        foreach (['text', 'theme', 'buttons'] as $group) {
            if (!array_key_exists($group, $validated)) {
                continue;
            }
            $values = [];
            foreach ($validated[$group] as $key => $value) {
                $values[self::camel($key)] = $value;
            }
            $browser[$group] = $values;
        }
        foreach (['layout', 'consent_type', 'close_button', 'default_granted'] as $key) {
            if (array_key_exists($key, $validated)) {
                $browser[self::camel($key)] = $validated[$key];
            }
        }
        if (($validated['external_tags'] ?? []) !== []) {
            $browser['externalTags'] = $validated['external_tags'];
        }
        if (array_key_exists('regions', $validated)) {
            $browser['regions'] = self::browserRegions($validated['regions']);
        }
        if (array_key_exists('default_region', $validated)
            && $validated['default_region'] !== null
        ) {
            $browser['defaultRegion'] = $validated['default_region'];
        }

        return $browser;
    }

    /**
     * @param list<array<string, mixed>> $regions
     * @return list<array<string, mixed>>
     */
    private static function browserRegions(array $regions): array
    {
        $browserRegions = [];
        foreach ($regions as $reg) {
            $item = [
                'id' => $reg['id'],
                'name' => $reg['name'],
                'consentType' => $reg['consent_type'],
                'default' => $reg['default'],
            ];
            foreach (['close_button', 'default_granted'] as $key) {
                if (array_key_exists($key, $reg)) {
                    $item[self::camel($key)] = $reg[$key];
                }
            }
            if (isset($reg['text'])) {
                $item['text'] = [];
                foreach ($reg['text'] as $tk => $tv) {
                    $item['text'][self::camel($tk)] = $tv;
                }
            }
            if (isset($reg['buttons'])) {
                $item['buttons'] = [];
                foreach ($reg['buttons'] as $bk => $bv) {
                    $item['buttons'][self::camel($bk)] = $bv;
                }
            }
            $browserRegions[] = $item;
        }

        return $browserRegions;
    }

    private static function camel(string $key): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $key))));
    }
}
