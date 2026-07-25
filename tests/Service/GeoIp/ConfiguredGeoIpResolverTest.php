<?php

declare(strict_types=1);

namespace App\Tests\Service\GeoIp;

use App\Service\AggregateConfigLoader;
use App\Service\GeoIp\ConfiguredGeoIpResolver;
use App\Service\GeoIp\GeoCodes;
use App\Service\GeoIp\MmdbGeoCodeLookupInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ConfiguredGeoIpResolverTest extends TestCase
{
    public function testCountryModeReturnsOnlyTheIsoCountry(): void
    {
        $lookup = $this->createMock(MmdbGeoCodeLookupInterface::class);
        $lookup->expects(self::once())
            ->method('lookup')
            ->with('var/geo/GeoLite2-Country.mmdb', '8.8.8.8')
            ->willReturn(new GeoCodes('us', 'na'));

        $area = (new ConfiguredGeoIpResolver($this->config(), $lookup))->resolve('8.8.8.8');

        self::assertNotNull($area);
        self::assertSame('country:US', $area->value());
    }

    public function testMacroRegionModeReturnsOnlyTheContinent(): void
    {
        $lookup = $this->createStub(MmdbGeoCodeLookupInterface::class);
        $lookup->method('lookup')->willReturn(new GeoCodes('DE', 'eu'));

        $area = (new ConfiguredGeoIpResolver(
            $this->config(['anonymous_geo_level' => 'macro_region']),
            $lookup,
        ))->resolve('2001:4860:4860::8888');

        self::assertNotNull($area);
        self::assertSame('continent:EU', $area->value());
    }

    public function testConfiguredGranularityNeverFallsBackToTheOtherCode(): void
    {
        $lookup = $this->createStub(MmdbGeoCodeLookupInterface::class);
        $lookup->method('lookup')->willReturn(new GeoCodes(null, 'NA'));

        $area = (new ConfiguredGeoIpResolver($this->config(), $lookup))->resolve('8.8.8.8');

        self::assertNull($area);
    }

    public function testDisabledGeoDoesNotPerformALookup(): void
    {
        $lookup = $this->createMock(MmdbGeoCodeLookupInterface::class);
        $lookup->expects(self::never())->method('lookup');

        $area = (new ConfiguredGeoIpResolver(
            $this->config(['anonymous_geo_enabled' => false]),
            $lookup,
        ))->resolve('8.8.8.8');

        self::assertNull($area);
    }

    public function testConfigurationFailureDoesNotPerformALookup(): void
    {
        $lookup = $this->createMock(MmdbGeoCodeLookupInterface::class);
        $lookup->expects(self::never())->method('lookup');

        $area = (new ConfiguredGeoIpResolver(
            $this->config(loadError: true),
            $lookup,
        ))->resolve('8.8.8.8');

        self::assertNull($area);
    }

    #[DataProvider('nonPublicAddresses')]
    public function testInvalidPrivateAndSpecialUseAddressesAreNeverLookedUp(string $ipAddress): void
    {
        $lookup = $this->createMock(MmdbGeoCodeLookupInterface::class);
        $lookup->expects(self::never())->method('lookup');

        $area = (new ConfiguredGeoIpResolver($this->config(), $lookup))->resolve($ipAddress);

        self::assertNull($area);
    }

    /** @return iterable<string, array{string}> */
    public static function nonPublicAddresses(): iterable
    {
        yield 'malformed' => ['not-an-address'];
        yield 'unspecified IPv4' => ['0.0.0.0'];
        yield 'private IPv4' => ['10.0.0.1'];
        yield 'loopback IPv4' => ['127.0.0.1'];
        yield 'shared carrier space' => ['100.64.0.1'];
        yield 'documentation IPv4' => ['192.0.2.1'];
        yield 'benchmark IPv4' => ['198.18.0.1'];
        yield 'unspecified IPv6' => ['::'];
        yield 'loopback IPv6' => ['::1'];
        yield 'private IPv4 mapped into IPv6' => ['::ffff:10.0.0.1'];
        yield 'unique-local IPv6' => ['fd00::1'];
        yield 'documentation IPv6' => ['2001:db8::1'];
    }

    public function testPublicIpv4MappedAddressIsReducedBeforeLookup(): void
    {
        $lookup = $this->createMock(MmdbGeoCodeLookupInterface::class);
        $lookup->expects(self::once())
            ->method('lookup')
            ->with('var/geo/GeoLite2-Country.mmdb', '8.8.8.8')
            ->willReturn(new GeoCodes('US', 'NA'));

        $area = (new ConfiguredGeoIpResolver($this->config(), $lookup))
            ->resolve('::ffff:8.8.8.8');

        self::assertSame('country:US', $area?->value());
    }

    public function testInvalidLevelOrDatabasePathFailsWithoutLookup(): void
    {
        $lookup = $this->createMock(MmdbGeoCodeLookupInterface::class);
        $lookup->expects(self::never())->method('lookup');

        $invalidLevel = new ConfiguredGeoIpResolver(
            $this->config(['anonymous_geo_level' => 'city']),
            $lookup,
        );
        $emptyPath = new ConfiguredGeoIpResolver(
            $this->config(['anonymous_geo_database_path' => '']),
            $lookup,
        );

        self::assertNull($invalidLevel->resolve('8.8.8.8'));
        self::assertNull($emptyPath->resolve('8.8.8.8'));
    }

    public function testLookupExceptionsAreSilentlyReducedToUnknown(): void
    {
        $lookup = $this->createStub(MmdbGeoCodeLookupInterface::class);
        $lookup->method('lookup')->willThrowException(new \RuntimeException('sensitive lookup detail'));

        $area = (new ConfiguredGeoIpResolver($this->config(), $lookup))->resolve('8.8.8.8');

        self::assertNull($area);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function config(array $overrides = [], bool $loadError = false): AggregateConfigLoader&MockObject
    {
        $settings = array_replace([
            'anonymous_geo_enabled' => true,
            'anonymous_geo_level' => 'country',
            'anonymous_geo_database_path' => 'var/geo/GeoLite2-Country.mmdb',
        ], $overrides);

        $config = $this->createMock(AggregateConfigLoader::class);
        $config->method('hasLoadError')->willReturn($loadError);
        $config->method('getBoolWithEnvFallback')
            ->willReturnCallback(static fn (string $key, bool $default = false): bool =>
                is_bool($settings[$key] ?? null) ? $settings[$key] : $default);
        $config->method('getWithEnvFallback')
            ->willReturnCallback(static fn (string $key, mixed $default = null): mixed =>
                array_key_exists($key, $settings) ? $settings[$key] : $default);

        return $config;
    }
}
