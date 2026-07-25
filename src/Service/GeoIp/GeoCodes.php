<?php

declare(strict_types=1);

namespace App\Service\GeoIp;

/** The only two fields allowed to leave the low-level MMDB adapter. */
final readonly class GeoCodes
{
    private ?string $countryCode;
    private ?string $continentCode;

    public function __construct(
        mixed $countryCode,
        mixed $continentCode,
    ) {
        $this->countryCode = self::normalizeCandidate($countryCode);
        $this->continentCode = self::normalizeCandidate($continentCode);
    }

    public function countryArea(): ?GeoArea
    {
        return GeoArea::country($this->countryCode);
    }

    public function continentArea(): ?GeoArea
    {
        return GeoArea::continent($this->continentCode);
    }

    private static function normalizeCandidate(mixed $code): ?string
    {
        if (!is_string($code)) {
            return null;
        }

        $code = strtoupper(trim($code));

        return preg_match('/^[A-Z]{2}$/D', $code) === 1 ? $code : null;
    }
}
