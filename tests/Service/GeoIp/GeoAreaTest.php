<?php

declare(strict_types=1);

namespace App\Tests\Service\GeoIp;

use App\Service\GeoIp\GeoArea;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GeoAreaTest extends TestCase
{
    public function testCountryIsNormalizedToACanonicalValue(): void
    {
        $area = GeoArea::country(' us ');

        self::assertNotNull($area);
        self::assertSame('country:US', $area->value());
        self::assertSame('country:US', (string) $area);
    }

    #[DataProvider('invalidCountryCodes')]
    public function testOnlyIsoCountryCodesAreAccepted(mixed $code): void
    {
        self::assertNull(GeoArea::country($code));
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidCountryCodes(): iterable
    {
        yield 'unknown alpha code' => ['ZZ'];
        yield 'continent is not a country' => ['EU'];
        yield 'too long' => ['USA'];
        yield 'non-string' => [['US']];
        yield 'null' => [null];
    }

    public function testContinentIsNormalizedToACanonicalValue(): void
    {
        $area = GeoArea::continent(' na ');

        self::assertNotNull($area);
        self::assertSame('continent:NA', $area->value());
    }

    #[DataProvider('invalidContinentCodes')]
    public function testOnlyKnownContinentCodesAreAccepted(mixed $code): void
    {
        self::assertNull(GeoArea::continent($code));
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidContinentCodes(): iterable
    {
        yield 'country is not a continent' => ['US'];
        yield 'unknown alpha code' => ['XX'];
        yield 'too long' => ['EUR'];
        yield 'non-string' => [42];
    }
}
