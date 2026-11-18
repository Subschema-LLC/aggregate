<?php

declare(strict_types=1);

namespace App\Integrations\TagManager;

use App\Entities\TagManager\CustomDataProperties;
use App\Service\CustomDataSettings;

/**
 * Connects TagManager property checks to CustomDataSettings.
 */
final class CustomDataSettingsProperties implements CustomDataProperties
{
    public function isValidKey(string $key): bool
    {
        return CustomDataSettings::isValidPropertyKey($key);
    }

    public function isBuiltin(string $key): bool
    {
        return isset(CustomDataSettings::BUILTIN_PROPERTIES[$key]);
    }
}
