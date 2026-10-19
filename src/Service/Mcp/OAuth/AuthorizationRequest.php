<?php

declare(strict_types=1);

namespace App\Service\Mcp\OAuth;

/**
 * The verified part of an assistant's sign-in request: the client and the
 * redirect address it registered, plus the state to hand back.
 */
final readonly class AuthorizationRequest
{
    public function __construct(
        public OAuthClient $client,
        public string $redirectUri,
        public ?string $state,
    ) {
    }

    /**
     * The client's redirect with the given parameters, the state and this
     * server's issuer (RFC 9207) added to any query it already has.
     *
     * @param array<string, string> $parameters
     */
    public function redirect(array $parameters, string $issuer): string
    {
        $parameters += array_filter(['state' => $this->state, 'iss' => $issuer], static fn (?string $value): bool => $value !== null);

        return $this->redirectUri.(str_contains($this->redirectUri, '?') ? '&' : '?').http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }
}
