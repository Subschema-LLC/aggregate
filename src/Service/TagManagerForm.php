<?php

declare(strict_types=1);

namespace App\Service;

use App\Features\TagManager\TagForm\TagForm as FeatureTagForm;

/**
 * Adapter delegating to App\Features\TagManager\TagForm\TagForm.
 */
final class TagManagerForm
{
    /**
     * @param array<string, mixed> $form
     * @param array<string, mixed> $settings
     * @param list<array>          $variableRows
     * @return array{0: array, 1: list<array>}
     */
    public static function draft(array $form, array $settings, array $variableRows): array
    {
        return FeatureTagForm::draft($form, $settings, $variableRows);
    }

    /** @param array<string, mixed> $row */
    public static function isBlankRow(array $row): bool
    {
        return FeatureTagForm::isBlankRow($row);
    }

    /**
     * @param array<string, mixed> $submitted
     * @return array{enabled: bool, variables: array<string, string>, tags: list<array>}
     */
    public static function settings(array $submitted): array
    {
        return FeatureTagForm::settings($submitted);
    }
}
