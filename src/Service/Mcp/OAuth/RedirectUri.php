<?php

declare(strict_types=1);

namespace App\Service\Mcp\OAuth;

/**
 * Redirect URI rules for assistant sign-in. A redirect must be HTTPS, or
 * plain HTTP to the loopback address of a native client on the same machine
 * (RFC 8252), and carries no fragment. A request matches a registered
 * redirect exactly, except that a loopback redirect matches on any port:
 * native clients, such as Claude Code, listen on a port chosen per session.
 */
final class RedirectUri
{
    public const MAX_LENGTH = 2000;
    private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

    /** @throws \InvalidArgumentException naming the problem */
    public static function assertValid(mixed $uri): string
    {
        if (!is_string($uri) || $uri === '' || strlen($uri) > self::MAX_LENGTH || preg_match('/[\x00-\x20\x7f]/', $uri) === 1) {
            throw new \InvalidArgumentException('Each redirect URI must be an absolute URL of at most '.self::MAX_LENGTH.' characters, without spaces.');
        }
        $parts = parse_url($uri);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException('Each redirect URI must be an absolute URL.');
        }
        if (isset($parts['fragment']) || str_contains($uri, '#')) {
            throw new \InvalidArgumentException('A redirect URI cannot contain a fragment (#).');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('A redirect URI cannot contain a user name or password.');
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme === 'http' && self::isLoopbackHost($parts['host'])) {
            return $uri;
        }
        if ($scheme !== 'https') {
            throw new \InvalidArgumentException('A redirect URI must use HTTPS, or HTTP to localhost or 127.0.0.1 for an assistant on the same computer.');
        }

        return $uri;
    }

    public static function matches(string $registered, string $requested): bool
    {
        if (hash_equals($registered, $requested)) {
            return true;
        }
        $a = parse_url($registered);
        $b = parse_url($requested);
        if (!is_array($a) || !is_array($b) || strtolower($a['scheme'] ?? '') !== 'http' || strtolower($b['scheme'] ?? '') !== 'http') {
            return false;
        }
        if (!self::isLoopbackHost($a['host'] ?? '') || strtolower($a['host'] ?? '') !== strtolower($b['host'] ?? '')) {
            return false;
        }
        foreach (['user', 'pass', 'fragment'] as $part) {
            if (isset($a[$part]) || isset($b[$part])) {
                return false;
            }
        }

        return ($a['path'] ?? '') === ($b['path'] ?? '') && ($a['query'] ?? null) === ($b['query'] ?? null);
    }

    public static function host(string $uri): string
    {
        $host = parse_url($uri, PHP_URL_HOST);

        return is_string($host) ? strtolower($host) : '';
    }

    private static function isLoopbackHost(string $host): bool
    {
        return in_array(strtolower($host), self::LOOPBACK_HOSTS, true);
    }
}
