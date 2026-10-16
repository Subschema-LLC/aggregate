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
    /** Names people give the properties object, after the request field and the GTM template's setting. */
    private const PROPERTY_WRAPPERS = ['customData', 'eventData'];
    /** The fields of an event object; the tracker sets the others (see public/aggregate.js emit()). */
    public const EVENT_FIELDS = ['eventName', 'customData', 'goalEvent', 'pagePath'];
    private const TRACKER_FIELDS = ['websiteToken', 'referrerChannel', 'deviceClass', 'viewportBucket', 'consentState', 'visitorId', 'sessionId', 'screenWidth', 'org_internal_traffic'];
    private const EVENT_NAME = '/^[A-Za-z][A-Za-z0-9_.:-]{0,99}$/D';
    private const MAX_PROPERTY_BYTES = 500;

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

    /**
     * Why a call to the tracker's emit() would lose its properties, or null.
     *
     * emit(name, properties, goal) takes flat properties: text, numbers, true,
     * false or null. The tracker puts them in the request's customData itself
     * and drops a property that holds an object or a list, so a customData
     * wrapper, or any other nesting, sends the event without them. The tag row
     * editor makes the same check as you type (tag_row_controller.js).
     *
     * @param list<mixed>  $args       validated arguments
     * @param list<string> $namespaces the tracker's JavaScript namespaces
     */
    public static function emitProblem(string $method, array $args, array $namespaces): ?string
    {
        $methods = array_map(static fn (string $namespace): string => $namespace.'.emit', $namespaces);
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
            return 'A variable gives one value, but the second argument needs an object of properties. Name each property and use a variable for its value: {"plan": {"$var": "plan"}}.';
        }
        // JSON {} and [] both decode to an empty array; neither sends anything.
        if (!is_array($properties) || ($properties !== [] && array_is_list($properties))) {
            return 'The second argument holds the event properties and must be an object, such as {"plan": "pro"}, or null. A goal goes third.';
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
            && ($properties[$wrapper] === [] || !array_is_list($properties[$wrapper]))) {
            return sprintf('Remove the "%s" wrapper and put its properties directly in the second argument. The tracker puts the properties in customData itself and drops a property that holds an object, so this event would be sent without them.', $wrapper);
        }
        $names = array_map(static fn (string $name): string => '"'.$name.'"', $nested);
        $last = array_pop($names);

        return sprintf('The tracker drops %s: a property must be text, a number, true, false or null, not an object or a list. Give each value its own property.', $names === [] ? $last : implode(', ', $names).' and '.$last);
    }

    /**
     * The event of a "Send an Aggregate event" tag, as the tracker's
     * emit({eventName, customData, goalEvent, pagePath}) takes it. customData
     * holds the event's own properties, flat; any value may be a
     * {$var: alias} reference read from the page. The tracker sets every
     * other request field, so the event cannot.
     *
     * @param array<string, mixed> $variables already validated alias definitions
     * @return array{eventName: string|array, customData?: array<string, mixed>, goalEvent?: string|array|null, pagePath?: string|array}
     */
    public static function validateEvent(mixed $event, array $variables, string $label = 'The event'): array
    {
        if (!is_array($event) || ($event !== [] && array_is_list($event))) {
            throw new \InvalidArgumentException($label.' must be an object such as {"eventName": "login", "customData": {"method": "password"}}.');
        }
        $unknown = array_diff(array_map('strval', array_keys($event)), self::EVENT_FIELDS);
        if ($unknown !== []) {
            $field = reset($unknown);
            throw new \InvalidArgumentException($label.' has '.$field.', but it takes only eventName, customData, goalEvent and pagePath. '
                .(in_array($field, self::TRACKER_FIELDS, true) ? 'The tracker sets '.$field.' itself.' : 'Put the event\'s properties in customData.'));
        }
        if (!array_key_exists('eventName', $event)) {
            throw new \InvalidArgumentException($label.' needs an eventName, such as "login".');
        }
        $validated = ['eventName' => self::eventValue($event['eventName'], $variables, $label.': eventName', static fn (mixed $name): bool => is_string($name) && preg_match(self::EVENT_NAME, $name) === 1,
            'must be a fixed name such as "login": a letter, then up to 99 letters, digits, _ . : or -')];

        if (array_key_exists('customData', $event)) {
            $properties = $event['customData'];
            if (!is_array($properties) || ($properties !== [] && array_is_list($properties))) {
                throw new \InvalidArgumentException($label.': customData must be an object of properties, such as {"method": "password"}.');
            }
            if (count($properties) > self::MAX_ENTRIES) {
                throw new \InvalidArgumentException($label.': customData may hold at most '.self::MAX_ENTRIES.' properties.');
            }
            $validated['customData'] = [];
            foreach ($properties as $key => $value) {
                $key = (string) $key;
                if (!CustomDataSettings::isValidPropertyKey($key)) {
                    throw new \InvalidArgumentException($label.': customData.'.mb_strimwidth($key, 0, 64, '…').' is not a property name. Use a letter, then up to 63 letters, digits, _ . or -.');
                }
                if (isset(CustomDataSettings::BUILTIN_PROPERTIES[$key])) {
                    throw new \InvalidArgumentException($label.': the tracker adds customData.'.$key.' itself; leave it out.');
                }
                $validated['customData'][$key] = self::eventValue($value, $variables, $label.': customData.'.$key, static fn (mixed $value): bool => $value === null || is_bool($value)
                    || (is_string($value) && strlen($value) <= self::MAX_PROPERTY_BYTES && preg_match('//u', $value) === 1)
                    || ((is_int($value) || is_float($value)) && is_finite((float) $value) && !(floor((float) $value) === (float) $value && abs($value) > self::MAX_SAFE_INTEGER)),
                    'must be text of at most '.self::MAX_PROPERTY_BYTES.' bytes, a number, true, false or null, not an object or a list');
            }
            if ($validated['customData'] === []) {
                unset($validated['customData']);
            }
        }
        if (array_key_exists('goalEvent', $event)) {
            $validated['goalEvent'] = self::eventValue($event['goalEvent'], $variables, $label.': goalEvent', static fn (mixed $goal): bool => $goal === null
                || (is_string($goal) && $goal !== '' && trim($goal) === $goal && strlen($goal) <= 100 && preg_match('/[\x00-\x1f\x7f]/', $goal) !== 1),
                'must name a goal from config/goals.yaml, or be null');
        }
        if (array_key_exists('pagePath', $event)) {
            $validated['pagePath'] = self::eventValue($event['pagePath'], $variables, $label.': pagePath', static fn (mixed $path): bool => is_string($path)
                && strlen($path) <= 512 && preg_match('#^/(?!/)[^?\#\\\\\x00-\x1f\x7f]*$#D', $path) === 1,
                'must be a path on the site such as /checkout/step-2, without a query or fragment');
        }

        return $validated;
    }

    /** A literal accepted by $valid, or a {$var: alias} reference to a declared variable. */
    private static function eventValue(mixed $value, array $variables, string $label, \Closure $valid, string $rule): mixed
    {
        if (self::isReference($value)) {
            $alias = $value['$var'];
            if (!is_string($alias) || !array_key_exists($alias, $variables)) {
                throw new \InvalidArgumentException($label.' uses {"$var": …} with a variable that is not defined. Add it under Variables.');
            }

            return ['$var' => $alias];
        }
        if (!$valid($value)) {
            throw new \InvalidArgumentException($label.' '.$rule.(is_array($value) ? '. To read a value from the page, use {"$var": "alias"}.' : '.'));
        }

        return $value;
    }

    private static function isReference(mixed $value): bool
    {
        return is_array($value) && count($value) === 1 && array_key_exists('$var', $value);
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
