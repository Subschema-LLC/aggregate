<?php

declare(strict_types=1);

namespace App\Features\Cmp\ConsentForm;

use App\Entities\ConsentManager\ConsentButtons;
use App\Entities\ConsentManager\ConsentRegions;
use App\Entities\ConsentManager\ConsentTheme;

/**
 * Reconstructs form values from a rejected submission for the consent manager page.
 */
final class ConsentFormDraft
{
    /**
     * @param array<string, mixed> $submitted
     * @param array<string, mixed> $form
     * @return array<string, mixed>
     */
    public static function fromSubmission(array $submitted, array $form): array
    {
        $text = static function (mixed $val, string $fallback, int $limit = 4096): string {
            return is_string($val) ? substr($val, 0, $limit) : $fallback;
        };
        if (in_array($submitted['enabled'] ?? null, ['0', '1'], true)) {
            $form['enabled'] = $submitted['enabled'] === '1';
        }
        $form['name'] = $text($submitted['name'] ?? null, $form['name']);
        $form['privacy_policy_url'] = $text(
            $submitted['privacy_policy_url'] ?? null,
            $form['privacy_policy_url'],
        );
        if (array_key_exists('precheck_categories', $submitted)
            && is_array($submitted['precheck_categories'])
        ) {
            $form['precheck_categories'] = array_values(
                array_filter($submitted['precheck_categories'], 'is_string'),
            );
        }
        self::applyWording($submitted, $form, $text);
        self::applyAppearance($submitted, $form);

        return $form;
    }

    private static function applyWording(array $submitted, array &$form, \Closure $text): void
    {
        $wording = is_array($submitted['text'] ?? null) ? $submitted['text'] : [];
        foreach ($form['text'] as $key => $value) {
            $form['text'][$key] = $text($wording[$key] ?? null, $value);
        }
        $details = is_array($wording['details'] ?? null) ? array_values($wording['details']) : [];
        foreach ($form['details'] as $index => $value) {
            $form['details'][$index] = $text(
                $details[$index] ?? null,
                $details === [] ? $value : '',
            );
        }
        foreach ($form['categories'] as $category => $value) {
            $form['categories'][$category] = $text(
                $wording['categories'][$category] ?? null,
                $value,
            );
        }
    }

    private static function applyAppearance(array $submitted, array &$form): void
    {
        $theme = is_array($submitted['theme'] ?? null) ? $submitted['theme'] : [];
        foreach ($form['theme'] as $key => $value) {
            $form['theme'][$key] = ConsentTheme::normalizeHexColor($theme[$key] ?? null) ?? $value;
        }
        $buttons = is_array($submitted['buttons'] ?? null) ? $submitted['buttons'] : [];
        if (is_string($buttons['show'] ?? null)
            && array_key_exists($buttons['show'], ConsentFormValues::arrangements())
        ) {
            $form['show'] = $buttons['show'];
        }
        if (in_array($buttons['reopen'] ?? null, ConsentButtons::REOPEN_POSITIONS, true)) {
            $form['reopen'] = $buttons['reopen'];
        }
        self::applyRegionsAndBehavior($submitted, $form);
    }

    private static function applyRegionsAndBehavior(array $submitted, array &$form): void
    {
        if (array_key_exists('regions', $submitted) && is_array($submitted['regions'])) {
            $form['regions'] = array_values(array_filter($submitted['regions'], 'is_array'));
        }
        $form['default_region'] = trim((string) ($submitted['default_region'] ?? ''));
        if (in_array($submitted['consent_type'] ?? null, ConsentRegions::CONSENT_TYPES, true)) {
            $form['consent_type'] = $submitted['consent_type'];
        }
        if (in_array(
            $submitted['close_button'] ?? null,
            ['', ...ConsentRegions::CLOSE_BUTTONS],
            true,
        )) {
            $form['close_button'] = $submitted['close_button'];
        }
        if (is_array($submitted['default_granted'] ?? null)) {
            $form['default_granted'] = array_values(array_filter(
                $submitted['default_granted'],
                static fn (mixed $cat): bool => is_string($cat) && $cat !== '',
            ));
        }
        if (in_array($submitted['respect_gpc'] ?? null, ['0', '1'], true)) {
            $form['respect_gpc'] = $submitted['respect_gpc'] === '1';
        }
        if (in_array($submitted['layout'] ?? null, ConsentButtons::LAYOUTS, true)) {
            $form['layout'] = $submitted['layout'];
        }
        if (is_string($submitted['categories'] ?? null)) {
            $form['extra_categories'] = substr($submitted['categories'], 0, 1024);
        }
        self::applyExternalTags($submitted, $form);
    }

    private static function applyExternalTags(array $submitted, array &$form): void
    {
        if (!is_array($submitted['external_tags'] ?? null)) {
            return;
        }
        $form['external_tags'] = [];
        $validRows = array_slice(
            array_values(array_filter($submitted['external_tags'], 'is_array')),
            0,
            30,
        );
        foreach ($validRows as $row) {
            $tag = [
                'id' => is_string($row['id'] ?? null)
                    ? substr($row['id'], 0, 256)
                    : '',
                'name' => is_string($row['name'] ?? null)
                    ? substr($row['name'], 0, 256)
                    : '',
                'category' => is_string($row['category'] ?? null)
                    ? substr($row['category'], 0, 256)
                    : '',
            ];
            if (implode('', $tag) !== '') {
                $form['external_tags'][] = $tag;
            }
        }
    }
}
