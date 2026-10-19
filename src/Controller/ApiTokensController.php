<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AggregateConfigLoader;
use App\Service\FeatureFlags;
use App\Service\Mcp\McpConnection;
use App\Service\Mcp\OAuth\OAuthServer;
use App\Service\Mcp\OAuth\OAuthStore;
use App\Service\ReportingApi\ApiToken;
use App\Service\ReportingApi\ApiTokenStore;
use App\Service\ReportingApi\ReportingApiSettings;
use App\Service\ViewSync\ViewCatalog;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Reporting → API tokens: create and revoke reporting API tokens, list and
 * revoke AI assistant connections, and the settings both share.
 * Administrators only (DashboardPages::ADMIN): a token or a connection grants
 * data access outside the dashboard's permissions. The same ApiTokenStore,
 * OAuthStore and ReportingApiSettings serve the app:api:token:* and
 * app:mcp:connection:* commands and YAML.
 *
 * A new token is shown once, in the page the create form returns; it is
 * never put in the session or a flash message. While both the reporting_api
 * and mcp_server flags are off the page still lists and revokes tokens and
 * connections, so they can be revoked before either is turned back on, but
 * creating tokens and saving settings return 404.
 */
final class ApiTokensController extends AbstractController
{
    use DashboardAccess;

    public const CSRF_TOKEN_ID = 'api_tokens';

    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly ApiTokenStore $tokens,
        private readonly ReportingApiSettings $settings,
        private readonly FeatureFlags $features,
        private readonly OAuthStore $connections,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/dashboard/api-tokens', name: 'app_api_tokens', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyUnlessPermitted();

