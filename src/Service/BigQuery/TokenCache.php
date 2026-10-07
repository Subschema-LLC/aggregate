<?php

declare(strict_types=1);

namespace App\Service\BigQuery;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Keeps one access token per credentials object until shortly before it
 * expires, and turns Google token responses into tokens or safe errors.
 */
final class TokenCache
{
    private ?string $token = null;
    private int $expiresAt = 0;

    /** @param \Closure(): int $clock */
    public function __construct(private readonly \Closure $clock)
    {
    }

    /** @param callable(): array{0: string, 1: int} $request Returns the token and its lifetime in seconds. */
    public function get(callable $request): string
    {
        $now = ($this->clock)();
        if ($this->token === null || $now >= $this->expiresAt - 120) {
            [$this->token, $lifetime] = $request();
            $this->expiresAt = $now + max(60, $lifetime);
        }

        return $this->token;
    }

    /**
     * @param ResponseInterface|\Closure(): ResponseInterface $response
     *
     * @return array{0: string, 1: int}
     *
     * @throws BigQueryException
     */
    public static function parse(ResponseInterface|\Closure $response, string $failure): array
    {
        try {
            if ($response instanceof \Closure) {
                $response = $response();
            }
            $status = $response->getStatusCode();
            $body = $response->toArray(false);
        } catch (ExceptionInterface $e) {
            throw new BigQueryException('Google could not be reached to sign in: '.self::transportMessage($e).' Check the server’s outbound HTTPS access to Google.', previous: $e);
        }
        $token = $body['access_token'] ?? null;
        if ($status !== 200 || !is_string($token) || $token === '') {
            $error = is_string($body['error'] ?? null) ? $body['error'] : 'HTTP '.$status;
            $description = is_string($body['error_description'] ?? null) ? $body['error_description'] : '';

            throw new BigQueryException(trim($failure.' Google answered: '.self::clean($error.($description !== '' ? ' ('.$description.')' : ''))), $status);
        }
        $lifetime = $body['expires_in'] ?? 3600;

        return [$token, is_int($lifetime) ? $lifetime : (int) (is_numeric($lifetime) ? $lifetime : 3600)];
    }

    /** A short, single-line message from Google or a transport, without control characters. */
    public static function clean(string $message, int $length = 300): string
    {
        $message = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $message));

        return mb_strlen($message) > $length ? mb_substr($message, 0, $length - 1).'…' : $message;
    }

    public static function transportMessage(\Throwable $e): string
    {
        // Transport errors can include the requested URL; keep only its host.
        return self::clean((string) preg_replace('#https?://([^/\s"]+)[^\s"]*#', '$1', $e->getMessage()), 200);
    }
}
