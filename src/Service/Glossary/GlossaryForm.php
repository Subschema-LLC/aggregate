<?php

declare(strict_types=1);

namespace App\Service\Glossary;

/**
 * The BI glossary page's form: applies one submitted entry, reset or locale
 * change to the current settings, and lays out the page's rows. Only the
 * dashboard uses it; YAML and the command line go to BiGlossarySettings
 * directly, which validates the result either way.
 */
final class GlossaryForm
{
    public function __construct(private readonly BiGlossarySettings $settings)
    {
    }

    /**
     * The glossary settings with the form applied, not yet validated.
     *
     * @throws \InvalidArgumentException when the form is not one this page sends
     */
    public function apply(array $form): array
    {
        $allowed = ['_csrf_token', 'action', 'locale', 'default_locale', 'locales', 'entry_type', 'subject', 'code', 'label', 'group', 'description', 'sort'];
        if (array_diff(array_keys($form), $allowed) !== [] || array_filter($form, static fn (mixed $value): bool => !is_string($value)) !== []) {
            throw new \InvalidArgumentException('Invalid glossary form. Nothing was saved.');
        }
        $settings = $this->settings->get();
        if (($form['action'] ?? null) === 'locales') {
            $settings['default_locale'] = $form['default_locale'] ?? '';
            $settings['locales'] = preg_split('/[\s,]+/', trim($form['locales'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);

            return $settings;
        }
        if (!in_array($form['action'] ?? null, ['entry', 'reset'], true)
            || !in_array($form['entry_type'] ?? null, ['value', 'column'], true)
            || !in_array($form['locale'] ?? null, $settings['locales'], true)) {
            throw new \InvalidArgumentException('Choose a glossary entry and a published locale. Nothing was saved.');
        }
        $type = $form['entry_type'] === 'value' ? 'values' : 'columns';
        $subject = $form['subject'] ?? '';
        $code = trim($form['code'] ?? '');
        $locale = $form['locale'];
        if ($form['action'] === 'reset') {
            unset($settings[$type][$subject][$code]);
            if (($settings[$type][$subject] ?? []) === []) {
                unset($settings[$type][$subject]);
            }

            return $settings;
        }
        $entry = $settings[$type][$subject][$code] ?? [];
        foreach (['label', 'group', 'description'] as $field) {
            if ($field === 'group' && $type === 'columns') {
                continue;
            }
            if ($field === 'description' && $type === 'columns'
                && $locale === $settings['default_locale'] && $this->property($subject, $code) !== null) {
                continue;
            }
            $texts = $entry[$field] ?? [];
            if (is_string($texts)) {
                $texts = [$settings['default_locale'] => $texts];
            }
            $value = $form[$field] ?? '';
            if (trim($value) === '') {
                unset($texts[$locale]);
            } else {
                $texts[$locale] = $value;
            }
            if ($texts === []) {
                unset($entry[$field]);
            } else {
                $entry[$field] = $texts;
            }
        }
        $sort = trim($form['sort'] ?? '');
        if ($sort === '') {
            unset($entry['sort']);
        } else {
            // Keep invalid input as-is so the shared validator identifies its field.
            $entry['sort'] = preg_match('/^\d{1,6}$/D', $sort) === 1 ? (int) $sort : $sort;
        }
        $settings[$type][$subject][$code] = $entry;

        return $settings;
    }

    /**
     * The page's rows for one locale, grouped by entry type and subject: what
     * is published now, the default-locale text, and the text to edit. With a
     * rejected form, its row shows the text as typed and stays open.
     *
     * @param array $resolved rows from GlossaryResolver grouped by type, subject, code and locale; see grouped()
     * @param list<string> $dimensions value dimensions listed even before they have entries
     * @param array|null $submitted the rejected form, or null
     */
    public function entries(array $resolved, array $settings, string $locale, array $dimensions, ?array $submitted): array
    {
        $entries = ['values' => array_fill_keys($dimensions, []), 'columns' => []];
        // Keep an unsaved declared-code row editable after validation fails.
        if ($submitted !== null && ($submitted['entry_type'] ?? null) === 'value'
            && is_string($submitted['subject'] ?? null) && array_key_exists($submitted['subject'], $entries['values'])
            && is_string($submitted['code'] ?? null) && strlen($submitted['code']) <= 191) {
            $resolved['values'][$submitted['subject']][$submitted['code']] ??= [];
        }
        foreach ($resolved as $type => $subjects) {
            foreach ($subjects as $subject => $codes) {
                foreach ($codes as $code => $translations) {
                    $current = $translations[$locale] ?? ['label' => (string) $code, 'label_locale' => null, 'description' => null, 'group_label' => null, 'sort_order' => 0];
                    $default = $translations[$settings['default_locale']] ?? $current;
                    $entry = $settings[$type][$subject][$code] ?? [];
                    $input = [];
                    foreach (['label', 'group', 'description'] as $field) {
                        $text = $entry[$field] ?? [];
                        $input[$field] = is_array($text) ? ($text[$locale] ?? '') : ($locale === $settings['default_locale'] ? $text : '');
                    }
                    $input['sort'] = $entry['sort'] ?? '';
                    $isSubmitted = $submitted !== null && ($submitted['entry_type'] ?? null) === ($type === 'values' ? 'value' : 'column')
                        && ($submitted['subject'] ?? null) === $subject && ($submitted['code'] ?? null) === (string) $code;
                    if ($isSubmitted) {
                        foreach (array_keys($input) as $field) {
                            $input[$field] = is_string($submitted[$field] ?? null) ? $submitted[$field] : '';
                        }
                    }
                    $property = $type === 'columns' ? $this->property($subject, (string) $code) : null;
                    $entries[$type][$subject][] = [
                        'code' => (string) $code, 'current' => $current, 'default' => $default, 'input' => $input,
                        'source' => match ($default['source'] ?? 'builtin') {
                            'config' => match (true) { $type === 'columns' => 'Data model', $subject === 'website_token' => 'Websites', default => 'goals.yaml' },
                            'glossary' => 'Glossary',
                            default => 'Built-in',
                        },
                        'property' => $property, 'overridden' => isset($settings[$type][$subject][$code]),
                        'unsaved' => $translations === [], 'open' => $isSubmitted || $translations === [],
                    ];
                }
            }
        }

        return $entries;
    }

    /** GlossaryResolver rows by type ('values' or 'columns'), subject, code and locale. */
    public static function grouped(array $rows): array
    {
        $resolved = [];
        foreach ($rows as $row) {
            $type = $row['entry_type'] === 'value' ? 'values' : 'columns';
            $resolved[$type][$row['subject']][$row['code']][$row['locale']] = $row;
        }

        return $resolved;
    }

    /** The custom property behind a column of an analytics_custom_* view. */
    public function property(string $view, string $column): ?array
    {
        if (!str_starts_with($view, 'analytics_custom_')) {
            return null;
        }
        foreach ($this->settings->properties() as $key => $property) {
            if (($property['column'] ?? '') === $column || ($property['numeric_column'] ?? '') === $column) {
                return ['key' => $key, ...$property];
            }
        }

        return null;
    }

    /** Whether administrators may declare new codes for a dimension; built-in dimensions are fixed. */
    public static function allowsNewCode(string $dimension): bool
    {
        return $dimension === 'event_name' || !in_array($dimension, ['goal_event', 'referrer_channel', 'device_class', 'viewport_bucket', 'geo_area', 'geo_level', 'privacy_mode'], true);
    }
}
