<?php

declare(strict_types=1);

namespace App\Entities\TagManager;

/**
 * Validates custom JavaScript syntax and safety rules via AST analysis.
 */
interface CustomCodeSyntax
{
    public function validateSyntax(mixed $code, string $path): void;
}
