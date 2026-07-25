<?php

declare(strict_types=1);

namespace App\Service\GeoIp;

/** @internal Keeps detailed database records behind a narrow reduction boundary. */
interface MmdbGeoCodeLookupInterface
{
    public function lookup(string $databasePath, string $ipAddress): ?GeoCodes;
}
