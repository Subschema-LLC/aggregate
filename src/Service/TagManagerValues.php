<?php

declare(strict_types=1);

namespace App\Service;

/** Bounded declarative method calls; method paths are identifiers, never code. */
final class TagManagerValues
{
    public const MAX_SAFE_INTEGER = 9_007_199_254_740_991;
    public const MAX_ARGUMENTS = 10;
    public const MAX_ENTRIES = 32;
    public const MAX_DEPTH = 4;
    public const MAX_NODES = 128;
    public const MAX_STRING_BYTES = 2048;

    public const BLOCKED_METHOD_COMPONENTS = ['__proto__', 'prototype', 'constructor', 'call', 'apply', 'bind'];
    public const BLOCKED_METHOD_ROOTS = [
        'window', 'globalThis', 'self', 'top', 'parent', 'frames', 'document',
        'location', 'localStorage', 'sessionStorage', 'Function', 'eval', 'Object',
        'Reflect', 'Proxy', 'WebAssembly', 'setTimeout', 'setInterval', 'fetch',
        'opener', 'navigator', 'history', 'navigation', 'XMLHttpRequest', 'WebSocket',
        'Worker', 'SharedWorker', 'importScripts', 'requestAnimationFrame', 'queueMicrotask',
    ];
    private const BLOCKED_MAP_KEYS = ['__proto__', 'prototype', 'constructor'];

    public static function validateMethod(mixed $method): string
    {
        if (!is_string($method) || strlen($method) > 128
            || preg_match('/^[A-Za-z_$][A-Za-z0-9_$]*(?:\.[A-Za-z_$][A-Za-z0-9_$]*){1,7}$/D', $method) !== 1) {
            throw new \InvalidArgumentException('A tag method must be a library.method identifier path of at most 128 bytes and 8 components.');
        }
        $components = explode('.', $method);
        if (in_array($components[0], self::BLOCKED_METHOD_ROOTS, true)
            || array_intersect($components, self::BLOCKED_METHOD_COMPONENTS) !== []) {
            throw new \InvalidArgumentException('A tag method must use an application library without browser globals, constructors, prototypes or function rebinding.');
        }

        return $method;
    }

    /** @param array<string, mixed> $variables Already validated alias definitions. */
    public static function validateArguments(mixed $arguments, array $variables): array
    {
        if (!is_array($arguments) || !array_is_list($arguments) || count($arguments) > self::MAX_ARGUMENTS) {
            throw new \InvalidArgumentException('Tag arguments must be a list of at most '.self::MAX_ARGUMENTS.' values.');
        }
        // The top-level argument list counts once; keys are not value nodes.
        $nodes = 1;
        foreach ($arguments as $index => $value) {
            $arguments[$index] = self::validateValue($value, $variables, 0, $nodes);
        }

        return $arguments;
    }

    private static function validateValue(mixed $value, array $variables, int $depth, int &$nodes): mixed
    {
        if (++$nodes > self::MAX_NODES) {
            throw new \InvalidArgumentException('Tag arguments may contain at most '.self::MAX_NODES.' total values including containers.');
        }
        if ($value === null || is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            if (!is_finite((float) $value) || (floor((float) $value) === (float) $value
                && abs($value) > self::MAX_SAFE_INTEGER)) {
                throw new \InvalidArgumentException('Tag numeric arguments must be finite, with integers inside the JavaScript safe-integer range.');
            }

            return $value;
        }
        if (is_string($value)) {
            if (strlen($value) > self::MAX_STRING_BYTES || preg_match('//u', $value) !== 1) {
                throw new \InvalidArgumentException('Tag string arguments must be valid UTF-8 of at most '.self::MAX_STRING_BYTES.' bytes.');
            }

            return $value;
        }
        if (!is_array($value) || count($value) > self::MAX_ENTRIES || $depth >= self::MAX_DEPTH) {
            throw new \InvalidArgumentException('Tag arguments support JSON literals with at most '.self::MAX_ENTRIES.' entries per container and '.self::MAX_DEPTH.' nested containers.');
        }

        if (array_key_exists('$var', $value)) {
            $alias = $value['$var'];
            if (count($value) !== 1 || !is_string($alias) || $alias === '' || strlen($alias) > 64
                || in_array($alias, self::BLOCKED_MAP_KEYS, true) || !array_key_exists($alias, $variables)) {
                throw new \InvalidArgumentException('A variable reference must contain only $var with a known variable alias.');
            }
            // Count and validate the reference's alias as its sole scalar child.
            self::validateValue($alias, $variables, $depth + 1, $nodes);

            return ['$var' => $alias];
        }

        $isList = array_is_list($value);
        foreach ($value as $key => $child) {
            if (!$isList) {
                $property = (string) $key;
                if ($property === '' || strlen($property) > 64 || preg_match('//u', $property) !== 1
                    || preg_match('/[\x00-\x1f\x7f]/', $property) === 1
                    || in_array($property, self::BLOCKED_MAP_KEYS, true)) {
                    throw new \InvalidArgumentException('Tag argument property names must contain 1–64 UTF-8 bytes without control characters or prototype keys.');
                }
            }
            $value[$key] = self::validateValue($child, $variables, $depth + 1, $nodes);
        }

        return $value;
    }
}
