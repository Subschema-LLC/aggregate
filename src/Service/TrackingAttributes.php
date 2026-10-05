<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Names of the HTML attributes that mark clicks and form submissions for the
 * tracker. They follow the JavaScript namespace, as public/aggregate.js
 * derives them (attributeNames): data-aggregate-event by default.
 */
final class TrackingAttributes
{
    /** The shared start of every attribute, such as "data-aggregate-". */
    public static function prefix(mixed $namespace): string
    {
        $name = strtolower((string) preg_replace('/[^A-Za-z0-9_-]/', '', is_string($namespace) ? $namespace : ''));

        return 'data-'.($name !== '' ? $name : 'aggregate').'-';
    }

    /** @return array{event: string, goal: string, prop: string} */
    public static function names(mixed $namespace): array
    {
        $prefix = self::prefix($namespace);

        return ['event' => $prefix.'event', 'goal' => $prefix.'goal', 'prop' => $prefix.'prop-'];
    }
}
