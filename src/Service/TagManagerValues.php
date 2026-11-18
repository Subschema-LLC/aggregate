<?php

declare(strict_types=1);

namespace App\Service;

use App\Entities\TagManager\CustomDataProperties;
use App\Entities\TagManager\TagArguments;
use App\Entities\TagManager\TagEvent;
use App\Entities\TagManager\TagValues as EntityTagValues;

/** Bounded declarative method calls; method paths are identifiers, never code. */
final class TagManagerValues
{
    public const MAX_SAFE_INTEGER = EntityTagValues::MAX_SAFE_INTEGER;
    public const MAX_ARGUMENTS = TagArguments::MAX_ARGUMENTS;
    public const MAX_ENTRIES = TagArguments::MAX_ENTRIES;
    public const MAX_DEPTH = TagArguments::MAX_DEPTH;
    public const MAX_NODES = TagArguments::MAX_NODES;
    public const MAX_STRING_BYTES = TagArguments::MAX_STRING_BYTES;

    public const BLOCKED_METHOD_COMPONENTS = EntityTagValues::BLOCKED_METHOD_COMPONENTS;
    public const BLOCKED_METHOD_ROOTS = EntityTagValues::BLOCKED_METHOD_ROOTS;
    public const EVENT_FIELDS = TagEvent::EVENT_FIELDS;

    public static function validateMethod(mixed $method): string
    {
        return EntityTagValues::validateMethod($method);
    }

    /**
     * @param list<mixed>  $args
     * @param list<string> $namespaces
     */
    public static function emitProblem(string $method, array $args, array $namespaces): ?string
    {
        return EntityTagValues::emitProblem($method, $args, $namespaces);
    }

    /**
     * @param array<string, mixed> $variables
     * @return array{eventName: string|array, customData?: array<string, mixed>,
     *               goalEvent?: string|array|null, pagePath?: string|array}
     */
    public static function validateEvent(
        mixed $event,
        array $variables,
        string $label = 'The event',
        ?CustomDataProperties $properties = null,
    ): array {
        return TagEvent::validate(
            $event,
            $variables,
            $properties ?? self::defaultProperties(),
            $label,
        );
    }

    /** @param array<string, mixed> $variables */
    public static function validateArguments(mixed $arguments, array $variables): array
    {
        return TagArguments::validate($arguments, $variables);
    }

    private static function defaultProperties(): CustomDataProperties
    {
        return new class implements CustomDataProperties {
            public function isValidKey(string $key): bool
            {
                return CustomDataSettings::isValidPropertyKey($key);
            }

            public function isBuiltin(string $key): bool
            {
                return isset(CustomDataSettings::BUILTIN_PROPERTIES[$key]);
            }
        };
    }
}
