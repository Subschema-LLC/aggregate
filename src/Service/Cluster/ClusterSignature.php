<?php

declare(strict_types=1);

namespace App\Service\Cluster;

use Symfony\Component\HttpFoundation\Request;

/**
 * Signs requests between the servers of a cluster with CLUSTER_KEY: an
 * HMAC-SHA256 over the method, path, time, server name and the SHA-256 of
 * the body. A signature more than a minute away from this server's clock is
 * refused, so a captured request cannot be replayed later.
 */
final class ClusterSignature
{
    public const SERVER_HEADER = 'X-Aggregate-Cluster-Server';
    public const TIME_HEADER = 'X-Aggregate-Cluster-Time';
    public const SIGNATURE_HEADER = 'X-Aggregate-Cluster-Signature';
    public const MAX_SKEW_SECONDS = 60;

    /** @return array<string, string> the headers that sign this request */
    public static function headers(#[\SensitiveParameter] string $key, string $method, string $path, string $server, int $time, string $body = ''): array
    {
        return [
            self::SERVER_HEADER => $server,
            self::TIME_HEADER => (string) $time,
            self::SIGNATURE_HEADER => self::sign($key, $method, $path, $server, $time, $body),
        ];
    }

    public static function sign(#[\SensitiveParameter] string $key, string $method, string $path, string $server, int $time, string $body = ''): string
    {
        $canonical = implode("\n", [strtoupper($method), $path, (string) $time, $server, hash('sha256', $body)]);

        return rtrim(strtr(base64_encode(hash_hmac('sha256', $canonical, $key, true)), '+/', '-_'), '=');
    }

    /**
     * The server name a request is signed by, or null when it is not signed,
     * is signed with another key, or is too old or too new.
     */
    public static function verify(#[\SensitiveParameter] ?string $key, Request $request, int $now): ?string
    {
        $server = $request->headers->get(self::SERVER_HEADER);
        $time = $request->headers->get(self::TIME_HEADER);
        $signature = $request->headers->get(self::SIGNATURE_HEADER);
        if ($key === null || !is_string($server) || !is_string($time) || !is_string($signature) || !ctype_digit($time)) {
            return null;
        }
        if (abs($now - (int) $time) > self::MAX_SKEW_SECONDS) {
            return null;
        }
        $expected = self::sign($key, $request->getMethod(), $request->getPathInfo(), $server, (int) $time, (string) $request->getContent());

        return hash_equals($expected, $signature) ? $server : null;
    }
}
