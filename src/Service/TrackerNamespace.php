<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The global the tracker installs itself as: js_namespace, or Aggregate when
 * it is unset or not a plain JavaScript name. The served tracker, snippets,
 * examples and tag manager all use this, so they agree on one name, and
 * calls read Optin.emit(...) on an installation that renamed it.
 */
final class TrackerNamespace
{
    public const DEFAULT = 'Aggregate';
    /** Letters, digits, _ and $, not starting with a digit; the HTML form fields use the same pattern. */
    public const PATTERN = '[A-Za-z_$][A-Za-z0-9_$]{0,63}';
    public const RULE = 'The JavaScript namespace must be a plain JavaScript name: letters, digits, _ or $, not starting with a digit, up to 64 characters.';

    public static function of(?AggregateConfigLoader $config): string
    {
        $namespace = $config?->getWithEnvFallback('js_namespace', self::DEFAULT);

        return self::valid($namespace) ? $namespace : self::DEFAULT;
    }

    /** The configured value when it is set but not used, so the settings page can say so. */
    public static function ignored(?AggregateConfigLoader $config): ?string
    {
        $namespace = $config?->getWithEnvFallback('js_namespace', self::DEFAULT);

        return $namespace === null || self::valid($namespace) ? null : (is_scalar($namespace) ? (string) $namespace : get_debug_type($namespace));
    }

    /** @phpstan-assert-if-true string $namespace */
    public static function valid(mixed $namespace): bool
    {
        return is_string($namespace) && preg_match('/^'.self::PATTERN.'$/D', $namespace) === 1;
    }
}
