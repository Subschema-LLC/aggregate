<?php

declare(strict_types=1);

namespace App\Service\GeoIp;

interface GeoIpResolverInterface
{
    /** Returns only a configured coarse area, or null when it cannot be derived safely. */
    public function resolve(string $ipAddress): ?GeoArea;
}
