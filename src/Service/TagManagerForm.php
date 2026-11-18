<?php

declare(strict_types=1);

namespace App\Service;

use App\Entities\TagManager\CustomCodeSyntax;
use App\Entities\TagManager\CustomDataProperties;
use App\Features\TagManager\SaveTagManager\TagForm as FeatureTagForm;

/**
 * Adapter delegating to App\Features\TagManager\SaveTagManager\TagForm.
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
    public static function settings(
        array $submitted,
        CustomDataProperties $properties,
        CustomCodeSyntax $syntax,
    ): array {
        return FeatureTagForm::settings($submitted, $properties, $syntax);
    }
}
