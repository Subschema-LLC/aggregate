<?php

declare(strict_types=1);

namespace App\Entities\TagManager;

/**
 * Checks property names and builtin tracker properties for event tags.
 */
interface CustomDataProperties
{
    public function isValidKey(string $key): bool;

    public function isBuiltin(string $key): bool;
}
