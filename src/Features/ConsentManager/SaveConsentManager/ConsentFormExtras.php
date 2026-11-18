<?php

declare(strict_types=1);

namespace App\Features\ConsentManager\SaveConsentManager;

use App\Entities\ConsentManager\ConsentButtons;

/**
 * Extracts optional layout, custom categories and external tags from consent form inputs.
 */
final class ConsentFormExtras
{
    /**
     * @param array<string, mixed> $form
     * @return array<string, mixed>
     */
    public static function extract(array $form): array
    {
        $extras = [];
        if (array_key_exists('layout', $form)) {
            $extras['layout'] = in_array(
                $form['layout'],
                ['', ConsentButtons::DEFAULT_LAYOUT],
                true,
            ) ? null : $form['layout'];
        }
        if (array_key_exists('categories', $form)) {
            $extras['categories'] = self::categoryNames($form['categories']);
        }
        if (array_key_exists('external_tags', $form)) {
            $extras['external_tags'] = self::externalTagRows($form['external_tags']);
        }

        return $extras;
    }

    private static function categoryNames(mixed $value): ?array
    {
        if (!is_string($value) || strlen($value) > 1024) {
            throw new \InvalidArgumentException(
                'Additional categories must be a short list of names. Nothing was saved.',
            );
        }
        $names = array_values(array_unique(array_filter(
            preg_split('/[\s,]+/', trim($value)) ?: [],
            static fn (string $name): bool => $name !== '',
        )));

        return $names === [] ? null : $names;
    }

    private static function externalTagRows(mixed $rows): ?array
    {
        if (!is_array($rows) || count($rows) > 35) {
            throw new \InvalidArgumentException(
                'The other tags list was malformed. Nothing was saved.',
            );
        }
        $tags = [];
        foreach ($rows as $row) {
            if (!is_array($row)
                || array_diff(array_keys($row), ['id', 'name', 'category']) !== []
                || array_filter($row, 'is_string') !== $row
            ) {
                throw new \InvalidArgumentException(
                    'Each other tag needs an ID, a name and a category as text. '
                    .'Nothing was saved.',
                );
            }
            $tag = [
                'id' => trim($row['id'] ?? ''),
                'name' => trim($row['name'] ?? ''),
                'category' => trim($row['category'] ?? ''),
            ];
            if ($tag !== ['id' => '', 'name' => '', 'category' => '']) {
                $tags[] = $tag;
            }
        }

        return $tags === [] ? null : $tags;
    }
}
