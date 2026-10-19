<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AggregateConfigLoader;
use App\Service\FeatureFlags;
use App\Service\Mcp\OAuth\AuthorizationRequest;
use App\Service\Mcp\OAuth\OAuthClient;
use App\Service\Mcp\OAuth\OAuthError;
use App\Service\Mcp\OAuth\OAuthServer;
use App\Service\Mcp\OAuth\OAuthStore;
use App\Service\Mcp\OAuth\RedirectUri;
use App\Service\ReportingApi\ApiTokenStore;
use App\Service\ReportingApi\ReportingApiSettings;
use App\Service\ViewSync\ViewCatalog;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * The page an administrator sees when an AI assistant asks to connect
 * (docs/MCP.md#sign-in): which assistant, where the sign-in returns to, and
 * a choice of views and expiry, as for an API token. Approving sends the
 * assistant back with a one-time code; denying sends it back with
 * access_denied. Administrators only (DashboardPages::ADMIN): a connection
 * grants data access outside the dashboard's permissions.
 *
 * A request whose client or redirect cannot be verified is answered on this
 * page and never redirected, so the page cannot send anyone to an address
 * the assistant did not register.
 */
final class McpAuthorizeController extends AbstractController
{
    use DashboardAccess;

    public const CSRF_TOKEN_ID = 'mcp_authorize';
    /** Sign-in parameters the page carries from the request to the decision. */
    private const CARRIED = ['response_type', 'client_id', 'redirect_uri', 'code_challenge', 'code_challenge_method', 'state', 'resource', 'scope'];

    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly FeatureFlags $features,
        private readonly OAuthServer $oauth,
        private readonly OAuthStore $store,
        private readonly ApiTokenStore $tokens,
        private readonly ReportingApiSettings $settings,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(OAuthServer::AUTHORIZE_PATH, name: 'app_mcp_authorize', methods: ['GET'])]
    public function authorize(Request $request): Response
    {
        $this->denyUnlessPermitted();
        $this->denyUnlessEnabled();

        return $this->handle($request, $request->query->all(), null);
    }

    #[Route(OAuthServer::AUTHORIZE_PATH, name: 'app_mcp_authorize_decide', methods: ['POST'])]
    public function decide(Request $request): Response
    {
        $this->denyUnlessPermitted();
        $this->denyUnlessEnabled();
        $form = $request->request;
        $token = $form->get('_csrf_token');
        if (!is_string($token) || !$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $token)) {
            return $this->failure('The page expired or its security token is invalid. Start the connection again from the assistant.');
        }

        return $this->handle($request, $form->all(), $form->get('decision'));
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function handle(Request $request, array $parameters, mixed $decision): Response
    {
        $parameters = array_intersect_key($parameters, array_flip([...self::CARRIED, 'views', 'private_views', 'scope_choice', 'private_views_confirmed', 'expires', 'expires_date']));
        $base = $request->getSchemeAndHttpHost().$request->getBaseUrl();
        try {
            $sign = $this->oauth->client($parameters);
        } catch (OAuthError $error) {
            return $this->failure($error->getMessage(), $error->status >= 500 ? 503 : 400);
        }
        try {
            $challenge = $this->oauth->challenge($parameters, $base);
        } catch (OAuthError $error) {
            return $this->back($sign, ['error' => $error->error, 'error_description' => $error->getMessage()], $base);
        }
        if ($decision === null) {
            return $this->page($request, $sign, $parameters);
        }
        if ($decision === 'deny') {
            return $this->back($sign, ['error' => 'access_denied', 'error_description' => 'An administrator declined the connection.'], $base);
        }
        if ($decision !== 'approve') {
            return $this->page($request, $sign, $parameters, 'Choose whether to connect the assistant.');
        }
        try {
            $views = match ($parameters['scope_choice'] ?? null) {
                'all' => null,
                'selected' => self::stringList($parameters['views'] ?? []),
                default => throw new \InvalidArgumentException('Choose which approved views the assistant may read.'),
            };
            $privateViews = self::stringList($parameters['private_views'] ?? []);
            if ($privateViews !== [] && ($parameters['private_views_confirmed'] ?? null) !== '1') {
                throw new \InvalidArgumentException('Confirm that this assistant may read row-level or unsuppressed data before granting private views.');
            }
            if ($privateViews !== [] && !$this->settings->privateViewsAllowed()) {
                throw new \InvalidArgumentException('Private views are turned off ('.ReportingApiSettings::PRIVATE_VIEWS.').');
            }
            $expiry = (string) ($parameters['expires'] ?? OAuthStore::DEFAULT_EXPIRY);
            if ($expiry === 'date') {
                $expiry = trim((string) ($parameters['expires_date'] ?? ''));
                if ($expiry === '') {
                    throw new \InvalidArgumentException('Choose the date the connection expires.');
                }
            } elseif (!array_key_exists($expiry, ApiTokenStore::EXPIRY_CHOICES)) {
                throw new \InvalidArgumentException('Choose when the connection expires.');
            }
            $code = $this->store->issueCode(
                $sign->client,
                $sign->redirectUri,
                $challenge,
                $views,
                $privateViews,
                $this->tokens->expiryFrom($expiry),
                $this->getUser()?->getUserIdentifier() ?? 'unknown',
            );
        } catch (\InvalidArgumentException $error) {
            return $this->page($request, $sign, $parameters, $error->getMessage());
        } catch (\Throwable $error) {
            $this->logger->error('An assistant sign-in could not be approved.', ['exception_class' => $error::class]);

            return $this->page($request, $sign, $parameters, 'The connection could not be saved. Check that config/secrets is writable and config/secrets/mcp-oauth.yaml is valid.');
        }

        return $this->back($sign, ['code' => $code], $base);
    }

    /** @param array<string, mixed> $parameters */
    private function page(Request $request, AuthorizationRequest $sign, array $parameters, ?string $error = null): Response
    {
        $client = $sign->client;
        $redirectHost = RedirectUri::host($sign->redirectUri);
        $response = $this->renderDashboardPage('mcp/authorize.html.twig', [
            'client' => $client,
            'client_host' => RedirectUri::host($client->id) ?: null,
            'registered_here' => $client->kind === OAuthClient::REGISTERED,
            'redirect_host' => $redirectHost,
            'redirect_is_local' => in_array($redirectHost, ['localhost', '127.0.0.1', '[::1]'], true),
            'secure' => $request->isSecure() || in_array($request->getHost(), ['localhost', '127.0.0.1'], true),
            'carried' => array_filter(array_intersect_key($parameters, array_flip(self::CARRIED)), 'is_string'),
            'error' => $error,
            'approved_views' => ViewCatalog::APPROVED,
            'private_views' => ViewCatalog::PRIVATE,
            'private_views_allowed' => $this->settings->privateViewsAllowed(),
            'expiry_choices' => ApiTokenStore::EXPIRY_CHOICES,
            'default_expiry' => OAuthStore::DEFAULT_EXPIRY,
            'never_expires_warning' => ApiTokenStore::NEVER_EXPIRES_WARNING,
            'csrf_token_id' => self::CSRF_TOKEN_ID,
        ]);
        if ($error !== null) {
            $response->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        // The page must not be framed by another site, or a click could be stolen.
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Content-Security-Policy', "frame-ancestors 'none'");

        return $response;
    }

    private function failure(string $message, int $status = 400): Response
    {
        $response = $this->renderDashboardPage('mcp/authorize_error.html.twig', ['message' => $message]);
        $response->setStatusCode($status);
        $response->headers->set('X-Frame-Options', 'DENY');

        return $response;
    }

    /** @param array<string, string> $parameters */
    private function back(AuthorizationRequest $sign, array $parameters, string $issuer): Response
    {
        $response = new RedirectResponse($sign->redirect($parameters, $issuer));
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    private function denyUnlessEnabled(): void
    {
        if (!$this->features->isEnabled('mcp_server')) {
            throw $this->createNotFoundException('The MCP server is turned off.');
        }
    }

    /** @return list<string> */
    private static function stringList(mixed $values): array
    {
        if (!is_array($values) || !array_is_list($values)) {
            throw new \InvalidArgumentException('The form contained an unexpected view list. Nothing was saved.');
        }
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new \InvalidArgumentException('The form contained an unexpected view list. Nothing was saved.');
            }
        }

        return $values;
    }
}
