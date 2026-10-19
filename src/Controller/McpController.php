<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\FeatureFlags;
use App\Service\Mcp\McpHttpError;
use App\Service\Mcp\McpRequestGuard;
use App\Service\Mcp\McpServer;
use App\Service\Mcp\OAuth\OAuthError;
use App\Service\Mcp\OAuth\OAuthServer;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The MCP server for AI assistants (docs/MCP.md) and the sign-in endpoints
 * assistants use to connect without a pasted token. The mcp_server feature
 * flag decides whether any of it exists; while it is off every address here
 * is an ordinary 404.
 *
 * /mcp speaks MCP's Streamable HTTP transport without sessions or streams:
 * each POST carries one JSON-RPC message and its credential and gets one JSON
 * answer, so it works on ordinary PHP hosting. GET and DELETE, which open a
 * server stream and end a session, are refused with 405.
 *
 * Sign-in needs the dashboard, where an administrator approves each
 * assistant. With DASHBOARD_ENABLED=0 the sign-in metadata and endpoints
 * return 404 and assistants use API tokens instead.
 *
 * Credentials are bearer tokens in the Authorization header and never
 * cookies, and no CORS headers are sent, so a web page cannot use a
 * visitor's browser to call the server.
 */
final class McpController
{
    /** The largest request body /mcp accepts. */
    public const MAX_BODY_BYTES = 1048576;

    private const JSON = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

