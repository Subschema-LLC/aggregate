<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The installation's reporting timezone. Stored in YAML, compiled into the
 * event views when they are regenerated, and never written onto events.
 */
final class ReportingTimezone
{
    public const KEY = 'reporting_timezone';
    public const DEFAULT = 'UTC';

    public function __construct(private readonly AggregateConfigLoader $config)
    {
    }

    public function name(): string
    {
        $value = $this->config->getWithEnvFallback(self::KEY, self::DEFAULT);

        if (!is_string($value)) {
            throw new \RuntimeException(self::invalidMessage());
        }

        try {
            return self::validate($value);
        } catch (\InvalidArgumentException $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }
    }

    public function save(string $name): void
    {
        $this->config->set(self::KEY, self::validate($name));
    }

    public function hasEnvironmentOverride(): bool
    {
        return $this->config->hasEnvironmentOverride(self::KEY);
    }

    /**
     * IANA names grouped by region, for the dashboard select. Offsets are omitted.
     *
     * @return array<string, list<string>>
     */
    public static function choices(): array
    {
        $groups = [];
        foreach (\DateTimeZone::listIdentifiers() as $name) {
            if (self::isRejectedOffset($name)) {
                continue;
            }
            $group = str_contains($name, '/') ? (string) strstr($name, '/', true) : 'Other';
            $groups[$group][] = $name;
        }
        ksort($groups);

        return $groups;
    }

    public static function validate(string $value): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 64 || self::isRejectedOffset($value) || !in_array($value, \DateTimeZone::listIdentifiers(), true)) {
            throw new \InvalidArgumentException(self::invalidMessage());
        }

        return $value;
    }

    /**
     * A zone whose offset is zero across the compiled range needs no conversion.
     * UTC, and zones such as Africa/Abidjan, keep the existing view SQL.
     */
    public static function isIdentity(string $timezone): bool
    {
        if ($timezone === self::DEFAULT) {
            return true;
        }

        $start = (new \DateTimeImmutable('1970-01-01 00:00:00 UTC'))->getTimestamp();
        $end = (new \DateTimeImmutable('2100-01-01 00:00:00 UTC'))->getTimestamp();
        foreach ((new \DateTimeZone($timezone))->getTransitions($start, $end) as $transition) {
            if ($transition['offset'] !== 0) {
                return false;
            }
        }

        return true;
    }

    public static function windowsName(string $timezone): string
    {
        $name = WindowsTimezoneNames::MAP[$timezone] ?? null;
        if (!is_string($name) || $name === '') {
            throw new \RuntimeException(sprintf(
                'SQL Server has no Windows time zone for %s. Choose another IANA name, or keep reporting_timezone at UTC. The existing views were left unchanged.',
                $timezone,
            ));
        }

        return $name;
    }

    /**
     * Exclusive UTC boundaries and the offset in effect before each, from 1970
     * through 2100. The last offset continues after that until the views are
     * regenerated. SQLite has no time zone database, so the view carries this
     * table instead.
     *
     * @return list<array{until: ?string, offset: int}>
     */
    public static function transitions(string $timezone): array
    {
        $rows = (new \DateTimeZone($timezone))->getTransitions(
            (new \DateTimeImmutable('1970-01-01 00:00:00 UTC'))->getTimestamp(),
            (new \DateTimeImmutable('2100-01-01 00:00:00 UTC'))->getTimestamp(),
        );
        if ($rows === []) {
            throw new \RuntimeException(sprintf('Cannot compile time zone transitions for %s.', $timezone));
        }

        $compiled = [];
        $count = count($rows);
        for ($i = 0; $i < $count; ++$i) {
            $compiled[] = [
                'until' => $i + 1 < $count ? gmdate('Y-m-d H:i:s', $rows[$i + 1]['ts']) : null,
                'offset' => $rows[$i]['offset'],
            ];
        }

        return $compiled;
    }

    public static function invalidMessage(): string
    {
        return 'reporting_timezone must be an IANA time zone name such as America/Chicago, or UTC. Offsets and abbreviations such as UTC-5 or EST are rejected because a fixed differential is wrong for half the year where daylight-saving time applies.';
    }

    private static function isRejectedOffset(string $value): bool
    {
        return preg_match('/^(?:UTC|GMT)[+-]/i', $value) === 1
            || preg_match('/^Etc\/GMT[+-]/', $value) === 1
            || preg_match('/^[+-]\d/', $value) === 1;
    }
}
