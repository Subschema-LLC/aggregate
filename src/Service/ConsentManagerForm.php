<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The Consent manager lite page's form, as plain arrays: the values it shows,
 * the consent_manager changes a submission makes, and a rejected submission
 * shown again. SiteScriptConfig and ConsentAppearance validate the changes,
 * as they do YAML edits, so this class only maps fields.
 */
final class ConsentManagerForm
{
    public const BUTTON_NAMES = ['reject' => 'Reject', 'accept' => 'Accept all', 'save' => 'Save selection'];
    /** Labels for ConsentAppearance::CONSENT_TYPES, in the order the form lists them. */
    public const CONSENT_TYPE_LABELS = [
        'implicit_us' => 'Implicit, US-style',
        'explicit_us' => 'Explicit, US-style',
        'explicit_eu' => 'Explicit, EU-style',
    ];
    /** Labels for ConsentAppearance::CLOSE_BUTTONS. */
    public const CLOSE_BUTTON_LABELS = [
        'reject' => 'Reject all and close',
        'dismiss' => 'Close without a choice',
        'hidden' => 'Hide the close button',
    ];
    /** Labels for ConsentAppearance::LAYOUTS. */
    public const LAYOUT_LABELS = [
        'card' => 'Card in the corner',
        'bar' => 'Bar across the bottom of the window',
    ];

