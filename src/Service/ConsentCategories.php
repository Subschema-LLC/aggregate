<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The categories a consent banner asks about. The built-in banner always asks
 * about analytics, then the categories the operator lists in consent_manager,
 * those of the website's other tags (external_tags), and those its enabled
 * tag-manager tags use. Only the visitor's explicit choice, or an implicit_us
 * default grant, allows any of them.
 */
final class ConsentCategories
{
    /**
     * @param list<string> $tagManagerCategories TagManagerSettings::consentCategories()
     * @param array $consent A validated consent_manager mapping
     * @return list<string>
     */
    public static function builtin(array $tagManagerCategories, array $consent): array
    {
        $categories = ['analytics'];
        foreach ([$consent['categories'] ?? [], ConsentAppearance::externalTagCategories($consent), $tagManagerCategories] as $source) {
            foreach ($source as $category) {
                if (is_string($category) && $category !== 'none' && !in_array($category, $categories, true)) {
                    $categories[] = $category;
                }
            }
        }

        return $categories;
    }
}
