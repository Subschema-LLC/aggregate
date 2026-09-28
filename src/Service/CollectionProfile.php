<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Deployment-wide collection profile.
 *
 * The strict profile reduces collection to what the browser must send to report
 * a page view or named event: the sanitized page path, the event name and an
 * approved goal. Every event is recorded in anonymous mode. The tracker reads
 * nothing else from the device and stores nothing on it; the server discards
 * any other submitted dimension and performs no geographic lookup.
 */
final class CollectionProfile
{
    public const KEY = 'collection_profile';
    public const STANDARD = 'standard';
    public const STRICT = 'strict';
    public const PROFILES = [self::STANDARD, self::STRICT];

    public function __construct(private readonly AggregateConfigLoader $config)
    {
    }

    public static function isValid(mixed $value): bool
    {
        return self::normalize($value) !== null;
    }

    /** Returns the canonical profile name, or null for an invalid value. */
    public static function normalize(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value));

        return in_array($value, self::PROFILES, true) ? $value : null;
    }

    public function name(): string
    {
        // Invalid values also make the configuration unhealthy, which stops
        // ingestion. Resolving them to strict keeps every other reader closed.
        return self::normalize($this->config->getWithEnvFallback(self::KEY, self::STANDARD)) ?? self::STRICT;
    }

    public function isStrict(): bool
    {
        return $this->name() === self::STRICT;
    }

    public function hasEnvironmentOverride(): bool
    {
        return $this->config->hasEnvironmentOverride(self::KEY);
    }

    /** @return array{profile: string} */
    public function toBrowserConfig(): array
    {
        return ['profile' => $this->name()];
    }
}