        return $this->page($request);
    }

    #[Route('/dashboard/api-tokens/create', name: 'app_api_tokens_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $this->denyUnlessPermitted();
        $this->denyUnlessApiEnabled();
        if (!$this->validCsrf($request)) {
            return $this->back();
        }
        $form = $request->request;
        try {
            $views = match ($form->get('scope')) {
                'all' => null,
                'selected' => $this->stringList($form->all('views')),
                default => throw new \InvalidArgumentException('Choose which approved views the token may read.'),
            };
            $privateViews = $this->stringList($form->all('private_views'));
            if ($privateViews !== [] && $form->get('private_views_confirmed') !== '1') {
                throw new \InvalidArgumentException('Confirm that this token may read row-level or unsuppressed data before granting private views.');
            }
            $expiry = (string) $form->get('expires', ApiTokenStore::DEFAULT_EXPIRY);
            if ($expiry === 'date') {
                $expiry = trim((string) $form->get('expires_date', ''));
                if ($expiry === '') {
                    throw new \InvalidArgumentException('Choose the date the token expires.');
                }
            } elseif (!array_key_exists($expiry, ApiTokenStore::EXPIRY_CHOICES)) {
                throw new \InvalidArgumentException('Choose when the token expires.');
            }
            [$token, $secret] = $this->tokens->create(
                (string) $form->get('name', ''),
                $views,
                $privateViews,
                $this->tokens->expiryFrom($expiry),
                $this->getUser()?->getUserIdentifier() ?? 'unknown',
                $form->get('mcp') === '1',
            );
        } catch (\InvalidArgumentException $error) {
            $this->addFlash('error', $error->getMessage());

            return $this->back();
        } catch (\Throwable $error) {
            $this->logFailure('create', $error);
            $this->addFlash('error', 'The token could not be created. Check that config/secrets is writable and config/secrets/api-tokens.yaml is valid.');

            return $this->back();
        }

        return $this->page($request, ['token' => $token, 'secret' => $secret]);
    }

    #[Route('/dashboard/api-tokens/{id}/revoke', name: 'app_api_tokens_revoke', requirements: ['id' => '[a-f0-9]{8}'], methods: ['POST'])]
    public function revoke(Request $request, string $id): Response
    {
        $this->denyUnlessPermitted();
        if (!$this->validCsrf($request)) {
            return $this->back();
        }
        try {
            $token = $this->tokens->revoke($id);
            $this->addFlash('success', sprintf('Revoked token %s ("%s"). Requests with it now fail.', $token->id, $token->name));
        } catch (\InvalidArgumentException $error) {
            $this->addFlash('error', $error->getMessage());
        } catch (\Throwable $error) {
            $this->logFailure('revoke', $error);
            $this->addFlash('error', 'The token could not be revoked. Check that config/secrets is writable and config/secrets/api-tokens.yaml is valid.');
        }

        return $this->back();
    }

    #[Route('/dashboard/api-tokens/connections/{id}/revoke', name: 'app_mcp_connection_revoke', requirements: ['id' => '[a-f0-9]{8}'], methods: ['POST'])]
    public function revokeConnection(Request $request, string $id): Response
    {
        $this->denyUnlessPermitted();
        if (!$this->validCsrf($request)) {
            return $this->back();
        }
        try {
            $connection = $this->connections->revoke($id);
            $this->addFlash('success', sprintf('Disconnected %s (%s). Its requests now fail.', $connection->clientName, $connection->id));
        } catch (\InvalidArgumentException $error) {
            $this->addFlash('error', $error->getMessage());
        } catch (\Throwable $error) {
            $this->logFailure('revoke_connection', $error);
            $this->addFlash('error', 'The connection could not be revoked. Check that config/secrets is writable and config/secrets/mcp-oauth.yaml is valid.');
        }

        return $this->back();
    }

    #[Route('/dashboard/api-tokens/settings', name: 'app_api_tokens_settings', methods: ['POST'])]
    public function saveSettings(Request $request): Response
    {
        $this->denyUnlessPermitted();
        $this->denyUnlessApiEnabled();
        if (!$this->validCsrf($request)) {
            return $this->back();
        }
        $form = $request->request;
        try {
            $candidate = [];
            if ($form->has('private_views')) {
                $candidate[ReportingApiSettings::PRIVATE_VIEWS] = match ($form->get('private_views')) {
                    '1' => true,
                    '0' => false,
                    default => throw new \InvalidArgumentException('Choose whether the API may serve private views.'),
                };
            }
            if ($form->has('max_page_size')) {
                $candidate[ReportingApiSettings::MAX_PAGE_SIZE] = trim((string) $form->get('max_page_size'));
            }
            $this->settings->save($candidate);
            $this->addFlash('success', 'Reporting API settings saved.');
        } catch (\InvalidArgumentException $error) {
            $this->addFlash('error', $error->getMessage());
        } catch (\Throwable $error) {
            $this->logFailure('settings', $error);
            $this->addFlash('error', 'The settings could not be saved. Check the active YAML configuration and file permissions.');
        }

        return $this->back();
    }

    /** @param array{token: ApiToken, secret: string}|null $created */
    private function page(Request $request, ?array $created = null): Response
    {
        $tokens = [];
        $tokensError = null;
        try {
            $tokens = $this->tokens->tokens();
        } catch (\RuntimeException $error) {
            $tokensError = $error->getMessage();
        }
        $settings = null;
        $settingsError = null;
        try {
            $settings = $this->settings->toArray();
        } catch (\RuntimeException|\InvalidArgumentException $error) {
            $settingsError = $error instanceof \InvalidArgumentException ? $error->getMessage() : 'The application configuration could not be read.';
        }
        $connections = [];
        $connectionsError = null;
        try {
            $connections = $this->connections->connections();
        } catch (\RuntimeException $error) {
            $connectionsError = $error->getMessage();
        }
        $now = new \DateTimeImmutable();
        $base = $request->getSchemeAndHttpHost().$request->getBaseUrl();

        return $this->renderDashboardPage('api_tokens/index.html.twig', [
            'api_enabled' => $this->features->isEnabled('reporting_api'),
            'mcp_enabled' => $this->features->isEnabled('mcp_server'),
            'tokens_creatable' => $this->tokens->creatable(),
            'connections' => $connections,
            'connections_error' => $connectionsError,
            'connection_hosts' => array_map(static fn (McpConnection $connection): string => $connection->clientHost(), $connections),
            'mcp_url' => OAuthServer::resource($base),
            'tokens' => $tokens,
            'tokens_error' => $tokensError,
            'expired' => array_map(static fn (ApiToken $token): bool => $token->isExpired($now), $tokens),
            'settings' => $settings,
            'settings_error' => $settingsError,
            'overrides' => $this->settings->environmentOverrides(),
            'private_views_allowed' => $this->settings->privateViewsAllowed(),
            'approved_views' => ViewCatalog::APPROVED,
            'private_views' => ViewCatalog::PRIVATE,
            'expiry_choices' => ApiTokenStore::EXPIRY_CHOICES,
            'default_expiry' => ApiTokenStore::DEFAULT_EXPIRY,
            'never_expires_warning' => ApiTokenStore::NEVER_EXPIRES_WARNING,
            'page_size_range' => ReportingApiSettings::PAGE_SIZE_RANGE,
            'api_base_url' => $request->getSchemeAndHttpHost().$request->getBaseUrl().'/api/v1',
            'created' => $created,
            'csrf_token_id' => self::CSRF_TOKEN_ID,
        ]);
    }

    /** Tokens and the shared settings serve the reporting API and the MCP server. */
    private function denyUnlessApiEnabled(): void
    {
        if (!$this->tokens->creatable()) {
            throw $this->createNotFoundException('The reporting API and the MCP server are turned off.');
        }
    }

    private function validCsrf(Request $request): bool
    {
        $token = $request->request->get('_csrf_token');
        if (!is_string($token) || !$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $token)) {
            $this->addFlash('error', 'Invalid security token. Please try again.');

            return false;
        }

        return true;
    }

    /**
     * @param array<mixed> $values
     *
     * @return list<string>
     */
    private function stringList(array $values): array
    {
        if (!array_is_list($values)) {
            throw new \InvalidArgumentException('The form contained an unexpected view list. Nothing was saved.');
        }
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new \InvalidArgumentException('The form contained an unexpected view list. Nothing was saved.');
            }
        }

        return $values;
    }

    private function back(): Response
    {
        $response = $this->redirectToRoute('app_api_tokens');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private function logFailure(string $operation, \Throwable $error): void
    {
        $this->logger->error('Reporting API token operation failed.', ['operation' => $operation, 'exception_class' => $error::class]);
    }
}
