<?php

declare(strict_types=1);

namespace App\Entities\TagManager;

/**
 * Validates method identifiers and analyzes tracker emit calls for call tags.
 */
final class TagValues
{
    public const MAX_SAFE_INTEGER = 9_007_199_254_740_991;

    public const BLOCKED_METHOD_COMPONENTS = [
        '__proto__', 'prototype', 'constructor', 'call', 'apply', 'bind',
    ];
    public const BLOCKED_METHOD_ROOTS = [
        'window', 'globalThis', 'self', 'top', 'parent', 'frames', 'document',
        'location', 'localStorage', 'sessionStorage', 'Function', 'eval', 'Object',
        'Reflect', 'Proxy', 'WebAssembly', 'setTimeout', 'setInterval', 'fetch',
        'opener', 'navigator', 'history', 'navigation', 'XMLHttpRequest', 'WebSocket',
        'Worker', 'SharedWorker', 'importScripts', 'requestAnimationFrame', 'queueMicrotask',
    ];
    public const BLOCKED_MAP_KEYS = ['__proto__', 'prototype', 'constructor'];
    private const PROPERTY_WRAPPERS = ['customData', 'eventData'];

    public static function validateMethod(mixed $method): string
    {
        if (!is_string($method) || strlen($method) > 128
            || preg_match(
                '/^[A-Za-z_$][A-Za-z0-9_$]*(?:\.[A-Za-z_$][A-Za-z0-9_$]*){1,7}$/D',
                $method,
            ) !== 1
        ) {
            throw new \InvalidArgumentException(
                'A tag method must be a library.method identifier path of '
                .'at most 128 bytes and 8 components.',
            );
        }
        $components = explode('.', $method);
        if (in_array($components[0], self::BLOCKED_METHOD_ROOTS, true)
            || array_intersect($components, self::BLOCKED_METHOD_COMPONENTS) !== []
        ) {
            throw new \InvalidArgumentException(
                'A tag method must use an application library without browser globals, '
                .'constructors, prototypes or function rebinding.',
            );
        }

        return $method;
    }

    /**
     * Why a call to the tracker's emit() would lose its properties, or null.
     *
     * @param list<mixed>  $args
     * @param list<string> $namespaces
     */
    public static function emitProblem(string $method, array $args, array $namespaces): ?string
    {
        $methods = array_map(static fn (string $ns): string => $ns.'.emit', $namespaces);
        if (!in_array($method, $methods, true)) {
            return null;
        }
        $name = $args[0] ?? null;
        if (!is_string($name) && !self::isReference($name)) {
            return 'The first argument is the event name, such as "login".';
        }
        $properties = $args[1] ?? null;
        if ($properties === null) {
            return null;
        }
        if (self::isReference($properties)) {
            return 'A variable gives one value, but the second argument needs an object '
                .'of properties. Name each property and use a variable for its value: '
                .'{"plan": {"$var": "plan"}}.';
        }
        if (!is_array($properties) || ($properties !== [] && array_is_list($properties))) {
            return 'The second argument holds the event properties and must be an object, '
                .'such as {"plan": "pro"}, or null. A goal goes third.';
        }
        $nested = [];
        foreach ($properties as $key => $value) {
            if (is_array($value) && !self::isReference($value)) {
                $nested[] = (string) $key;
            }
        }
        if ($nested === []) {
            return null;
        }
        $wrapper = $nested[0];
        if (count($properties) === 1 && in_array($wrapper, self::PROPERTY_WRAPPERS, true)
            && ($properties[$wrapper] === [] || !array_is_list($properties[$wrapper]))
        ) {
            return sprintf(
                'Remove the "%s" wrapper and put its properties directly in the second argument. '
                .'The tracker puts the properties in customData itself and drops a property that '
                .'holds an object, so this event would be sent without them.',
                $wrapper,
            );
        }
        $names = array_map(static fn (string $name): string => '"'.$name.'"', $nested);
        $last = array_pop($names);

        return sprintf(
            'The tracker drops %s: a property must be text, a number, true, false or null, '
            .'not an object or a list. Give each value its own property.',
            $names === [] ? $last : implode(', ', $names).' and '.$last,
        );
    }

    public static function isReference(mixed $value): bool
    {
        return is_array($value)
            && !array_is_list($value)
            && array_key_exists('$var', $value)
            && array_diff(array_keys($value), ['$var', 'from']) === []
            && (!array_key_exists('from', $value)
                || in_array($value['from'], ['recent', 'event'], true));
    }

    /**
     * @param array{tags: list<array>} $settings
     * @param list<string>             $trackerNamespaces
     * @return list<string>
     */
    public static function emitWarnings(array $settings, array $trackerNamespaces): array
    {
        $warnings = [];
        foreach ($settings['tags'] as $tag) {
            if (($tag['type'] ?? null) !== 'call') {
                continue;
            }
            $problem = self::emitProblem(
                $tag['method'],
                $tag['args'],
                $trackerNamespaces,
            );
            if ($problem !== null) {
                $warnings[] = 'Tag '.$tag['id'].': '.$problem
                    .' Or convert it to a Send an Aggregate event action, '
                    .'which takes {"eventName": …, "customData": {…}}.';
            }
        }

        return $warnings;
    }
}
