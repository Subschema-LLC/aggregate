<?php

declare(strict_types=1);

namespace App\Service;

use App\Entities\ConsentManager\ConsentCategories as EntityConsentCategories;

/**
 * Adapter delegating to App\Entities\ConsentManager\ConsentCategories.
 */
final class ConsentCategories
{
    /**
     * @param list<string>         $tagManagerCategories
     * @param array<string, mixed> $consent
     * @return list<string>
     */
    public static function builtin(array $tagManagerCategories, array $consent): array
    {
        return EntityConsentCategories::builtin($tagManagerCategories, $consent);
    }
}
