<?php

declare(strict_types=1);

namespace App\Service\DataTransfer;

use App\Service\GeoIp\GeoArea;

/**
 * Turns a database row into a package row and back. Every value is checked
 * here. Messages name the problem, never the value.
 */
final class RowCodec
{
    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function encode(string $table, array $row): array
    {
        $package = [];
        foreach (TransferCatalog::columns($table) as $name => $column) {
            if (!array_key_exists($name, $row)) {
                throw new DataTransferException(sprintf('The %s table is missing %s. Run doctrine:migrations:migrate before transferring.', $table, $name));
            }
            $package[$name] = self::toPackage($column, $row[$name]);
        }
        self::decode($table, $package, 0);

        return $package;
    }

    /**
     * A package row is untrusted JSON, so its keys may not be column names.
     *
     * @param array<mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function decode(string $table, array $row, int $line): array
    {
        $definition = TransferCatalog::columns($table);
        foreach ($row as $key => $value) {
            if (!is_string($key) || !isset($definition[$key])) {
                throw new DataTransferException(self::place($table, $line).' has a column this version does not transfer, so it was not imported.');
            }
        }
        $decoded = [];
        foreach ($definition as $name => $column) {
            if (!array_key_exists($name, $row)) {
                throw new DataTransferException(self::place($table, $line).' is missing '.$name.', so it was not imported.');
            }
            try {
                $decoded[$name] = self::validate($column, $row[$name]);
            } catch (DataTransferException $e) {
                throw new DataTransferException(self::place($table, $line).' was not imported: '.$e->getMessage(), previous: $e);
            }
        }
        self::assertPrivacy($table, $decoded, $line);

        return $decoded;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, string> $tokenMap
     *
     * @return array<string, mixed>
     */
    public static function applyTokenMap(string $table, array $row, array $tokenMap): array
    {
        if ($tokenMap === [] || !TransferCatalog::hasWebsite($table)) {
            return $row;
        }
        $current = $row['website_token'] ?? null;
        if (!is_string($current) || !isset($tokenMap[$current])) {
            return $row;
        }
        $mapped = $tokenMap[$current];
        if (preg_match('/^[A-Za-z0-9_-]{1,191}$/D', $mapped) !== 1) {
            throw new DataTransferException('A mapped website token is not a token this installation can collect, so the row was not imported.');
        }
        $row['website_token'] = $mapped;

        return $row;
    }

    /** @param array<string, mixed> $column */
    private static function toPackage(array $column, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($column['kind']) {
            'integer' => is_int($value) ? $value : (is_string($value) && preg_match('/^-?[0-9]+$/D', $value) === 1 ? (int) $value : $value),
            'bigint' => is_int($value) || (is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1) ? (string) (is_int($value) ? $value : $value) : $value,
            'datetime' => self::formatDateTime($value),
            'date' => self::formatDate($value),
            'json' => self::decodeStoredJson($value, true),
            'roles' => self::decodeStoredJson($value, false),
            default => $value,
        };
    }

    /** @param array<string, mixed> $column */
    private static function validate(array $column, mixed $value): mixed
    {
        $kind = $column['kind'];
        $nullable = (bool) ($column['nullable'] ?? false);
        if ($value === null) {
            if (!$nullable) {
                throw new DataTransferException('a required value is missing');
            }

            return null;
        }

        return match ($kind) {
            'integer' => self::integer($value, (int) ($column['min'] ?? 0)),
            'bigint' => self::bigint($value),
            'datetime' => self::dateTime($value),
            'date' => self::date($value),
            'json' => self::customData($value),
            'roles' => self::roles($value),
            'text', 'string' => self::text($value, (int) $column['max']),
            'token' => self::token($value),
            'event_name' => self::eventName($value),
            'privacy_mode' => self::choice($value, ['anonymous', 'enhanced']),
            'enum' => self::choice($value, $column['values']),
            'geo_area' => self::geoArea($value),
            'consent_state' => self::choice($value, ['granted']),
            'path' => self::path($value),
            'referrer_channel' => self::choice($value, TransferCatalog::ANONYMOUS_REFERRERS),
            'cell_key' => self::cellKey($value),
            'username' => self::username($value),
            'secret' => self::secret($value),
            default => throw new DataTransferException('a value has the wrong type'),
        };
    }

