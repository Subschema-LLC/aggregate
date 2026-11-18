<?php

declare(strict_types=1);

namespace App\Service;

use App\Features\ConsentManager\SaveConsentManager\ConsentForm;

/**
 * Adapter delegating to App\Features\Cmp\ConsentForm\ConsentForm.
 */
final class ConsentManagerForm
{
    public const BUTTON_NAMES = ConsentForm::BUTTON_NAMES;
    public const CONSENT_TYPE_LABELS = ConsentForm::CONSENT_TYPE_LABELS;
    public const CLOSE_BUTTON_LABELS = ConsentForm::CLOSE_BUTTON_LABELS;
    public const LAYOUT_LABELS = ConsentForm::LAYOUT_LABELS;

    /**
     * @param array<string, mixed> $form
     * @param array<string, mixed> $saved
     * @return array<string, mixed>
     */
    public static function changes(array $form, array $saved): array
    {
        return ConsentForm::changes($form, $saved);
    }

    /**
     * @param array<string, mixed> $consent
     * @param list<string>         $categories
     * @return array<string, mixed>
     */
    public static function values(array $consent, array $categories): array
    {
        return ConsentForm::values($consent, $categories);
    }

    /**
     * @param array<string, mixed> $submitted
     * @param array<string, mixed> $form
     * @return array<string, mixed>
     */
    public static function fromSubmission(array $submitted, array $form): array
    {
        return ConsentForm::fromSubmission($submitted, $form);
    }

    /**
     * @return array<string, string>
     */
    public static function arrangements(): array
    {
        return ConsentForm::arrangements();
    }

    public static function defaultLabel(string $category): string
    {
        return ConsentForm::defaultLabel($category);
    }
}
