<?php

declare(strict_types=1);

namespace App\Features\ConsentManager\SaveConsentManager;

use App\Entities\ConsentManager\ConsentTheme;

/**
 * Extracts and cleans form fields for consent manager settings.
 */
final class ConsentFormExtract
{
    /**
     * @param array<string, mixed> $submittedText
     * @param array<string, mixed> $saved
     * @param array<string, mixed> $defaults
     * @return array<string, mixed>
     */
    public static function text(array $submittedText, array $saved, array $defaults): array
    {
        $text = [];
        foreach ($submittedText as $key => $value) {
            if (!array_key_exists($key, $defaults)) {
                throw new \InvalidArgumentException(
                    'The consent manager form contained an unexpected text field. '
                    .'Nothing was saved.',
                );
            }
            if ($key === 'details') {
                $details = self::extractDetails($value, $defaults['details']);
                if ($details !== null) {
                    $text['details'] = $details;
                }
            } elseif ($key === 'categories') {
                $categories = self::extractCategories($value, $saved);
                if ($categories !== []) {
                    $text['categories'] = $categories;
                }
            } else {
                if (!is_string($value)) {
                    throw new \InvalidArgumentException(
                        'Banner wording must be text. Nothing was saved.',
                    );
                }
                if (trim($value) !== '' && trim($value) !== $defaults[$key]) {
                    $text[$key] = $value;
                }
            }
        }
        if (!array_key_exists('categories', $submittedText)
            && isset($saved['text']['categories'])
        ) {
            $text['categories'] = $saved['text']['categories'];
        }

        return $text;
    }

    private static function extractDetails(mixed $value, array $defaultDetails): ?array
    {
        if (!is_array($value) || !array_is_list($value)
            || count($value) > 4 || array_filter($value, 'is_string') !== $value
        ) {
            throw new \InvalidArgumentException(
                'Additional paragraphs must be text. Nothing was saved.',
            );
        }
        $paragraphs = array_values(array_filter(
            array_map('trim', $value),
            static fn (string $p): bool => $p !== '',
        ));

        return $paragraphs === $defaultDetails ? null : $paragraphs;
    }

    private static function extractCategories(mixed $value, array $saved): array
    {
        if (!is_array($value) || array_filter($value, 'is_string') !== $value) {
            throw new \InvalidArgumentException(
                'Category labels must be text. Nothing was saved.',
            );
        }
        $labels = $saved['text']['categories'] ?? [];
        foreach ($value as $category => $label) {
            unset($labels[$category]);
            if (trim($label) !== ''
                && trim($label) !== ConsentFormValues::defaultLabel((string) $category)
            ) {
                $labels[(string) $category] = $label;
            }
        }

        return $labels;
    }

    /**
     * @param array<string, mixed> $themeInput
     * @return array<string, string>
     */
    public static function theme(array $themeInput): array
    {
        $theme = [];
        foreach ($themeInput as $key => $value) {
            $default = ConsentTheme::THEME_DEFAULTS['builtin'][$key] ?? null;
            if ($default === null || ConsentTheme::normalizeHexColor($value) !== $default) {
                $theme[$key] = $value;
            }
        }

        return $theme;
    }

    /**
     * @param list<mixed> $precheckInput
     * @return list<string>
     */
    public static function precheck(array $precheckInput): array
    {
        $precheck = [];
        foreach ($precheckInput as $cat) {
            $cat = trim((string) $cat);
            if ($cat !== '' && !in_array($cat, $precheck, true)) {
                $precheck[] = $cat;
            }
        }

        return $precheck;
    }
}