    public function __construct(
        private readonly FeatureFlags $features,
        private readonly McpRequestGuard $guard,
        private readonly McpServer $server,
        private readonly OAuthServer $oauth,
        private readonly UrlGeneratorInterface $urls,
        #[Target('mcp_sign_in')]
        private readonly RateLimiterFactoryInterface $signInLimiter,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/mcp', name: 'mcp', methods: ['GET', 'POST', 'DELETE'])]
    public function mcp(Request $request): Response
    {
        $this->denyUnlessEnabled();
        try {
            if (!$request->isMethod('POST')) {
                throw new McpHttpError(405, 'This server has no event stream or session: send each JSON-RPC message with POST.', ['Allow' => 'POST']);
            }
            $version = $request->headers->get('MCP-Protocol-Version');
            if ($version !== null && !in_array($version, McpServer::PROTOCOL_VERSIONS, true)) {
                throw new McpHttpError(400, sprintf('Unsupported MCP-Protocol-Version. This server supports %s.', implode(', ', McpServer::PROTOCOL_VERSIONS)));
            }
            $principal = $this->guard->authenticate($request, $this->signInAvailable() ? $this->baseUrl($request).OAuthServer::RESOURCE_METADATA_PATH : null);
            $length = $request->headers->get('Content-Length');
            if ($length !== null && (int) $length > self::MAX_BODY_BYTES) {
                throw new McpHttpError(413, 'The request body is larger than 1 MiB.');
            }
            $body = $request->getContent();
            if (strlen($body) > self::MAX_BODY_BYTES) {
                throw new McpHttpError(413, 'The request body is larger than 1 MiB.');
            }
            try {
                $message = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return $this->rpc(McpServer::error(null, McpServer::PARSE_ERROR, 'The request body is not valid JSON.'), 400);
            }
            $answer = $this->server->handle($message, $principal);
            if ($answer === null) {
                return $this->protect(new Response('', Response::HTTP_ACCEPTED));
            }

            return $this->rpc($answer, isset($answer['error']) && $answer['id'] === null ? 400 : 200);
        } catch (McpHttpError $error) {
            return $this->protect(new JsonResponse(['error' => $error->getMessage()], $error->status, $error->headers));
        } catch (\Throwable $error) {
            $this->logger->error('An MCP request failed.', ['exception_class' => $error::class]);

            return $this->rpc(McpServer::error(null, McpServer::INTERNAL_ERROR, 'The request could not be completed.'), 500);
        }
    }

    #[Route('/.well-known/oauth-protected-resource/mcp', name: 'mcp_resource_metadata', methods: ['GET'])]
    #[Route('/.well-known/oauth-protected-resource', name: 'mcp_resource_metadata_root', methods: ['GET'])]
    public function resourceMetadata(Request $request): Response
    {
        $this->denyUnlessSignIn();

        return $this->json($this->oauth->protectedResourceMetadata($this->baseUrl($request)));
    }

    #[Route('/.well-known/oauth-authorization-server', name: 'mcp_authorization_server_metadata', methods: ['GET'])]
    #[Route('/.well-known/openid-configuration', name: 'mcp_openid_configuration', methods: ['GET'])]
    public function authorizationServerMetadata(Request $request): Response
    {
        $this->denyUnlessSignIn();

        return $this->json($this->oauth->authorizationServerMetadata($this->baseUrl($request)));
    }

    #[Route('/oauth/register', name: 'mcp_oauth_register', methods: ['POST'])]
    public function register(Request $request): Response
    {
        $this->denyUnlessSignIn();

        return $this->oauthAnswer($request, function () use ($request): Response {
            try {
                $metadata = json_decode($request->getContent(), true, 16, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new OAuthError('invalid_client_metadata', 'Send the client metadata as a JSON object.');
            }
            if (!is_array($metadata) || array_is_list($metadata)) {
                throw new OAuthError('invalid_client_metadata', 'Send the client metadata as a JSON object.');
            }

            return $this->json($this->oauth->register($metadata), Response::HTTP_CREATED);
        });
    }

    #[Route('/oauth/token', name: 'mcp_oauth_token', methods: ['POST'])]
    public function token(Request $request): Response
    {
        $this->denyUnlessSignIn();

        return $this->oauthAnswer($request, fn (): Response => $this->json($this->oauth->token($request->request->all(), $this->basicClientId($request), $this->baseUrl($request))));
    }

    #[Route('/oauth/revoke', name: 'mcp_oauth_revoke', methods: ['POST'])]
    public function revoke(Request $request): Response
    {
        $this->denyUnlessSignIn();

        return $this->oauthAnswer($request, function () use ($request): Response {
            $this->oauth->revoke($request->request->all(), $this->basicClientId($request));

            return $this->protect(new Response('', Response::HTTP_OK));
        });
    }

    /** @param callable(): Response $handler */
    private function oauthAnswer(Request $request, callable $handler): Response
    {
        $limit = $this->signInLimiter->create('ip-'.($request->getClientIp() ?? 'unknown'))->consume();
        if (!$limit->isAccepted()) {
            $retry = max(1, $limit->getRetryAfter()->getTimestamp() - time());

            return $this->json(['error' => 'temporarily_unavailable', 'error_description' => sprintf('Too many sign-in requests. Retry after %d seconds.', $retry)], 429, ['Retry-After' => (string) $retry]);
        }
        try {
            return $handler();
        } catch (OAuthError $error) {
            if ($error->status >= 500) {
                $this->logger->error('An assistant sign-in request failed.', ['error' => $error->error, 'exception_class' => $error->getPrevious() !== null ? $error->getPrevious()::class : null]);
            }

            return $this->json($error->toArray(), $error->status);
        } catch (\RuntimeException $error) {
            $this->logger->error('An assistant sign-in request failed.', ['exception_class' => $error::class]);

            return $this->json(['error' => 'temporarily_unavailable', 'error_description' => 'Assistant sign-in is unavailable: its settings file cannot be read or written. An administrator needs to correct it.'], 503);
        }
    }

    /** The client_id of an HTTP Basic header, for clients that send it there; a secret is ignored, as none is issued. */
    private function basicClientId(Request $request): ?string
    {
        $header = (string) $request->headers->get('Authorization', '');
        if (preg_match('/^Basic[ \t]+([A-Za-z0-9+\/=]+)$/iD', $header, $matches) !== 1) {
            return null;
        }
        $decoded = base64_decode($matches[1], true);
        if (!is_string($decoded) || $decoded === '') {
            return null;
        }
        $id = rawurldecode(explode(':', $decoded, 2)[0]);

        return $id === '' ? null : $id;
    }

    private function denyUnlessEnabled(): void
    {
        if (!$this->features->isEnabled('mcp_server')) {
            throw new NotFoundHttpException();
        }
    }

    private function denyUnlessSignIn(): void
    {
        $this->denyUnlessEnabled();
        if (!$this->signInAvailable()) {
            throw new NotFoundHttpException();
        }
    }

    /** Whether the dashboard, where administrators approve assistants, is installed. */
    private function signInAvailable(): bool
    {
        try {
            $this->urls->generate('app_mcp_authorize');

            return true;
        } catch (RouteNotFoundException) {
            return false;
        }
    }

    private function baseUrl(Request $request): string
    {
        return $request->getSchemeAndHttpHost().$request->getBaseUrl();
    }

    /** @param array<string, mixed> $answer */
    private function rpc(array $answer, int $status): Response
    {
        $response = new JsonResponse(json_encode($answer, self::JSON), $status, [], true);

        return $this->protect($response);
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $headers
     */
    private function json(array $data, int $status = Response::HTTP_OK, array $headers = []): Response
    {
        return $this->protect(new JsonResponse(json_encode($data, self::JSON), $status, $headers, true));
    }

    private function protect(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
