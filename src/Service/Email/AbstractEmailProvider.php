<?php

declare(strict_types=1);

namespace App\Service\Email;

use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;

/**
 * A provider whose transport is described entirely by a DSN for its Symfony
 * Mailer bridge. Building a Dsn object, rather than a DSN string, means a
 * credential never needs URL encoding.
 */
abstract class AbstractEmailProvider implements EmailProvider
{
    /** @param array<string, mixed> $settings validated email settings */
    abstract protected function dsn(array $settings, #[\SensitiveParameter] ?string $credential): Dsn;

    public function transport(Transport $factory, array $settings, #[\SensitiveParameter] ?string $credential): TransportInterface
    {
        return $factory->fromDsnObject($this->dsn($settings, $credential));
    }

    public function prepare(Email $email, array $settings): void
    {
    }

    /**
     * A setting that must be one of a few codes.
     *
     * @param array<string, mixed> $values
     * @param list<string>         $allowed
     */
    protected static function choice(array $values, string $key, array $allowed): string
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
    protected static function text(array $values, string $key, int $maxLength = 255): string
    {
        $value = $values[$key] ?? '';
        if (!is_string($value) || strlen($value) > $maxLength || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new \InvalidArgumentException(sprintf('%s must be text of at most %d characters.', $key, $maxLength));
        }

        return trim($value);
    }

    /** Whether a value is a host name such as mg.example.com (letters, digits, hyphens and dots). */
    protected static function isHostName(string $value): bool
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
