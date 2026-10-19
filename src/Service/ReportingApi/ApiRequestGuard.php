<?php

declare(strict_types=1);

namespace App\Service\ReportingApi;

use App\Service\ViewSync\ViewCatalog;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Who may call the reporting API and what they may read: the bearer token
 * from the Authorization header, the per-token rate limit, and whether the
 * token may read a view. Failures are ApiProblem exceptions that never say
 * whether a rejected token was unknown, revoked or expired.
 */
class ApiRequestGuard
{
    /** Query parameters that look like a token; refused so callers move it to the header. */
    public const TOKEN_PARAMETERS = ['token', 'access_token', 'api_key', 'apikey', 'api_token', 'bearer'];

    public function __construct(
        private readonly ApiTokenStore $tokens,
        private readonly ReportingApiSettings $settings,
        #[Target('reporting_api')]
        private readonly RateLimiterFactoryInterface $limiter,
    ) {
    }

    /**
     * The token that authorized this request, counted against its rate limit.
     *
     * @throws ApiProblem 400 for a token in the address, 401, 429 or 503
     */
    public function authenticate(Request $request): ApiToken
    {
        foreach (array_keys($request->query->all()) as $name) {
            if (in_array(strtolower((string) $name), self::TOKEN_PARAMETERS, true)) {
                throw ApiProblem::badRequest('token_in_query', 'Send the API token in the Authorization header as "Bearer <token>", never in the address: addresses are written to server and proxy logs. Revoke a token that was sent this way.');
            }
        }
        $header = $request->headers->get('Authorization');
        if ($header === null || trim($header) === '') {
            throw new ApiProblem(401, 'missing_token', 'Send an API token in the Authorization header as "Bearer <token>".');
        }
        if (preg_match('/^Bearer[ \t]+(\S+)[ \t]*$/iD', $header, $matches) !== 1) {
            throw new ApiProblem(401, 'invalid_token', 'The Authorization header must be "Bearer <token>".');
        }
        try {
            $token = $this->tokens->authenticate($matches[1]);
        } catch (\RuntimeException $error) {
            throw new ApiProblem(503, 'configuration_invalid', 'The API tokens on this installation cannot be read. An administrator needs to correct them.', previous: $error);
        }
        if ($token === null) {
            throw new ApiProblem(401, 'invalid_token', 'The API token is not valid. It may be mistyped, revoked or expired.');
        }

        $limit = $this->limiter->create('token-'.$token->id)->consume();
        if (!$limit->isAccepted()) {
            $retry = max(1, $limit->getRetryAfter()->getTimestamp() - time());
            throw new ApiProblem(429, 'rate_limited', sprintf('This token made more than %d requests in the current window. Retry after %d seconds.', $limit->getLimit(), $retry), [
                'Retry-After' => (string) $retry,
                'X-RateLimit-Limit' => (string) $limit->getLimit(),
                'X-RateLimit-Remaining' => '0',
            ]);
        }

        return $token;
    }

    /**
     * @throws ApiProblem 404 for a view the API does not serve, 403 when the token may not read it
     */
    public function authorize(ApiToken $token, string $view): void
    {
        if (!ViewCatalog::isKnown($view)) {
            throw new ApiProblem(404, 'unknown_view', sprintf('%s is not a reporting view the API serves. GET /api/v1/views lists the views this token may read.', mb_substr($view, 0, 100)));
        }
        $privateAllowed = $this->settings->privateViewsAllowed();
        if (ViewCatalog::isPrivate($view) && !$privateAllowed) {
            throw new ApiProblem(403, 'private_views_disabled', sprintf('%s is a private view, and private views are turned off for the reporting API on this installation.', $view));
        }
        if (!$token->mayRead($view, $privateAllowed)) {
            throw new ApiProblem(403, 'view_not_permitted', sprintf('This token may not read %s.', $view));
        }
    }
}
