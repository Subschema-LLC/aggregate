<?php

declare(strict_types=1);

namespace App\Service\Glossary;

use App\Service\ReportingViewManager;

/** Resolves declared metadata in PHP. Never queries events, archives or views. */
class GlossaryResolver
{
    public function __construct(
        private readonly BiGlossarySettings $settings,
        private readonly BuiltinGlossaryCatalog $catalog,
    ) {
    }

    /** @return list<array<string, mixed>> Full table rows except the write timestamp. */
    public function resolve(?array $settings = null): array
    {
        $settings = $settings === null ? $this->settings->get() : $this->settings->validate($settings);
        $dimensions = $this->settings->dimensions();
        foreach ($settings['values'] as $dimension => $codes) {
            $dimensions[$dimension] = array_values(array_unique([...$dimensions[$dimension], ...array_map('strval', array_keys($codes))]));
        }
        $goals = $this->settings->goals();
        $websites = $this->settings->websites();
        $descriptions = [];
        foreach ($this->settings->properties() as $definition) {
            foreach (['column', 'numeric_column'] as $field) {
                if (($definition[$field] ?? '') !== '' && $definition['description'] !== '') {
                    $descriptions[$definition[$field]] = $definition['description'];
                }
            }
        }
        $rows = [];
        foreach (['value' => $dimensions, 'column' => $this->settings->coveredColumns()] as $type => $subjects) {
            foreach ($subjects as $subject => $codes) {
                foreach ($codes as $index => $code) {
                    $sourceText = [];
                    if ($type === 'value' && $subject === 'goal_event') {
                        $sourceText['label'] = $goals[$code]['label'];
                    } elseif ($type === 'value' && $subject === 'website_token' && isset($websites[$code])) {
                        // A website's name and domain are default-locale text from websites.yaml.
                        $sourceText = array_filter(['label' => $websites[$code]['name'], 'description' => $websites[$code]['domain']], 'is_string');
                    } elseif ($type === 'column' && in_array($subject, ReportingViewManager::VIEW_NAMES, true) && isset($descriptions[$code])) {
                        $sourceText['description'] = $descriptions[$code];
                    }
                    $entry = $settings[$type === 'value' ? 'values' : 'columns'][$subject][$code] ?? [];
                    foreach ($settings['locales'] as $locale) {
                        [$label, $labelLocale, $source] = $this->field($type, $subject, $code, 'label', $locale, $settings['default_locale'], $entry, $sourceText);
                        [$description, $descriptionLocale] = $this->field($type, $subject, $code, 'description', $locale, $settings['default_locale'], $entry, $sourceText);
                        [$group] = $type === 'value'
                            ? $this->field($type, $subject, $code, 'group', $locale, $settings['default_locale'], $entry, $sourceText)
                            : [null];
                        $rows[] = [
                            'entry_type' => $type, 'subject' => $subject, 'code' => $code, 'locale' => $locale,
                            'label' => $label ?? $code, 'label_locale' => $labelLocale, 'group_label' => $group,
                            'description' => $description, 'description_locale' => $descriptionLocale,
                            'sort_order' => $entry['sort'] ?? (($index + 1) * 10),
                            'is_default_locale' => (int) ($locale === $settings['default_locale']),
                            'source' => $source ?? 'builtin',
                        ];
                    }
                }
            }
        }
        // Continents retain enum order; countries sort by each locale's own
        // resolved label. Stable code tie-breaking makes no-op sync deterministic.
        foreach ($settings['locales'] as $locale) {
            $countries = array_filter($rows, static fn (array $row): bool => $row['entry_type'] === 'value' && $row['subject'] === 'geo_area' && $row['locale'] === $locale && str_starts_with($row['code'], 'country:'));
            // Native Intl orders accented names in their locale's alphabet.
            // The bundled ICU polyfill supports fewer locales; keep resolution
            // usable on those prepared-release installations as well.
            try {
                $collator = new \Collator(str_replace('-', '_', $locale));
            } catch (\Throwable) {
                $collator = null;
            }
            uasort($countries, static function (array $a, array $b) use ($collator): int {
                $comparison = $collator?->compare($a['label'], $b['label']);

                return (is_int($comparison) ? $comparison : strcasecmp($a['label'], $b['label'])) ?: strcmp($a['code'], $b['code']);
            });
            $order = 80;
            foreach ($countries as $index => $row) {
                $rows[$index]['sort_order'] = $settings['values']['geo_area'][$row['code']]['sort'] ?? $order;
                $order += 10;
            }
        }

        return $rows;
    }

    private function field(string $type, string $subject, string $code, string $field, string $locale, string $default, array $entry, array $source): array
    {
        $chain = [$locale];
        while (str_contains($locale, '-')) {
            $locale = substr($locale, 0, strrpos($locale, '-'));
            $chain[] = $locale;
        }
        $chain[] = $default;
        $chain[] = 'en';
        foreach (array_unique($chain) as $candidate) {
            if (isset($entry[$field][$candidate])) {
                return [$entry[$field][$candidate], $candidate, 'glossary'];
            }
            if ($candidate === $default && isset($source[$field]) && trim($source[$field]) !== '') {
                return [trim($source[$field]), $candidate, 'config'];
            }
            $text = $this->catalog->text($type, $subject, $code, $field, $candidate);
            if ($text !== null && trim($text) !== '') {
                return [$text, $candidate, 'builtin'];
            }
        }

        return [null, null, null];
    }
}
