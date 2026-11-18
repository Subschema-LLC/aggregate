<?php

declare(strict_types=1);

namespace App\Entities\TagManager;

/**
 * Validates bounded declarative arguments for call tags.
 */
final class TagArguments
{
    public const MAX_ARGUMENTS = 10;
    public const MAX_ENTRIES = 32;
    public const MAX_DEPTH = 4;
    public const MAX_NODES = 128;
    public const MAX_STRING_BYTES = 2048;

    /** @param array<string, mixed> $variables */
    public static function validate(mixed $arguments, array $variables): array
    {
        if (!is_array($arguments) || !array_is_list($arguments)
            || count($arguments) > self::MAX_ARGUMENTS
        ) {
            throw new \InvalidArgumentException(
                'Tag arguments must be a list of at most '.self::MAX_ARGUMENTS.' values.',
            );
        }
        $nodes = 1;
        foreach ($arguments as $index => $value) {
            $arguments[$index] = self::validateValue($value, $variables, 0, $nodes);
        }

        return $arguments;
    }

    private static function validateValue(
        mixed $value,
        array $variables,
        int $depth,
        int &$nodes,
    ): mixed {
        if (++$nodes > self::MAX_NODES) {
            throw new \InvalidArgumentException(
                'Tag arguments may contain at most '.self::MAX_NODES
                .' total values including containers.',
            );
        }
        if ($value === null || is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            if (!is_finite((float) $value) || (floor((float) $value) === (float) $value
                && abs($value) > TagValues::MAX_SAFE_INTEGER)
            ) {
                throw new \InvalidArgumentException(
                    'Tag numeric arguments must be finite, with integers inside '
                    .'the JavaScript safe-integer range.',
                );
            }

            return $value;
        }
        if (is_string($value)) {
            if (strlen($value) > self::MAX_STRING_BYTES || preg_match('//u', $value) !== 1) {
                throw new \InvalidArgumentException(
                    'Tag string arguments must be valid UTF-8 of at most '
                    .self::MAX_STRING_BYTES.' bytes.',
                );
            }

            return $value;
        }
        if (!is_array($value) || count($value) > self::MAX_ENTRIES || $depth >= self::MAX_DEPTH) {
            throw new \InvalidArgumentException(
                'Tag arguments support JSON literals with at most '.self::MAX_ENTRIES
                .' entries per container and '.self::MAX_DEPTH.' nested containers.',
            );
        }

        if (!array_is_list($value) && array_key_exists('$var', $value)) {
            $alias = $value['$var'];
            if (!TagValues::isReference($value) || !is_string($alias) || $alias === ''
                || strlen($alias) > 64 || in_array($alias, TagValues::BLOCKED_MAP_KEYS, true)
                || !array_key_exists($alias, $variables)
            ) {
                throw new \InvalidArgumentException(
                    'A variable reference must contain only $var with a known variable alias, '
                    .'and an optional "from" set to "recent" or "event".',
                );
            }
            self::validateValue($alias, $variables, $depth + 1, $nodes);

            return ($value['from'] ?? 'recent') === 'event'
                ? ['$var' => $alias, 'from' => 'event']
                : ['$var' => $alias];
        }

        $isList = array_is_list($value);
        foreach ($value as $key => $child) {
            if (!$isList) {
                $prop = (string) $key;
                if ($prop === '' || strlen($prop) > 64
                    || preg_match('//u', $prop) !== 1
                    || preg_match('/[\x00-\x1f\x7f]/', $prop) === 1
                    || in_array($prop, TagValues::BLOCKED_MAP_KEYS, true)
                ) {
                    throw new \InvalidArgumentException(
                        'Tag argument property names must contain 1–64 UTF-8 bytes '
                        .'without control characters or prototype keys.',
                    );
                }
            }
            $value[$key] = self::validateValue($child, $variables, $depth + 1, $nodes);
        }

        return $value;
    }
}
