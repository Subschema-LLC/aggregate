<?php

declare(strict_types=1);

namespace App\Entities\TagManager;

/**
 * Validates and wraps custom JavaScript code for custom tags.
 */
final class TagCustomCode
{
    public const MAX_BYTES = 20000;
    public const MAX_TOTAL_BYTES = 65536;
    public const FEATURE = 'custom_scripts';

    private const PREFIX = "(function (tag) {\n'use strict';\n";
    private const SUFFIX = "\n})";

    public static function validate(
        mixed $code,
        string $path,
        ?CustomCodeSyntax $syntax = null,
        ?string $cacheDirectory = null,
    ): string {
        if (!is_string($code) || preg_match('//u', $code) !== 1) {
            throw new \InvalidArgumentException($path.' must be JavaScript text in UTF-8.');
        }
        $code = rtrim(str_replace(["\r\n", "\r"], "\n", $code));
        if (trim($code) === '') {
            throw new \InvalidArgumentException(
                $path.' is empty. Write the JavaScript to run, or remove the tag.',
            );
        }
        if (strlen($code) > self::MAX_BYTES) {
            throw new \InvalidArgumentException(sprintf(
                '%s is %s bytes; custom JavaScript allows at most %s bytes per tag. '
                .'Load larger code with tag.loadScript().',
                $path,
                number_format(strlen($code)),
                number_format(self::MAX_BYTES),
            ));
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $code) === 1) {
            throw new \InvalidArgumentException(
                $path.' contains control characters. Only tabs and line breaks are allowed.',
            );
        }
        if (preg_match('/^\s*</', $code) === 1) {
            throw new \InvalidArgumentException(
                $path.' looks like HTML. Custom JavaScript runs as script: '
                .'remove <script> and </script> and keep only the code between them. '
                .'Custom HTML tags are on the roadmap.',
            );
        }

        $syntax?->validateSyntax($code, $path, $cacheDirectory);

        return $code;
    }

    public static function wrap(string $code): string
    {
        return substr(self::PREFIX, 1).$code.substr(self::SUFFIX, 0, -1);
    }
}
