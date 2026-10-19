<?php

declare(strict_types=1);

namespace App\Service\ReportingApi;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * An error answered by the reporting API as RFC 9457 problem details, with a
 * stable code callers can branch on. Details name parameters, views and
 * columns, never a value from the data or a token.
 */
final class ApiProblem extends \RuntimeException
{
    public const CONTENT_TYPE = 'application/problem+json';

    /** Codes and their titles, so every response with a code has the same title. */
    public const TITLES = [
        'unknown_parameter' => 'Unknown parameter',
        'invalid_parameter' => 'Invalid parameter',
        'unknown_column' => 'Unknown column',
        'unsupported_operator' => 'Unsupported filter operator',
        'invalid_filter_value' => 'Invalid filter value',
        'token_in_query' => 'Token sent in the address',
        'missing_token' => 'Missing API token',
        'invalid_token' => 'Invalid API token',
        'view_not_permitted' => 'View not permitted',
        'private_views_disabled' => 'Private views are turned off',
        'unknown_view' => 'Unknown view',
        'not_found' => 'Not found',
        'method_not_allowed' => 'Method not allowed',
        'rate_limited' => 'Too many requests',
        'view_unavailable' => 'View unavailable',
        'configuration_invalid' => 'API configuration invalid',
        'internal_error' => 'Internal error',
    ];

    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly string $problemCode,
        string $detail,
        public readonly array $headers = [],
        ?\Throwable $previous = null,
    ) {
        if (!isset(self::TITLES[$problemCode])) {
            throw new \LogicException(sprintf('Unknown reporting API problem code "%s".', $problemCode));
        }
        parent::__construct($detail, 0, $previous);
    }

    public static function badRequest(string $code, string $detail): self
    {
        return new self(400, $code, $detail);
    }

    public function title(): string
    {
        return self::TITLES[$this->problemCode];
    }

    /** @param string|null $type a documentation URL for the error codes, or null for about:blank */
    public function toResponse(?string $type = null): JsonResponse
    {
        $response = new JsonResponse([
            'type' => $type ?? 'about:blank',
            'title' => $this->title(),
            'status' => $this->status,
            'code' => $this->problemCode,
            'detail' => $this->getMessage(),
        ], $this->status, $this->headers);
        $response->setEncodingOptions(JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $response->headers->set('Content-Type', self::CONTENT_TYPE);
        $response->headers->set('Cache-Control', 'private, no-store');
        if ($this->status === 401) {
            $response->headers->set('WWW-Authenticate', 'Bearer realm="reporting-api"');
        }

        return $response;
    }
}
