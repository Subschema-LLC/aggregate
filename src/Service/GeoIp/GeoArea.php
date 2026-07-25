<?php

declare(strict_types=1);

namespace App\Service\GeoIp;

use Symfony\Component\Intl\Countries;

/** A deliberately narrow geography value safe to pass into event ingestion. */
final readonly class GeoArea
{
    /** @var list<string> */
    private const CONTINENT_CODES = ['AF', 'AN', 'AS', 'EU', 'NA', 'OC', 'SA'];

    private function __construct(private string $value)
    {
    }

    public static function country(mixed $code): ?self
    {
        $code = self::normalizeCode($code);
        if ($code === null || !Countries::exists($code)) {
            return null;
        }

        return new self('country:'.$code);
    }

    public static function continent(mixed $code): ?self
    {
        $code = self::normalizeCode($code);
        if ($code === null || !in_array($code, self::CONTINENT_CODES, true)) {
            return null;
        }

        return new self('continent:'.$code);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    private static function normalizeCode(mixed $code): ?string
    {
        if (!is_string($code)) {
            return null;
        }

        $code = strtoupper(trim($code));

        return preg_match('/^[A-Z]{2}$/D', $code) === 1 ? $code : null;
    }
}
