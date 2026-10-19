<?php

declare(strict_types=1);

namespace App\Service\Mcp;

use App\Service\Mcp\OAuth\OAuthStore;
use App\Service\ReportingApi\ApiRequestGuard;
use App\Service\ReportingApi\ApiTokenStore;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Who may call the MCP server: a bearer credential in the Authorization
 * header, which is either a reporting API token holding the MCP grant
 * (agg_…) or the access token of an approved assistant connection
 * (aggmcp_…), counted against that credential's rate limit. A rejection
 * never says whether a credential was unknown, revoked, expired or lacked the
 * grant. The challenge on a 401 points assistants at the sign-in metadata
 * when sign-in is available.
 */
class McpRequestGuard
{
    public function __construct(
        private readonly ApiTokenStore $tokens,
        private readonly OAuthStore $connections,
        #[Target('mcp')]
        private readonly RateLimiterFactoryInterface $limiter,
    ) {
    }

    /**
     * @param string|null $resourceMetadata the protected resource metadata URL, or null when sign-in is unavailable
     *
     * @throws McpHttpError 400 for a token in the address, 401, 429 or 503
     */
    public function authenticate(Request $request, ?string $resourceMetadata): McpPrincipal
    {
        foreach (array_keys($request->query->all()) as $name) {
            if (in_array(strtolower((string) $name), ApiRequestGuard::TOKEN_PARAMETERS, true)) {
                throw new McpHttpError(400, 'Send the credential in the Authorization header as "Bearer <token>", never in the address: addresses are written to server and proxy logs. Revoke a token that was sent this way.');
            }
        }
        $challenge = 'Bearer realm="mcp"'.($resourceMetadata === null ? '' : ', resource_metadata="'.$resourceMetadata.'"');
        $header = $request->headers->get('Authorization');
        if ($header === null || trim($header) === '') {
            throw new McpHttpError(401, 'Sign in, or send a credential in the Authorization header as "Bearer <token>".', ['WWW-Authenticate' => $challenge]);
        }
        $invalid = new McpHttpError(401, 'The credential is not valid. It may be mistyped, revoked, expired or not allowed for AI assistants.', ['WWW-Authenticate' => $challenge.', error="invalid_token"']);
        if (preg_match('/^Bearer[ \t]+(\S+)[ \t]*$/iD', $header, $matches) !== 1) {
            throw $invalid;
        }
        $presented = $matches[1];
        try {
            if (str_starts_with($presented, OAuthStore::ACCESS_PREFIX)) {
                $connection = $this->connections->authenticate($presented);
                $principal = $connection === null ? null : McpPrincipal::connection($connection);
            } else {
                $token = $this->tokens->authenticate($presented);
                $principal = $token === null || !$token->mcp ? null : McpPrincipal::token($token);
            }
        } catch (\RuntimeException $error) {
            throw new McpHttpError(503, 'The credentials on this installation cannot be read. An administrator needs to correct them.', previous: $error);
        }
        if ($principal === null) {
            throw $invalid;
        }

        $limit = $this->limiter->create($principal->limiterKey())->consume();
        if (!$limit->isAccepted()) {
            $retry = max(1, $limit->getRetryAfter()->getTimestamp() - time());
            throw new McpHttpError(429, sprintf('This credential made more than %d requests in the current window. Retry after %d seconds.', $limit->getLimit(), $retry), [
                'Retry-After' => (string) $retry,
                'X-RateLimit-Limit' => (string) $limit->getLimit(),
                'X-RateLimit-Remaining' => '0',
            ]);
        }

        return $principal;
    }
}
