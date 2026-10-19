<?php

declare(strict_types=1);

namespace App\Service\Mcp\OAuth;

use App\Service\AppBranding;
use App\Service\DocumentationLinks;

/**
 * The OAuth 2.1 authorization server assistants sign in through
 * (docs/MCP.md#sign-in), following the MCP authorization specification:
 *
 * - protected resource metadata (RFC 9728) for /mcp, and authorization
 *   server metadata (RFC 8414) naming the endpoints below;
 * - dynamic client registration (RFC 7591) and client ID metadata
 *   documents, for public clients only;
 * - the authorization code grant with PKCE S256, the code approved by an
 *   administrator on the dashboard's sign-in page;
 * - refresh tokens that are replaced on every use, and token revocation
 *   (RFC 7009).
 *
 * Tokens are only ever valid for this installation's /mcp: a resource
 * parameter (RFC 8707) naming anything else is refused. State lives in
 * OAuthStore. Every address is built from the request's own base URL, so
 * the issuer is the installation's public HTTPS address.
 */
class OAuthServer
{
    public const SCOPE = 'mcp';
    public const AUTHORIZE_PATH = '/dashboard/oauth/authorize';
    public const TOKEN_PATH = '/oauth/token';
    public const REGISTER_PATH = '/oauth/register';
    public const REVOKE_PATH = '/oauth/revoke';
    public const MCP_PATH = '/mcp';
    public const RESOURCE_METADATA_PATH = '/.well-known/oauth-protected-resource/mcp';

    public function __construct(
        private readonly OAuthStore $store,
        private readonly ClientResolver $clients,
        private readonly AppBranding $branding,
        private readonly DocumentationLinks $documentation,
    ) {
    }

    /** The URL tokens are issued for: this installation's MCP endpoint. */
    public static function resource(string $baseUrl): string
    {
        return $baseUrl.self::MCP_PATH;
    }

