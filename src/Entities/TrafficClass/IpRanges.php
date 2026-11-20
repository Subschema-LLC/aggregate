<?php

declare(strict_types=1);

namespace App\Entities\TrafficClass;

/**
 * A set of IPv4 and IPv6 CIDR ranges that answers whether an address is in
 * one of them. Each range is kept as its masked network, grouped by prefix
 * length, so a lookup costs one masked comparison per prefix length in use.
 * Addresses are only compared in memory; nothing here keeps them.
 */
final class IpRanges
{
    /** More ranges than any published list holds; protects memory. */
    public const MAX_RANGES = 50_000;

    /** @var array<int, array<string, true>> prefix length + 1000 for IPv6 => networks */
    private array $networks = [];

    private int $count = 0;

    private function __construct()
    {
    }

    /**
     * @param list<string> $cidrs ranges such as 192.0.2.0/24 or 2001:db8::/32
     *
     * @throws \InvalidArgumentException when an entry is not a valid range,
     *                                   or there are too many of them
     */
    public static function fromList(array $cidrs): self
    {
        if (count($cidrs) > self::MAX_RANGES) {
            throw new \InvalidArgumentException(sprintf(
                'A range list may hold at most %d ranges.',
                self::MAX_RANGES,
            ));
        }
        $ranges = new self();
        foreach ($cidrs as $cidr) {
            [$network, $bits] = self::parse($cidr);
            $key = strlen($network) === 4 ? $bits : 1000 + $bits;
            if (!isset($ranges->networks[$key][$network])) {
                $ranges->networks[$key][$network] = true;
                ++$ranges->count;
            }
        }
        krsort($ranges->networks);

        return $ranges;
    }

    public function contains(string $ip): bool
    {
        $packed = self::pack($ip);
        if ($packed === null) {
            return false;
        }
        $offset = strlen($packed) === 4 ? 0 : 1000;
        foreach ($this->networks as $key => $networks) {
            $bits = $key - $offset;
            if ($bits < 0 || $bits > strlen($packed) * 8) {
                continue;
            }
            if (isset($networks[self::mask($packed, $bits)])) {
                return true;
            }
        }

        return false;
    }

    public function count(): int
    {
        return $this->count;
    }

    /** @return list<string> the ranges in canonical form, masked to their prefix */
    public function toList(): array
    {
        $list = [];
        foreach ($this->networks as $key => $networks) {
            $bits = $key >= 1000 ? $key - 1000 : $key;
            foreach (array_keys($networks) as $network) {
                $list[] = inet_ntop((string) $network).'/'.$bits;
            }
        }

        return $list;
    }

    /** @return array{0: string, 1: int} the masked network and prefix length */
    private static function parse(mixed $cidr): array
    {
        if (!is_string($cidr) || substr_count($cidr, '/') > 1) {
            throw new \InvalidArgumentException('A range must be written as address/prefix.');
        }
        $parts = explode('/', trim($cidr));
        $packed = self::pack($parts[0]);
        if ($packed === null) {
            throw new \InvalidArgumentException(sprintf('"%s" is not an IP range.', $cidr));
        }
        $max = strlen($packed) * 8;
        $bits = $parts[1] ?? (string) $max;
        if (preg_match('/^\d{1,3}$/', $bits) !== 1 || (int) $bits > $max) {
            throw new \InvalidArgumentException(sprintf('"%s" has an invalid prefix.', $cidr));
        }

        return [self::mask($packed, (int) $bits), (int) $bits];
    }

    /** The packed address, with IPv4-mapped IPv6 read as IPv4, or null. */
    private static function pack(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $packed = inet_pton($ip);
        if ($packed === false) {
            return null;
        }
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            return substr($packed, 12);
        }

        return $packed;
    }

    private static function mask(string $packed, int $bits): string
    {
        $whole = intdiv($bits, 8);
        $masked = substr($packed, 0, $whole);
        if ($bits % 8 !== 0) {
            $masked .= chr(ord($packed[$whole]) & (0xFF << (8 - $bits % 8)) & 0xFF);
        }

        return str_pad($masked, strlen($packed), "\0");
    }
}