    /**
     * Turns the page's fields into consent_manager changes. A value equal to
     * its default is not stored, so later default wording still applies, and
     * null removes a key that now matches its default.
     */
    public static function changes(array $form, array $saved): array
    {
        if (array_diff(array_keys($form), ['_csrf_token', 'site', 'enabled', 'name', 'privacy_policy_url', 'precheck_categories', 'text', 'theme', 'buttons', 'regions', 'default_region',
            'consent_type', 'close_button', 'default_granted', 'respect_gpc', 'layout', 'categories', 'external_tags']) !== []
            || !in_array($form['enabled'] ?? null, ['0', '1'], true) || !is_string($form['name'] ?? null)
            || !is_string($form['privacy_policy_url'] ?? '')
            || (array_key_exists('precheck_categories', $form) && (!is_array($form['precheck_categories']) || array_filter($form['precheck_categories'], 'is_string') !== $form['precheck_categories']))
            || !is_array($form['text'] ?? []) || !is_array($form['theme'] ?? []) || !is_array($form['buttons'] ?? [])) {
            throw new \InvalidArgumentException('The consent manager form contained missing or unexpected settings. Nothing was saved.');
        }
        $defaults = ConsentAppearance::DEFAULT_TEXT[ConsentAppearance::BUILTIN];
        $submittedText = $form['text'] ?? [];
        $text = [];
        foreach ($submittedText as $key => $value) {
            if (!is_string($key) || !array_key_exists($key, $defaults)) {
                throw new \InvalidArgumentException('The consent manager form contained an unexpected text field. Nothing was saved.');
            }
            if ($key === 'details') {
                if (!is_array($value) || !array_is_list($value) || count($value) > ConsentAppearance::MAX_DETAILS || array_filter($value, 'is_string') !== $value) {
                    throw new \InvalidArgumentException('Additional paragraphs must be text. Nothing was saved.');
                }
                $paragraphs = array_values(array_filter(array_map('trim', $value), static fn (string $paragraph): bool => $paragraph !== ''));
                if ($paragraphs !== $defaults['details']) {
                    $text['details'] = $paragraphs;
                }
            } elseif ($key === 'categories') {
                if (!is_array($value) || array_filter($value, 'is_string') !== $value) {
                    throw new \InvalidArgumentException('Category labels must be text. Nothing was saved.');
                }
                // Keep labels for categories whose tags are currently disabled.
                $labels = $saved['text']['categories'] ?? [];
                foreach ($value as $category => $label) {
                    unset($labels[$category]);
                    if (trim($label) !== '' && trim($label) !== self::defaultLabel((string) $category)) {
                        $labels[(string) $category] = $label;
                    }
                }
                if ($labels !== []) {
                    $text['categories'] = $labels;
                }
            } else {
                if (!is_string($value)) {
                    throw new \InvalidArgumentException('Banner wording must be text. Nothing was saved.');
                }
                if (trim($value) !== '' && trim($value) !== $defaults[$key]) {
                    $text[$key] = $value;
                }
            }
        }
        if (!array_key_exists('categories', $submittedText) && isset($saved['text']['categories'])) {
            $text['categories'] = $saved['text']['categories'];
        }

        $theme = [];
        foreach ($form['theme'] ?? [] as $key => $value) {
            $default = ConsentAppearance::THEME_DEFAULTS[ConsentAppearance::BUILTIN][$key] ?? null;
            // Unknown keys and invalid colors reach validation for a precise message.
            if ($default === null || BrandingTheme::normalizeHexColor($value) !== $default) {
                $theme[$key] = $value;
            }
        }

        $buttons = [];
        $defaultButtons = ConsentAppearance::BUTTON_DEFAULTS[ConsentAppearance::BUILTIN];
        $show = $form['buttons']['show'] ?? implode(',', $defaultButtons['show']);
        if (!is_string($show) || !array_key_exists($show, self::arrangements())) {
            throw new \InvalidArgumentException('Choose one of the listed button arrangements. Nothing was saved.');
        }
        if ($show !== implode(',', $defaultButtons['show'])) {
            $buttons['show'] = explode(',', $show);
        }
        $reopen = $form['buttons']['reopen'] ?? $defaultButtons['reopen'];
        if ($reopen !== $defaultButtons['reopen']) {
            $buttons['reopen'] = $reopen;
        }
        if (array_diff(array_keys($form['buttons'] ?? []), ['show', 'reopen']) !== []) {
            throw new \InvalidArgumentException('The consent manager form contained an unexpected button setting. Nothing was saved.');
        }

        $privacy = trim($form['privacy_policy_url'] ?? '');

        // Layout and the website's categories and other tags. A field the form did
        // not send keeps its saved value; unknown values reach validation.
        $extras = [];
        if (array_key_exists('layout', $form)) {
            $extras['layout'] = in_array($form['layout'], ['', ConsentAppearance::DEFAULT_LAYOUT], true) ? null : $form['layout'];
        }
        if (array_key_exists('categories', $form)) {
            $extras['categories'] = self::categoryNames($form['categories']);
        }
        if (array_key_exists('external_tags', $form)) {
            $extras['external_tags'] = self::externalTagRows($form['external_tags']);
        }

        $precheck = [];
        foreach ($form['precheck_categories'] ?? [] as $cat) {
            $cat = trim($cat);
            if ($cat !== '' && !in_array($cat, $precheck, true)) {
                $precheck[] = $cat;
            }
        }

        // Consent behavior. A field the form did not send keeps its saved value;
        // a value equal to its default is removed. Unknown values reach validation.
        $behavior = [];
        if (array_key_exists('consent_type', $form)) {
            $behavior['consent_type'] = $form['consent_type'] === ConsentAppearance::DEFAULT_CONSENT_TYPE ? null : $form['consent_type'];
        }
        if (array_key_exists('close_button', $form)) {
            $behavior['close_button'] = $form['close_button'] === '' ? null : $form['close_button'];
        }
        if (array_key_exists('default_granted', $form)) {
            $granted = self::submittedCategories($form['default_granted'], 'Default-on categories');
            $sorted = $granted;
            sort($sorted);
            $behavior['default_granted'] = $sorted === ConsentAppearance::DEFAULT_GRANTED ? null : $granted;
        }
        if (array_key_exists('respect_gpc', $form)) {
            if (!in_array($form['respect_gpc'], ['0', '1'], true)) {
                throw new \InvalidArgumentException('The Global Privacy Control setting must be on or off. Nothing was saved.');
            }
            $behavior['respect_gpc'] = $form['respect_gpc'] === '1' ? null : false;
        }

        $regions = [];
        $savedRegions = array_column($saved['regions'] ?? [], null, 'id');
        if (isset($form['regions']) && is_array($form['regions'])) {
            foreach ($form['regions'] as $reg) {
                if (!is_array($reg)) continue;
                $id = trim((string) ($reg['id'] ?? ''));
                $name = trim((string) ($reg['name'] ?? ''));
                if ($id === '' && $name === '') continue;
                $regItem = [
                    'id' => $id,
                    'name' => $name,
                    // An unknown type reaches validation, which names the allowed ones.
                    'consent_type' => is_string($reg['consent_type'] ?? null) && $reg['consent_type'] !== '' ? $reg['consent_type'] : ConsentAppearance::DEFAULT_CONSENT_TYPE,
                    'default' => ($reg['default'] ?? null) === '1' || ($reg['default'] ?? null) === true,
                ];
                // The form shows only some region wording. Keep the region's other
                // YAML wording (details, close labels...) and its buttons as saved.
                $previous = $savedRegions[$id] ?? [];
                $submittedRegionText = isset($reg['text']) && is_array($reg['text']) ? $reg['text'] : [];
                $regText = $previous['text'] ?? [];
                foreach ($submittedRegionText as $tk => $tv) {
                    if (is_string($tv) && trim($tv) !== '') {
                        $regText[$tk] = trim($tv);
                    } else {
                        unset($regText[$tk]);
                    }
                }
                if ($regText !== []) {
                    $regItem['text'] = $regText;
                }
                if (isset($previous['buttons'])) {
                    $regItem['buttons'] = $previous['buttons'];
                }
                if (($reg['close_button'] ?? '') !== '') {
                    $regItem['close_button'] = $reg['close_button'];
                }
                if (($reg['default_granted_override'] ?? null) === '1') {
                    $regItem['default_granted'] = self::submittedCategories($reg['default_granted'] ?? [], 'Region default-on categories');
                }
                $regions[] = $regItem;
            }
        }

        return [
            'enabled' => $form['enabled'] === '1', 'name' => $form['name'],
            'privacy_policy_url' => $privacy === '' ? null : $privacy,
            'precheck_categories' => $precheck === [] ? null : $precheck,
            'text' => $text === [] ? null : $text,
            'theme' => $theme === [] ? null : $theme,
            'buttons' => $buttons === [] ? null : $buttons,
            'regions' => $regions === [] ? null : $regions,
            'default_region' => isset($form['default_region']) && trim((string) $form['default_region']) !== '' ? trim((string) $form['default_region']) : null,
        ] + $behavior + $extras;
    }

