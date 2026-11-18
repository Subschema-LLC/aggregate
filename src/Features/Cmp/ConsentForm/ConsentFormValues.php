<?php

declare(strict_types=1);

namespace App\Features\Cmp\ConsentForm;

use App\Entities\ConsentManager\ConsentButtons;
use App\Entities\ConsentManager\ConsentRegions;
use App\Entities\ConsentManager\ConsentText;
use App\Entities\ConsentManager\ConsentTheme;

/**
 * Prepares view values and draft reconstructions for the consent manager page.
 */
final class ConsentFormValues
{
    /**
     * @param array<string, mixed> $consent
     * @param list<string>         $categories
     * @return array<string, mixed>
     */
    public static function values(array $consent, array $categories): array
    {
        $defaults = ConsentText::DEFAULT_TEXT['builtin'];
        $text = [];
        foreach ($defaults as $key => $default) {
            if (is_string($default)) {
                $text[$key] = $consent['text'][$key] ?? $default;
            }
        }
        $labels = [];
        foreach ($categories as $category) {
            $labels[$category] = $consent['text']['categories'][$category]
                ?? self::defaultLabel($category);
        }
        $buttons = array_replace(
            ConsentButtons::BUTTON_DEFAULTS['builtin'],
            $consent['buttons'] ?? [],
        );

        return [
            'enabled' => $consent['enabled'], 'name' => $consent['name'],
            'privacy_policy_url' => $consent['privacy_policy_url'] ?? '',
            'precheck_categories' => $consent['precheck_categories'] ?? [],
            'text' => $text,
            'details' => array_pad($consent['text']['details'] ?? $defaults['details'], 4, ''),
            'categories' => $labels,
            'theme' => array_replace(
                ConsentTheme::THEME_DEFAULTS['builtin'],
                $consent['theme'] ?? [],
            ),
            'show' => implode(',', $buttons['show']),
            'reopen' => $buttons['reopen'],
            'regions' => $consent['regions'] ?? [],
            'default_region' => $consent['default_region'] ?? '',
            'consent_type' => $consent['consent_type'] ?? ConsentRegions::DEFAULT_CONSENT_TYPE,
            'close_button' => $consent['close_button'] ?? '',
            'default_granted' => $consent['default_granted'] ?? ConsentRegions::DEFAULT_GRANTED,
            'respect_gpc' => $consent['respect_gpc'] ?? true,
            'layout' => $consent['layout'] ?? ConsentButtons::DEFAULT_LAYOUT,
            'extra_categories' => implode(', ', $consent['categories'] ?? []),
            'external_tags' => $consent['external_tags'] ?? [],
            'category_tags' => self::tagNamesByCategory($consent['external_tags'] ?? []),
        ];
    }

    /**
     * @param list<array{id: string, name: string, category: string}> $tags
     * @return array<string, string>
     */
    private static function tagNamesByCategory(array $tags): array
    {
        $names = [];
        foreach ($tags as $tag) {
            $names[$tag['category']][] = $tag['name'];
        }

        return array_map(static fn (array $list): string => implode(', ', $list), $names);
    }

    /**
     * @param array<string, mixed> $submitted
     * @param array<string, mixed> $form
     * @return array<string, mixed>
     */
    public static function fromSubmission(array $submitted, array $form): array
    {
        return ConsentFormDraft::fromSubmission($submitted, $form);
    }

    /**
     * @return array<string, string>
     */
    public static function arrangements(): array
    {
        $permutations = static function (array $items) use (&$permutations): array {
            if (count($items) <= 1) {
                return [$items];
            }
            $result = [];
            foreach ($items as $index => $item) {
                $rest = $items;
                unset($rest[$index]);
                foreach ($permutations(array_values($rest)) as $tail) {
                    $result[] = [$item, ...$tail];
                }
            }

            return $result;
        };
        $options = [];
        $buttonNames = ConsentForm::BUTTON_NAMES;
        foreach ([['reject', 'accept', 'save'], ['reject', 'accept'], ['reject', 'save']] as $set) {
            foreach ($permutations($set) as $order) {
                $options[implode(',', $order)] = implode(
                    ' · ',
                    array_map(static fn (string $name): string => $buttonNames[$name], $order),
                );
            }
        }

        return $options;
    }

    public static function defaultLabel(string $category): string
    {
        return ConsentText::DEFAULT_TEXT['builtin']['categories'][$category]
            ?? ucfirst(str_replace(['-', '_'], ' ', $category));
    }
}
