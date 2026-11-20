<?php

declare(strict_types=1);

namespace App\Tests\Entities\TrafficClass;

use App\Entities\TrafficClass\IpRanges;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IpRangesTest extends TestCase
{
    public function testFindsAddressesInsideIpv4AndIpv6Ranges(): void
    {
        $ranges = IpRanges::fromList(['192.0.2.0/24', '198.51.100.7/32', '2001:db8:10::/48']);

        self::assertTrue($ranges->contains('192.0.2.0'));
        self::assertTrue($ranges->contains('192.0.2.255'));
        self::assertTrue($ranges->contains('198.51.100.7'));
        self::assertTrue($ranges->contains('2001:db8:10:ffff::1'));
        self::assertFalse($ranges->contains('192.0.3.1'));
        self::assertFalse($ranges->contains('198.51.100.8'));
        self::assertFalse($ranges->contains('2001:db8:11::1'));
    }

    public function testReadsIpv4MappedIpv6AddressesAsIpv4(): void
    {
        $ranges = IpRanges::fromList(['203.0.113.0/25']);

        self::assertTrue($ranges->contains('::ffff:203.0.113.9'));
        self::assertFalse($ranges->contains('::ffff:203.0.113.200'));
    }

    public function testPrefixesThatAreNotByteAlignedMaskCorrectly(): void
    {
        $ranges = IpRanges::fromList(['10.20.32.0/19', '2001:db8:8000::/33']);

        self::assertTrue($ranges->contains('10.20.63.255'));
        self::assertFalse($ranges->contains('10.20.64.0'));
        self::assertTrue($ranges->contains('2001:db8:ffff::1'));
        self::assertFalse($ranges->contains('2001:db8:7fff::1'));
    }

    public function testCanonicalisesAndDeduplicatesRanges(): void
    {
        $ranges = IpRanges::fromList(['192.0.2.77/24', '192.0.2.0/24', '198.51.100.1']);

        self::assertSame(2, $ranges->count());
        self::assertEqualsCanonicalizing(['192.0.2.0/24', '198.51.100.1/32'], $ranges->toList());
    }

    public function testIgnoresValuesThatAreNotAddresses(): void
    {
        $ranges = IpRanges::fromList(['0.0.0.0/0']);

        self::assertTrue($ranges->contains('8.8.8.8'));
        self::assertFalse($ranges->contains('not-an-ip'));
        self::assertFalse($ranges->contains(''));
        self::assertFalse($ranges->contains('2001:db8::1'));
    }

    #[DataProvider('invalidRanges')]
    public function testRefusesInvalidRanges(string $cidr): void
    {
        $this->expectException(\InvalidArgumentException::class);
        IpRanges::fromList([$cidr]);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidRanges(): iterable
    {
        yield 'not an address' => ['example.com/24'];
        yield 'IPv4 prefix too long' => ['192.0.2.0/33'];
        yield 'IPv6 prefix too long' => ['2001:db8::/129'];
        yield 'negative prefix' => ['192.0.2.0/-1'];
        yield 'two slashes' => ['192.0.2.0/24/8'];
        yield 'empty' => [''];
    }
}
