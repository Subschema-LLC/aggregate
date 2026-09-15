<?php

declare(strict_types=1);

namespace App\Service\GeoIp;

use App\Service\AggregateConfigLoader;
use Symfony\Component\HttpFoundation\IpUtils;

/** Resolves only public client addresses using an explicitly enabled local DB. */
final class ConfiguredGeoIpResolver implements GeoIpResolverInterface
{
    /**
     * Extra non-public/special-use ranges not consistently rejected by PHP's
     * FILTER_FLAG_NO_PRIV_RANGE and FILTER_FLAG_NO_RES_RANGE.
     *
     * @var list<string>
     */
    private const NON_PUBLIC_RANGES = [
        '100.64.0.0/10',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.88.99.0/24',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '2001:db8::/32',
        '64:ff9b:1::/48',
        '100::/64',
        '2001:2::/48',
        '2001:10::/28',
        '2001:20::/28',
        '3fff::/20',
        '5f00::/16',
    ];

    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly MmdbGeoCodeLookupInterface $lookup,
    ) {
    }

    public function resolve(string $ipAddress): ?GeoArea
    {
        try {
            if ($this->config->hasLoadError()
                || !$this->config->getBoolWithEnvFallback('anonymous_geo_enabled', false)) {
                return null;
            }

            $ipAddress = $this->normalizePublicIp($ipAddress);
            if ($ipAddress === null) {
                return null;
            }

            $level = $this->config->getWithEnvFallback('anonymous_geo_level', 'macro_region');
            $databasePath = $this->config->getWithEnvFallback('anonymous_geo_database_path', '');
            if (!is_string($level) || !is_string($databasePath)) {
                return null;
            }

            $level = strtolower(trim($level));
            $databasePath = trim($databasePath);
            if (!in_array($level, ['macro_region', 'country'], true) || $databasePath === '') {
                return null;
            }

            $codes = $this->lookup->lookup($databasePath, $ipAddress);
            if ($codes === null) {
                return null;
            }

            // Do not fall back to a different granularity: mixed geo areas make
            // BI cells harder to reason about and can weaken suppression.
            return $level === 'country'
                ? $codes->countryArea()
                : $codes->continentArea();
        } catch (\Throwable) {
            // Geography is optional and fail-closed. Do not log exceptions here:
            // they can carry an address or a detailed database record.
            return null;
        }
    }

    private function normalizePublicIp(string $ipAddress): ?string
    {
        // PHP treats IPv4-mapped IPv6 private addresses as public under the
        // filter flags, so reduce mapped addresses before validating ranges.
        if (filter_var($ipAddress, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $packed = inet_pton($ipAddress);
        if ($packed === false) {
            return null;
        }

        if (strlen($packed) === 16
            && substr($packed, 0, 10) === str_repeat("\0", 10)
            && substr($packed, 10, 2) === "\xff\xff") {
            $mappedAddress = inet_ntop(substr($packed, 12));
            if ($mappedAddress === false) {
                return null;
            }

            $ipAddress = $mappedAddress;
        }

        if (filter_var(
            $ipAddress,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false) {
            return null;
        }

        return IpUtils::checkIp($ipAddress, self::NON_PUBLIC_RANGES) ? null : $ipAddress;
    }
}
