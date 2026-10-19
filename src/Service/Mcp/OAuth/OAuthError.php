<?php

declare(strict_types=1);

namespace App\Service\Mcp\OAuth;

/**
 * An OAuth error answered as RFC 6749 section 5.2 JSON: a standard error code
 * and a description that names parameters, never a token or a secret.
 */
final class OAuthError extends \RuntimeException
{
    public const CODES = [
        'invalid_request',
        'invalid_client',
        'invalid_grant',
        'unauthorized_client',
        'unsupported_grant_type',
        'unsupported_response_type',
        'invalid_scope',
        'invalid_target',
        'invalid_redirect_uri',
        'invalid_client_metadata',
        'access_denied',
        'temporarily_unavailable',
        'server_error',
    ];

    public function __construct(
        public readonly string $error,
        string $description,
        public readonly int $status = 400,
        ?\Throwable $previous = null,
    ) {
        if (!in_array($error, self::CODES, true)) {
            throw new \LogicException(sprintf('Unknown OAuth error code "%s".', $error));
        }
        parent::__construct($description, 0, $previous);
    }

    /** @return array{error: string, error_description: string} */
    public function toArray(): array
    {
        return ['error' => $this->error, 'error_description' => $this->getMessage()];
    }
}
