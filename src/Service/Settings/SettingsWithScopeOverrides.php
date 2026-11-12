<?php

declare(strict_types=1);

namespace App\Service\Settings;

/**
 * A section a website or group can override, whose own values can also be
 * removed again so it follows the broader setting (app:config unset).
 */
interface SettingsWithScopeOverrides
{
    /**
     * Removes the website's or group's own values for these keys, with the
     * rest of the section the page saves them in.
     *
     * @param list<string> $keys
     *
     * @return string what happened
     */
    public function unset(array $keys, string $target, bool $check = false): string;
}
