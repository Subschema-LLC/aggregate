<?php

declare(strict_types=1);

namespace App\Entities\Destination;

/**
 * The address a webhook destination posts to: HTTPS, no credentials or
 * fragment, and never localhost, private, loopback, link-local or reserved.
 */
final class WebhookUrl
{
    public const MAX_LENGTH = 2000;

    private const LOCAL_SUFFIXES = [
        '.localhost', '.local', '.internal', '.home.arpa', '.localdomain',
    ];

    public static function check(mixed $url): string
    {
        if (!is_string($url)) {
            throw new \InvalidArgumentException('Webhook URL must be an HTTPS web address.');
        }
        $url = trim($url);
        if ($url === '' || strlen($url) > self::MAX_LENGTH
            || preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
            throw new \InvalidArgumentException(sprintf(
                'Webhook URL must be a valid HTTPS address of at most %d characters.',
                self::MAX_LENGTH,
            ));
        }

        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException('Webhook URL must be a full HTTPS web address.');
        }
        if (strtolower($parts['scheme']) !== 'https') {
            throw new \InvalidArgumentException('Webhook URLs must use HTTPS.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException(
                'Webhook URLs cannot contain a user name or password.',
            );
        }
        if (isset($parts['fragment']) || str_contains($url, '#')) {
            throw new \InvalidArgumentException('Webhook URLs cannot contain a fragment (#).');
        }

        $host = strtolower(trim($parts['host'], '[]'));
        if ($host === '' || self::isPrivateOrLocalHost($host)) {
            throw new \InvalidArgumentException(
                'Webhook URLs cannot target localhost, loopback, link-local, '
                .'or private network addresses.',
            );
        }

        return $url;
    }

    /** The lower-case host of a URL that passed check(). */
    public static function hostOf(string $url): string
    {
        return strtolower((string) parse_url($url, PHP_URL_HOST));
    }

    public static function isPrivateOrLocalHost(string $host): bool
    {
        $host = strtolower(trim($host, " \t\n\r\0\x0B.[]"));
        if ($host === '' || $host === 'localhost') {
            return true;
        }
        foreach (self::LOCAL_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        // IPv4-mapped IPv6 literals such as ::ffff:127.0.0.1 or ::ffff:10.0.0.1.
        if (str_starts_with($host, '::ffff:')) {
            $mapped = substr($host, 7);
            if (filter_var($mapped, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return self::isPrivateOrReservedIpv4($mapped);
            }
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return self::isPrivateOrReservedIpv4($host);
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            foreach (['fe8', 'fe9', 'fea', 'feb'] as $linkLocal) {
                if (str_starts_with($host, $linkLocal)) {
                    return true;
                }
            }
            $public = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;

            return filter_var($host, FILTER_VALIDATE_IP, $public) === false;
        }

        // Numeric, octal and shorthand IPv4 forms such as 127.1 or 2130706433.
        return preg_match('/^[0-9.]+$/D', $host) === 1
            || preg_match('/^0x[0-9a-f.]+$/Di', $host) === 1;
    }

    private static function isPrivateOrReservedIpv4(string $ipv4): bool
    {
        $public = FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        if (filter_var($ipv4, FILTER_VALIDATE_IP, $public) === false) {
            return true;
        }
        $long = ip2long($ipv4);

        // RFC 6598 Carrier-Grade NAT (100.64.0.0/10: 100.64.0.0 - 100.127.255.255).
        return $long !== false && ($long & 0xFFC00000) === 0x64400000;
    }
}