    /** @param array<string, mixed> $row */
    private static function assertPrivacy(string $table, array $row, int $line): void
    {
        if ($table === 'analytics_archive_events') {
            if ($row['privacy_mode'] === 'anonymous') {
                if (!is_string($row['page_path']) || !self::isSanitizedPath($row['page_path'], 512)) {
                    throw new DataTransferException(self::place($table, $line).' is anonymous but its page path is not a sanitized path, so it was not copied.');
                }
                if (!is_string($row['referrer_channel']) || !in_array($row['referrer_channel'], TransferCatalog::ANONYMOUS_REFERRERS, true)) {
                    throw new DataTransferException(self::place($table, $line).' is anonymous but its referrer channel is not a coarse channel, so it was not copied.');
                }
            } elseif (!is_string($row['page_path']) || $row['page_path'] === '' || !is_string($row['referrer_channel']) || $row['referrer_channel'] === '') {
                throw new DataTransferException(self::place($table, $line).' has an empty page path or referrer channel, so it was not copied.');
            }

            return;
        }
        if ($table !== 'events') {
            return;
        }
        if ($row['privacy_mode'] === 'anonymous') {
            foreach (['generalized_user_agent', 'screen_width', 'visitor_id', 'session_id', 'consent_state'] as $column) {
                if ($row[$column] !== null) {
                    throw new DataTransferException(self::place($table, $line).' is anonymous but has an identifier or exact detail, so it was not copied.');
                }
            }
            if (!is_string($row['url']) || !self::isSanitizedPath($row['url'], 512)) {
                throw new DataTransferException(self::place($table, $line).' is anonymous but its URL is not a sanitized path, so it was not copied.');
            }
            if ($row['referrer'] !== null && !in_array($row['referrer'], TransferCatalog::ANONYMOUS_REFERRERS, true)) {
                throw new DataTransferException(self::place($table, $line).' is anonymous but its referrer is not a coarse channel, so it was not copied.');
            }
        }
        if ($row['privacy_mode'] === 'enhanced' && $row['consent_state'] !== 'granted') {
            throw new DataTransferException(self::place($table, $line).' is enhanced but does not have granted consent, so it was not copied.');
        }
    }

    private static function place(string $table, int $line): string
    {
        return $line > 0 ? sprintf('%s row %d', $table, $line) : 'A '.$table.' row';
    }

    private static function integer(mixed $value, int $min): int
    {
        if (is_int($value)) {
            $int = $value;
        } elseif (is_string($value) && preg_match('/^(0|[1-9][0-9]{0,9})$/D', $value) === 1) {
            $int = (int) $value;
        } else {
            throw new DataTransferException('a number has the wrong type');
        }
        if ($int < $min || $int > 2147483647) {
            throw new DataTransferException('a number is outside the range the column can store');
        }

        return $int;
    }

    private static function bigint(mixed $value): string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (!is_string($value) || preg_match('/^(0|[1-9][0-9]{0,18})$/D', $value) !== 1) {
            throw new DataTransferException('an event count is not a whole number');
        }
        if (strlen($value) === 19 && strcmp($value, '9223372036854775807') > 0) {
            throw new DataTransferException('an event count is outside the range the column can store');
        }

