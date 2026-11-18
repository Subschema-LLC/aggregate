<?php

declare(strict_types=1);

namespace App\Entities\TagManager;

/**
 * Validates custom JavaScript code for custom tags.
 */
final class TagCustomCode
{
    public const MAX_BYTES = 20000;
    public const MAX_TOTAL_BYTES = 65536;
    public const FEATURE = 'custom_scripts';

    public static function validate(
        mixed $code,
        string $path,
        CustomCodeSyntax $syntax,
    ): string {
        $syntax->validateSyntax($code, $path);

        return is_string($code) ? rtrim(str_replace(["\r\n", "\r"], "\n", $code)) : '';
    }
}
