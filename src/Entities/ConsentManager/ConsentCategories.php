<?php

declare(strict_types=1);

namespace App\Entities\ConsentManager;

/**
 * Resolves and validates consent categories.
 */
final class ConsentCategories
{
    public const MAX_CATEGORIES = 10;

    /**
     * @return list<string> Category names; [] is allowed.
     */
    public static function categoryList(mixed $value, string $path): array
    {
        if (!is_array($value) || ($value !== [] && !array_is_list($value))
            || count($value) > self::MAX_CATEGORIES
        ) {
            throw new \InvalidArgumentException(
                $path.' must be a list of at most '.self::MAX_CATEGORIES
                .' category names; use [] for none.',
            );
        }
        $result = [];
        foreach ($value as $category) {
            if (!is_string($category)
                || preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $category) !== 1
                || in_array(
                    $category,
                    ['none', 'gpc', '__proto__', 'prototype', 'constructor'],
                    true,
                )
            ) {
                throw new \InvalidArgumentException(
                    $path.' entries must be category names of 1–32 lowercase '
                    .'letters, digits, underscores or hyphens, beginning with a letter.',
                );
            }
            if (!in_array($category, $result, true)) {
                $result[] = $category;
            }
        }

        return $result;
    }

    /**
     * @param list<string>         $tagManagerCategories
     * @param array<string, mixed> $consent
     * @return list<string>
     */
    public static function builtin(array $tagManagerCategories, array $consent): array
    {
        $categories = ['analytics'];
        $sources = [
            $consent['categories'] ?? [],
            ConsentExternalTags::categories($consent),
            $tagManagerCategories,
        ];
        foreach ($sources as $source) {
            foreach ($source as $category) {
                if (is_string($category) && $category !== 'none'
                    && !in_array($category, $categories, true)
                ) {
                    $categories[] = $category;
                }
            }
        }

        return $categories;
    }
}
