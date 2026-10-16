<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The global the tracker installs itself as: js_namespace, or Aggregate when
 * it is unset or not a plain JavaScript name. Examples and checks use it so
 * that calls read Optin.emit(...) on an installation that renamed it.
 */
final class TrackerNamespace
{
    public const DEFAULT = 'Aggregate';

    public static function of(?AggregateConfigLoader $config): string
    {
        $namespace = $config?->getWithEnvFallback('js_namespace', self::DEFAULT);

        return is_string($namespace) && preg_match('/^[A-Za-z_$][A-Za-z0-9_$]{0,63}$/D', $namespace) === 1 ? $namespace : self::DEFAULT;
    }
}