    /** Additional categories typed as a comma- or space-separated list; null for none. */
    private static function categoryNames(mixed $value): ?array
    {
        if (!is_string($value) || strlen($value) > 1024) {
            throw new \InvalidArgumentException('Additional categories must be a short list of names. Nothing was saved.');
        }
        $names = array_values(array_unique(array_filter(preg_split('/[\s,]+/', trim($value)) ?: [], static fn (string $name): bool => $name !== '')));

        return $names === [] ? null : $names;
    }

    /**
     * The other-tags rows; a row whose fields are all empty is skipped, so
     * clearing a row removes that tag. Null for none.
     */
    private static function externalTagRows(mixed $rows): ?array
    {
        if (!is_array($rows) || count($rows) > ConsentAppearance::MAX_EXTERNAL_TAGS + 5) {
            throw new \InvalidArgumentException('The other tags list was malformed. Nothing was saved.');
        }
        $tags = [];
        foreach ($rows as $row) {
            if (!is_array($row) || array_diff(array_keys($row), ['id', 'name', 'category']) !== []
                || array_filter($row, 'is_string') !== $row) {
                throw new \InvalidArgumentException('Each other tag needs an ID, a name and a category as text. Nothing was saved.');
            }
            $tag = ['id' => trim($row['id'] ?? ''), 'name' => trim($row['name'] ?? ''), 'category' => trim($row['category'] ?? '')];
            if ($tag !== ['id' => '', 'name' => '', 'category' => '']) {
                $tags[] = $tag;
            }
        }

        return $tags === [] ? null : $tags;
    }