    /** @return array<string, mixed> */
    public function protectedResourceMetadata(string $baseUrl): array
    {
        return array_filter([
            'resource' => self::resource($baseUrl),
            'authorization_servers' => [$baseUrl],
            'bearer_methods_supported' => ['header'],
            'scopes_supported' => [self::SCOPE],
            'resource_name' => $this->branding->getName(),
            'resource_documentation' => $this->documentation->url('mcp'),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @return array<string, mixed> */
    public function authorizationServerMetadata(string $baseUrl): array
    {
        return array_filter([
            'issuer' => $baseUrl,
            'authorization_endpoint' => $baseUrl.self::AUTHORIZE_PATH,
            'token_endpoint' => $baseUrl.self::TOKEN_PATH,
            'registration_endpoint' => $baseUrl.self::REGISTER_PATH,
            'revocation_endpoint' => $baseUrl.self::REVOKE_PATH,
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'revocation_endpoint_auth_methods_supported' => ['none'],
            'scopes_supported' => [self::SCOPE],
            'client_id_metadata_document_supported' => true,
            'authorization_response_iss_parameter_supported' => true,
            'service_documentation' => $this->documentation->url('mcp'),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * Dynamic client registration: the request's metadata, the registered
     * client back. Only public clients using the authorization code grant.
     *
     * @param array<mixed> $metadata
     *
     * @return array<string, mixed>
     *
     * @throws OAuthError
     */
    public function register(array $metadata): array
    {
        $grants = $metadata['grant_types'] ?? ['authorization_code'];
        $responses = $metadata['response_types'] ?? ['code'];
        if (!is_array($grants) || !in_array('authorization_code', $grants, true) || array_diff($grants, ['authorization_code', 'refresh_token']) !== []) {
            throw new OAuthError('invalid_client_metadata', 'grant_types may only be authorization_code and refresh_token.');
        }
        if (!is_array($responses) || $responses !== ['code']) {
            throw new OAuthError('invalid_client_metadata', 'response_types may only be code.');
        }
        $name = $metadata['client_name'] ?? '';
        if (!is_string($name)) {
            throw new OAuthError('invalid_client_metadata', 'client_name must be text.');
        }
        $uris = $metadata['redirect_uris'] ?? null;
        if (!is_array($uris)) {
            throw new OAuthError('invalid_redirect_uri', 'redirect_uris must list the client\'s redirect URIs.');
        }
        $client = $this->store->registerClient($name, array_values($uris));

        return [
            'client_id' => $client->id,
            'client_id_issued_at' => time(),
            'client_name' => $client->name,
            'redirect_uris' => $client->redirectUris,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            // A public client: it proves itself with PKCE, never a secret.
            'token_endpoint_auth_method' => 'none',
            'scope' => self::SCOPE,
        ];
    }

    /**
     * Checks an authorization request in two stages. The client and redirect
     * come first: when either is wrong the error is shown, never sent to an
     * unverified address. Everything else is checked after, and its errors
     * can be returned to the client's redirect.
     *
     * @param array<string, mixed> $query
     *
     * @throws OAuthError a client or redirect problem; never redirected
     */
    public function client(array $query): AuthorizationRequest
    {
        $clientId = $query['client_id'] ?? null;
        if (!is_string($clientId) || $clientId === '') {
            throw new OAuthError('invalid_request', 'The sign-in request has no client_id.');
        }
        $client = $this->clients->resolve($clientId);
        $redirectUri = $query['redirect_uri'] ?? null;
        if (!is_string($redirectUri) || $redirectUri === '') {
            // Optional in OAuth when one is registered, but required by OAuth 2.1 with PKCE.
            throw new OAuthError('invalid_request', 'The sign-in request has no redirect_uri.');
        }
        if (!$client->allowsRedirect($redirectUri)) {
            throw new OAuthError('invalid_redirect_uri', 'The redirect address '.mb_substr($redirectUri, 0, 200).' is not registered for this assistant, so the sign-in cannot return to it.');
        }
        $state = $query['state'] ?? null;

        return new AuthorizationRequest($client, $redirectUri, is_string($state) && strlen($state) <= 2000 ? $state : null);
    }

    /**
     * The rest of the request, once the client and redirect are known good.
     *
     * @param array<string, mixed> $query
     *
     * @return string the S256 code challenge
     *
     * @throws OAuthError to return to the client's redirect
     */
    public function challenge(array $query, string $baseUrl): string
    {
        if (($query['response_type'] ?? null) !== 'code') {
            throw new OAuthError('unsupported_response_type', 'Only response_type=code is supported.');
        }
        $challenge = $query['code_challenge'] ?? null;
        if (($query['code_challenge_method'] ?? null) !== 'S256' || !is_string($challenge) || preg_match('/^[A-Za-z0-9_-]{43}$/D', $challenge) !== 1) {
            throw new OAuthError('invalid_request', 'PKCE is required: send code_challenge with code_challenge_method=S256.');
        }
        self::assertResource($query['resource'] ?? null, $baseUrl);

        return $challenge;
    }

    /**
     * The token endpoint.
     *
     * @param array<string, mixed> $form the form-encoded body
     *
     * @return array<string, mixed>
     *
     * @throws OAuthError
     */
    public function token(array $form, ?string $basicClientId, string $baseUrl): array
    {
        $clientId = $form['client_id'] ?? $basicClientId;
        if (!is_string($clientId) || $clientId === '') {
            throw new OAuthError('invalid_request', 'client_id is required.');
        }
        self::assertResource($form['resource'] ?? null, $baseUrl);
        $required = static function (string $name) use ($form): string {
            $value = $form[$name] ?? null;
            if (!is_string($value) || $value === '') {
                throw new OAuthError('invalid_request', $name.' is required.');
            }

            return $value;
        };
        [$connection, $access, $refresh] = match ($form['grant_type'] ?? null) {
            'authorization_code' => $this->store->exchangeCode($required('code'), $clientId, $required('redirect_uri'), $required('code_verifier')),
            'refresh_token' => $this->store->refresh($required('refresh_token'), $clientId),
            default => throw new OAuthError('unsupported_grant_type', 'grant_type must be authorization_code or refresh_token.'),
        };

        return [
            'access_token' => $access,
            'token_type' => 'Bearer',
            'expires_in' => max(1, $connection->accessExpiresAt->getTimestamp() - time()),
            'refresh_token' => $refresh,
            'scope' => self::SCOPE,
        ];
    }

    /**
     * Token revocation. Answers success whether or not the token was current.
     *
     * @param array<string, mixed> $form
     *
     * @throws OAuthError invalid_request when no token is sent
     */
    public function revoke(array $form, ?string $basicClientId): void
    {
        $token = $form['token'] ?? null;
        $clientId = $form['client_id'] ?? $basicClientId;
        if (!is_string($token) || $token === '') {
            throw new OAuthError('invalid_request', 'token is required.');
        }
        if (is_string($clientId) && $clientId !== '') {
            $this->store->revokeToken($token, $clientId);
        }
    }

    /** @throws OAuthError invalid_target */
    private static function assertResource(mixed $resource, string $baseUrl): void
    {
        if ($resource === null) {
            return;
        }
        // Several resource parameters arrive as a list; each must be this server.
        foreach ((array) $resource as $value) {
            if (!is_string($value) || rtrim($value, '/') !== self::resource($baseUrl)) {
                throw new OAuthError('invalid_target', 'Tokens here are only for '.self::resource($baseUrl).'.');
            }
        }
    }
}
