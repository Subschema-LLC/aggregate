<?php

declare(strict_types=1);

namespace App\Features\ConsentManager\SaveConsentManager;

use App\Entities\ConsentManager\ConsentText;

/**
 * Validates and converts dashboard form inputs into consent_manager mapping changes.
 */
final class ConsentForm
{
    public const BUTTON_NAMES = [
        'reject' => 'Reject', 'accept' => 'Accept all', 'save' => 'Save selection',
    ];
    public const CONSENT_TYPE_LABELS = [
        'implicit_us' => 'Implicit, US-style',
        'explicit_us' => 'Explicit, US-style',
        'explicit_eu' => 'Explicit, EU-style',
    ];
    public const CLOSE_BUTTON_LABELS = [
        'reject' => 'Reject all and close',
        'dismiss' => 'Close without a choice',
        'hidden' => 'Hide the close button',
    ];
    public const LAYOUT_LABELS = [
        'card' => 'Card in the corner',
        'bar' => 'Bar across the bottom of the window',
    ];

    /**
     * @param array<string, mixed> $form
     * @param array<string, mixed> $saved
     * @return array<string, mixed>
     */
    public static function changes(array $form, array $saved): array
    {
        self::assertValidStructure($form);
        $defaults = ConsentText::DEFAULT_TEXT['builtin'];
        $text = ConsentFormExtract::text($form['text'] ?? [], $saved, $defaults);
        $theme = ConsentFormExtract::theme($form['theme'] ?? []);
        $buttons = ConsentFormBehavior::buttons($form['buttons'] ?? []);
        // In this order, so a form with several problems reports the same one first.
        $extras = ConsentFormExtras::extract($form);
        $privacy = trim((string) ($form['privacy_policy_url'] ?? ''));
        $precheck = ConsentFormExtract::precheck($form['precheck_categories'] ?? []);
        $behavior = ConsentFormBehavior::behavior($form);
        $regions = ConsentFormRegions::regions($form, $saved);

        return [
            'enabled' => $form['enabled'] === '1', 'name' => $form['name'],
            'privacy_policy_url' => $privacy === '' ? null : $privacy,
            'precheck_categories' => $precheck === [] ? null : $precheck,
            'text' => $text === [] ? null : $text,
            'theme' => $theme === [] ? null : $theme,
            'buttons' => $buttons === [] ? null : $buttons,
            'regions' => $regions === [] ? null : $regions,
            'default_region' => isset($form['default_region'])
                && trim((string) $form['default_region']) !== ''
                ? trim((string) $form['default_region'])
                : null,
        ] + $behavior + $extras;
    }

    private static function assertValidStructure(array $form): void
    {
        $allowed = [
            '_csrf_token', 'site', 'enabled', 'name', 'privacy_policy_url',
            'precheck_categories', 'text', 'theme', 'buttons', 'regions',
            'default_region', 'consent_type', 'close_button', 'default_granted',
            'respect_gpc', 'layout', 'categories', 'external_tags',
        ];
        $precheck = $form['precheck_categories'] ?? null;
        $precheckOk = $precheck === null || (is_array($precheck)
            && array_filter($precheck, 'is_string') === $precheck);
        if (array_diff(array_keys($form), $allowed) !== []
            || !in_array($form['enabled'] ?? null, ['0', '1'], true)
            || !is_string($form['name'] ?? null)
            || !is_string($form['privacy_policy_url'] ?? '')
            || !$precheckOk
            || !is_array($form['text'] ?? [])
            || !is_array($form['theme'] ?? [])
            || !is_array($form['buttons'] ?? [])
        ) {
            throw new \InvalidArgumentException(
                'The consent manager form contained missing or unexpected settings. '
                .'Nothing was saved.',
            );
        }
    }

    public static function values(array $consent, array $categories): array
    {
        return ConsentFormValues::values($consent, $categories);
    }

    public static function fromSubmission(array $submitted, array $form): array
    {
        return ConsentFormValues::fromSubmission($submitted, $form);
    }

    public static function arrangements(): array
    {
        return ConsentFormValues::arrangements();
    }

    public static function defaultLabel(string $category): string
    {
        return ConsentFormValues::defaultLabel($category);
    }
}