    /** Checked category boxes; an empty value is the marker that makes "none" submittable. */
    private static function submittedCategories(mixed $value, string $label): array
    {
        if (!is_array($value) || array_filter($value, 'is_string') !== $value) {
            throw new \InvalidArgumentException($label.' must be category names. Nothing was saved.');
        }

        return array_values(array_unique(array_filter(array_map('trim', $value), static fn (string $category): bool => $category !== '')));
    }

    /** Effective values for every field: saved overrides, otherwise defaults. */
    public static function values(array $consent, array $categories): array
    {
        $defaults = ConsentAppearance::DEFAULT_TEXT[ConsentAppearance::BUILTIN];
        $text = [];
        foreach ($defaults as $key => $default) {
            if (is_string($default)) {
                $text[$key] = $consent['text'][$key] ?? $default;
            }
        }
        $labels = [];
        foreach ($categories as $category) {
            $labels[$category] = $consent['text']['categories'][$category] ?? self::defaultLabel($category);
        }
        $buttons = array_replace(ConsentAppearance::BUTTON_DEFAULTS[ConsentAppearance::BUILTIN], $consent['buttons'] ?? []);

        return [
            'enabled' => $consent['enabled'], 'name' => $consent['name'],
            'privacy_policy_url' => $consent['privacy_policy_url'] ?? '',
            'precheck_categories' => $consent['precheck_categories'] ?? [],
            'text' => $text,
            'details' => array_pad($consent['text']['details'] ?? $defaults['details'], ConsentAppearance::MAX_DETAILS, ''),
            'categories' => $labels,
            'theme' => array_replace(ConsentAppearance::THEME_DEFAULTS[ConsentAppearance::BUILTIN], $consent['theme'] ?? []),
            'show' => implode(',', $buttons['show']),
            'reopen' => $buttons['reopen'],
            'regions' => $consent['regions'] ?? [],
            'default_region' => $consent['default_region'] ?? '',
            'consent_type' => $consent['consent_type'] ?? ConsentAppearance::DEFAULT_CONSENT_TYPE,
            'close_button' => $consent['close_button'] ?? '',
            'default_granted' => $consent['default_granted'] ?? ConsentAppearance::DEFAULT_GRANTED,
            'respect_gpc' => $consent['respect_gpc'] ?? true,
            'layout' => $consent['layout'] ?? ConsentAppearance::DEFAULT_LAYOUT,
            'extra_categories' => implode(', ', $consent['categories'] ?? []),
            'external_tags' => $consent['external_tags'] ?? [],
            'category_tags' => self::tagNamesByCategory($consent['external_tags'] ?? []),
        ];
    }

    /** @return array<string, string> Category => its external tags' names, comma-separated, for the preview. */
    private static function tagNamesByCategory(array $tags): array
    {
        $names = [];
        foreach ($tags as $tag) {
            $names[$tag['category']][] = $tag['name'];
        }

        return array_map(static fn (array $list): string => implode(', ', $list), $names);
    }

