<?php

declare(strict_types=1);

namespace App\Entities\ConsentManager;

/**
 * Validates external tags configuration for scripts the website loads itself.
 */
final class ConsentExternalTags
{
    public const MAX_EXTERNAL_TAGS = 30;

    /**
     * @return list<array{id: string, name: string, category: string}>
     */
    public static function validate(mixed $tags, string $path): array
    {
        if (!is_array($tags) || ($tags !== [] && !array_is_list($tags))
            || count($tags) > self::MAX_EXTERNAL_TAGS
        ) {
            throw new \InvalidArgumentException(
                $path.' must be a list of at most '.self::MAX_EXTERNAL_TAGS
                .' tags; use [] for none.',
            );
        }
        $result = [];
        $ids = [];
        foreach ($tags as $index => $tag) {
            $itemPath = $path.'['.$index.']';
            if (!is_array($tag) || array_is_list($tag)
                || array_diff(array_keys($tag), ['id', 'name', 'category']) !== []
            ) {
                throw new \InvalidArgumentException(
                    $itemPath.' must be a mapping with only id, name and category.',
                );
            }
            $id = $tag['id'] ?? null;
            if (!is_string($id) || preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/D', $id) !== 1) {
                throw new \InvalidArgumentException(
                    $itemPath.'.id must be 1–64 letters, digits, underscores '
                    .'or hyphens, beginning with a letter.',
                );
            }
            if (isset($ids[$id])) {
                throw new \InvalidArgumentException(
                    $itemPath.'.id "'.$id.'" is duplicated.',
                );
            }
            $ids[$id] = true;
            $category = $tag['category'] ?? null;
            if (!is_string($category)
                || preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $category) !== 1
                || in_array(
                    $category,
                    ['none', 'gpc', '__proto__', 'prototype', 'constructor'],
                    true,
                )
            ) {
                throw new \InvalidArgumentException(
                    $itemPath.'.category must be a category name of 1–32 lowercase letters, '
                    .'digits, underscores or hyphens, beginning with a letter; '
                    .'none and gpc are reserved, so every listed tag waits for consent.',
                );
            }
            $name = ConsentText::wording($tag['name'] ?? null, 120, $itemPath.'.name');
            $result[] = ['id' => $id, 'name' => $name, 'category' => $category];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $validated
     * @return list<string>
     */
    public static function categories(array $validated): array
    {
        return array_values(array_unique(
            array_column($validated['external_tags'] ?? [], 'category'),
        ));
    }
}