        return $value;
    }

    private static function dateTime(mixed $value): \DateTimeImmutable
    {
        if (!is_string($value)) {
            throw new DataTransferException('a timestamp is missing or not UTC');
        }
        $normalized = preg_replace('/\.\d+/', '', $value);
        if (!is_string($normalized) || strlen($normalized) > 32) {
            throw new DataTransferException('a timestamp is missing or not UTC');
        }
        $utc = new \DateTimeZone('UTC');
        foreach (['!Y-m-d\TH:i:s\Z', '!Y-m-d H:i:s'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat($format, $normalized, $utc);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($parsed instanceof \DateTimeImmutable && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $parsed;
            }
        }

        throw new DataTransferException('a timestamp is missing or not UTC');
    }

    private static function date(mixed $value): \DateTimeImmutable
    {
        if (!is_string($value) || strlen($value) > 10) {
            throw new DataTransferException('a date is missing or not a UTC date');
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$parsed instanceof \DateTimeImmutable || ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))) {
            throw new DataTransferException('a date is missing or not a UTC date');
        }

        return $parsed;
    }

    private static function text(mixed $value, int $max): string
    {
        if (!is_string($value) || strlen($value) > $max || !self::isPlain($value)) {
            throw new DataTransferException('a text value is too long or not plain text');
        }

        return $value;
    }

    private static function token(mixed $value): string
    {
        if (!is_string($value) || $value === '' || strlen($value) > 191 || !self::isPlain($value)) {
            throw new DataTransferException('a website token is missing or not plain text');
        }

        return $value;
    }

    private static function eventName(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[A-Za-z][A-Za-z0-9_.:-]{0,190}$/D', $value) !== 1) {
            throw new DataTransferException('an event name is missing or not a safe event name');
        }

        return $value;
    }

    /** @param list<string> $allowed */
    private static function choice(mixed $value, array $allowed): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new DataTransferException('a value is not one of the allowed choices');
        }

        return $value;
    }

    private static function geoArea(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^(country|continent):([A-Z]{2})$/D', $value, $matches) !== 1 || strlen($value) > 16) {
            throw new DataTransferException('a geography area is not a coarse area code');
        }
        if ($matches[1] === 'continent' && !in_array($matches[2], GeoArea::CONTINENT_CODES, true)) {
            throw new DataTransferException('a geography area is not a coarse area code');
        }

        return $value;
    }

    private static function path(mixed $value): string
    {
        if (!is_string($value) || !self::isSanitizedPath($value, 512)) {
            throw new DataTransferException('a page path is not a sanitized path');
        }

        return $value;
    }

    private static function cellKey(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[A-Fa-f0-9]{64}$/D', $value) !== 1) {
            throw new DataTransferException('an archive cell key is not a 64-character key');
        }

        return $value;
    }

    private static function username(mixed $value): string
    {
        if (!is_string($value) || $value === '' || strlen($value) > 180 || !self::isPlain($value)) {
            throw new DataTransferException('a username is missing or not plain text');
        }

        return $value;
    }

    private static function secret(mixed $value): string
    {
        if (!is_string($value) || $value === '' || strlen($value) > 255 || !self::isPlain($value)) {
            throw new DataTransferException('a password hash is missing or not a stored hash');
        }

        return $value;
    }

    /** @return array<string, mixed>|null */
    private static function customData(mixed $value): ?array
    {
        if ($value === []) {
            return null;
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new DataTransferException('custom data is not a set of named values');
        }
        if (count($value) > 64) {
            throw new DataTransferException('custom data has too many values');
        }
        foreach ($value as $key => $item) {
            if (!is_string($key) || $key === '' || strlen($key) > 191 || !self::isPlain($key)) {
                throw new DataTransferException('custom data has a name that is not plain text');
            }
            if (!self::isScalar($item)) {
                throw new DataTransferException('custom data has a value that is not a single value');
            }
        }
        if (array_key_exists('org_internal_traffic', $value) && !is_bool($value['org_internal_traffic'])) {
            throw new DataTransferException('custom data has an organization-traffic flag that is not true or false');
        }
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($encoded) > 131072) {
            throw new DataTransferException('custom data is larger than the transfer allows');
        }

        return $value;
    }

    /** @return list<string> */
    private static function roles(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 20) {
            throw new DataTransferException('roles are not a list of role names');
        }
        foreach ($value as $role) {
            if (!is_string($role) || preg_match('/^ROLE_[A-Z0-9_]{1,60}$/D', $role) !== 1) {
                throw new DataTransferException('a role is not a role name');
            }
        }

        return $value;
    }

    private static function isScalar(mixed $value): bool
    {
        if ($value === null || is_bool($value)) {
            return true;
        }
        if (is_int($value)) {
            return true;
        }
        if (is_float($value)) {
            return is_finite($value);
        }

        return is_string($value) && strlen($value) <= 8192 && self::isPlain($value);
    }

    private static function isSanitizedPath(string $value, int $max): bool
    {
        return $value !== ''
            && strlen($value) <= $max
            && str_starts_with($value, '/')
            && strpbrk($value, '?#') === false
            && self::isPlain($value);
    }

    private static function isPlain(string $value): bool
    {
        return mb_check_encoding($value, 'UTF-8') && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }

    private static function formatDateTime(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        }
        if (!is_string($value)) {
            return $value;
        }
        $normalized = preg_replace('/\.\d+/', '', $value) ?? $value;
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $normalized, new \DateTimeZone('UTC'))
            ?: \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $normalized, new \DateTimeZone('UTC'));

        return $parsed instanceof \DateTimeImmutable ? $parsed->format('Y-m-d\TH:i:s\Z') : $value;
    }

    private static function formatDate(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');
        }
        if (!is_string($value)) {
            return $value;
        }

        return strlen($value) >= 10 ? substr($value, 0, 10) : $value;
    }

    private static function decodeStoredJson(mixed $value, bool $emptyAsNull): mixed
    {
        if (is_array($value)) {
            return $value === [] && $emptyAsNull ? null : $value;
        }
        if (!is_string($value) || $value === '') {
            return $value;
        }
        try {
            $decoded = json_decode($value, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $value;
        }

        return $decoded === [] && $emptyAsNull ? null : $decoded;
    }
}
