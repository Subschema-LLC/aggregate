<?php

declare(strict_types=1);

namespace App\Service;

/** Validate bounded data paths and URL templates without evaluating JavaScript. */
final class TagManagerVariables
{
    public const MAX_VARIABLES = 32;
    public const MAX_PATH_LENGTH = 256;
    public const MAX_SEGMENTS = 16;
    public const MAX_INDEX = 10000;

    private const FORBIDDEN_KEYS = ['__proto__', 'prototype', 'constructor'];
    private const IDENTIFIER = '[A-Za-z_$][A-Za-z0-9_$]*';
    private const ALIAS = '[A-Za-z][A-Za-z0-9_]{0,63}';

    /** @return array<string, string> */
    public static function validate(mixed $variables): array
    {
        if (!is_array($variables) || count($variables) > self::MAX_VARIABLES) {
            throw new \InvalidArgumentException('Tag variables must be a mapping of at most '.self::MAX_VARIABLES.' aliases to data paths.');
        }

        foreach ($variables as $alias => $path) {
            if (!is_string($alias) || preg_match('/\A'.self::ALIAS.'\z/', $alias) !== 1
                || in_array($alias, self::FORBIDDEN_KEYS, true)) {
                throw new \InvalidArgumentException('Variable aliases must contain 1–64 letters, digits or underscores, beginning with a letter. Prototype-related names are forbidden.');
            }
            if (!is_string($path) || $path === '' || strlen($path) > self::MAX_PATH_LENGTH
                || preg_match('/\A'.self::IDENTIFIER.'(?:\.'.self::IDENTIFIER.'|\[(?:0|[1-9][0-9]{0,4})\])*\z/', $path) !== 1) {
                throw new \InvalidArgumentException('Variable paths must contain at most '.self::MAX_PATH_LENGTH.' bytes and use dot-separated keys or numeric bracket indexes, without JavaScript expressions.');
            }

            preg_match_all('/'.self::IDENTIFIER.'|[0-9]+/', $path, $segments);
            if (count($segments[0]) > self::MAX_SEGMENTS) {
                throw new \InvalidArgumentException('Variable paths may contain at most '.self::MAX_SEGMENTS.' segments.');
            }
            foreach ($segments[0] as $segment) {
                if (in_array($segment, self::FORBIDDEN_KEYS, true)
                    || (ctype_digit($segment) && (int) $segment > self::MAX_INDEX)) {
                    throw new \InvalidArgumentException('Variable paths cannot traverse prototype-related keys, and array indexes must be between 0 and '.self::MAX_INDEX.'.');
                }
            }
        }

        return $variables;
    }

    /** Only the path and query may contain declared {{alias}} placeholders. */
    public static function validateSource(mixed $src, array $variables): string
    {
        $variables = self::validate($variables);
        if (!is_string($src) || $src === '' || strlen($src) > 2048
            || preg_match('/[\x00-\x20\x7f\\\\]/', $src) === 1
            || preg_match('/\Ahttps:\/\/([^\/?#]+)/i', $src, $authority) !== 1
            || strpbrk($authority[1], '{}') !== false) {
            throw new \InvalidArgumentException('Tag scripts need an absolute HTTPS URL of at most 2048 bytes, with a fixed host and no spaces, controls or backslashes.');
        }

        $sample = preg_replace_callback('/\{\{('.self::ALIAS.')\}\}/', static function (array $match) use ($variables): string {
            if (!array_key_exists($match[1], $variables)) {
                throw new \InvalidArgumentException('Every URL placeholder must reference a declared tag variable.');
            }

            return 'aggregatevalue';
        }, $src);
        if (!is_string($sample) || strpbrk($sample, '{}') !== false || filter_var($sample, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException('Tag script URLs must be valid HTTPS URLs; placeholders use exactly {{alias}} in the path or query.');
        }
        $parts = parse_url($sample);
        if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https'
            || !isset($parts['host']) || $parts['host'] === ''
            || array_key_exists('user', $parts) || array_key_exists('pass', $parts)
            || array_key_exists('fragment', $parts)) {
            throw new \InvalidArgumentException('Tag script URLs cannot include credentials or fragments.');
        }

        return $src;
    }
}