    /** The rejected form exactly as typed, bounded, over the effective values. */
    public static function fromSubmission(array $submitted, array $form): array
    {
        $text = static fn (mixed $value, string $fallback, int $limit = 4096): string => is_string($value) ? substr($value, 0, $limit) : $fallback;
        if (in_array($submitted['enabled'] ?? null, ['0', '1'], true)) {
            $form['enabled'] = $submitted['enabled'] === '1';
        }
        $form['name'] = $text($submitted['name'] ?? null, $form['name']);
        $form['privacy_policy_url'] = $text($submitted['privacy_policy_url'] ?? null, $form['privacy_policy_url']);
        if (array_key_exists('precheck_categories', $submitted) && is_array($submitted['precheck_categories'])) {
            $form['precheck_categories'] = array_values(array_filter($submitted['precheck_categories'], 'is_string'));
        }
        $wording = is_array($submitted['text'] ?? null) ? $submitted['text'] : [];
        foreach ($form['text'] as $key => $value) {
            $form['text'][$key] = $text($wording[$key] ?? null, $value);
        }
        $details = is_array($wording['details'] ?? null) ? array_values($wording['details']) : [];
        foreach ($form['details'] as $index => $value) {
            $form['details'][$index] = $text($details[$index] ?? null, $details === [] ? $value : '');
        }
        foreach ($form['categories'] as $category => $value) {
            $form['categories'][$category] = $text($wording['categories'][$category] ?? null, $value);
        }
        $theme = is_array($submitted['theme'] ?? null) ? $submitted['theme'] : [];
        foreach ($form['theme'] as $key => $value) {
            // Color inputs can only display valid colors.
            $form['theme'][$key] = BrandingTheme::normalizeHexColor($theme[$key] ?? null) ?? $value;
        }
        $buttons = is_array($submitted['buttons'] ?? null) ? $submitted['buttons'] : [];
        if (is_string($buttons['show'] ?? null) && array_key_exists($buttons['show'], self::arrangements())) {
            $form['show'] = $buttons['show'];
        }
        if (in_array($buttons['reopen'] ?? null, ConsentAppearance::REOPEN_POSITIONS, true)) {
            $form['reopen'] = $buttons['reopen'];
        }
        if (array_key_exists('regions', $submitted) && is_array($submitted['regions'])) {
            $form['regions'] = array_values(array_filter($submitted['regions'], 'is_array'));
        }
        $form['default_region'] = trim((string) ($submitted['default_region'] ?? ''));
        if (in_array($submitted['consent_type'] ?? null, ConsentAppearance::CONSENT_TYPES, true)) {
            $form['consent_type'] = $submitted['consent_type'];
        }
        if (in_array($submitted['close_button'] ?? null, ['', ...ConsentAppearance::CLOSE_BUTTONS], true)) {
            $form['close_button'] = $submitted['close_button'];
        }
        if (is_array($submitted['default_granted'] ?? null)) {
            $form['default_granted'] = array_values(array_filter($submitted['default_granted'], static fn (mixed $category): bool => is_string($category) && $category !== ''));
        }
        if (in_array($submitted['respect_gpc'] ?? null, ['0', '1'], true)) {
            $form['respect_gpc'] = $submitted['respect_gpc'] === '1';
        }
        if (in_array($submitted['layout'] ?? null, ConsentAppearance::LAYOUTS, true)) {
            $form['layout'] = $submitted['layout'];
        }
        $form['extra_categories'] = $text($submitted['categories'] ?? null, $form['extra_categories'], 1024);
        if (is_array($submitted['external_tags'] ?? null)) {
            $form['external_tags'] = [];
            foreach (array_slice(array_values(array_filter($submitted['external_tags'], 'is_array')), 0, ConsentAppearance::MAX_EXTERNAL_TAGS) as $row) {
                $tag = [];
                foreach (['id', 'name', 'category'] as $field) {
                    $tag[$field] = $text($row[$field] ?? null, '', 256);
                }
                if (implode('', $tag) !== '') {
                    $form['external_tags'][] = $tag;
                }
            }
        }

        return $form;
    }

    /** @return array<string, string> Every valid button list, as "reject,accept,save" => "Reject · Accept all · Save selection". */
    public static function arrangements(): array
    {
        $permutations = static function (array $items) use (&$permutations): array {
            if (count($items) <= 1) return [$items];
            $result = [];
            foreach ($items as $index => $item) {
                $rest = $items;
                unset($rest[$index]);
                foreach ($permutations(array_values($rest)) as $tail) $result[] = [$item, ...$tail];
            }

            return $result;
        };
        $options = [];
        foreach ([['reject', 'accept', 'save'], ['reject', 'accept'], ['reject', 'save']] as $set) {
            foreach ($permutations($set) as $order) {
                $options[implode(',', $order)] = implode(' · ', array_map(static fn (string $name): string => self::BUTTON_NAMES[$name], $order));
            }
        }

        return $options;
    }

    /** The banner script's label for a category without a configured label. */
    public static function defaultLabel(string $category): string
    {
        return ConsentAppearance::DEFAULT_TEXT[ConsentAppearance::BUILTIN]['categories'][$category]
            ?? ucfirst(str_replace(['-', '_'], ' ', $category));
    }
}
