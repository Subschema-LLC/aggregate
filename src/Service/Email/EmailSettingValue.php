<?php

declare(strict_types=1);

namespace App\Service\Email;

/**
 * Checks for the plain values email providers take from YAML. Providers call
 * these rather than inheriting them, so each provider class holds everything
 * it does.
 */
final class EmailSettingValue
{
    /**
     * A setting that must be one of a few codes.
     *
     * @param array<string, mixed> $values
     * @param list<string>         $allowed
     */
    public static function choice(array $values, string $key, array $allowed): string
    {
        $value = $values[$key] ?? null;
        $value = is_string($value) ? strtolower(trim($value)) : $value;
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new \InvalidArgumentException(sprintf('%s must be %s.', $key, self::alternatives($allowed)));
        }

        return $value;
    }

    /**
     * A plain one-line text setting; null and a missing value are empty.
     *
     * @param array<string, mixed> $values
     */
    public static function text(array $values, string $key, int $maxLength = 255): string
    {
        $value = $values[$key] ?? '';
        if (!is_string($value) || strlen($value) > $maxLength || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new \InvalidArgumentException(sprintf('%s must be text of at most %d characters.', $key, $maxLength));
        }

        return trim($value);
    }

    /** Whether a value is a host name such as mg.example.com (letters, digits, hyphens and dots). */
    public static function isHostName(string $value): bool
    {
        return strlen($value) <= 253
            && preg_match('/^(?=.{1,253}$)(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)*[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/D', $value) === 1;
    }

    /** @param list<string> $allowed */
    private static function alternatives(array $allowed): string
    {
        if (count($allowed) === 1) {
            return $allowed[0];
        }
        $last = array_pop($allowed);

        return implode(', ', $allowed).' or '.$last;
    }
}
