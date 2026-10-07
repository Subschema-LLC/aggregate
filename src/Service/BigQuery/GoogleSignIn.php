<?php

declare(strict_types=1);

namespace App\Service\BigQuery;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * “Sign in with Google” for BigQuery: the OAuth 2.0 authorization code flow
 * with PKCE and offline access, using the installation's own OAuth client.
 * Only the refresh token and the account's email are kept.
 */
final class GoogleSignIn
{
    public const AUTHORIZATION_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    public const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';
    public const SCOPES = [GoogleCredentials::BIGQUERY_SCOPE, 'openid', 'email'];

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    /**
     * A new state and PKCE verifier to keep in the session, and the Google
     * address to send the administrator to.
     *
     * @return array{url: string, state: string, verifier: string}
     */
    public function start(string $clientId, string $redirectUri, string $loginHint = ''): array
    {
        $state = ServiceAccountKey::base64Url(random_bytes(32));
        $verifier = ServiceAccountKey::base64Url(random_bytes(48));
        $query = [
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            'access_type' => 'offline',
            // Always ask, so Google returns a refresh token on every sign-in.
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
            'code_challenge' => ServiceAccountKey::base64Url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
        ];
        if ($loginHint !== '') {
            $query['login_hint'] = $loginHint;
        }

        return ['url' => self::AUTHORIZATION_URL.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986), 'state' => $state, 'verifier' => $verifier];
    }

    /**
     * Exchanges the authorization code for a refresh token.
     *
     * @return array{refresh_token: string, account: string}
     *
     * @throws BigQueryException
     */
    public function exchange(string $code, string $verifier, string $clientId, #[\SensitiveParameter] string $clientSecret, string $redirectUri): array
    {
        try {
            $response = $this->http->request('POST', self::TOKEN_URL, [
                'body' => [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'code_verifier' => $verifier,
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'redirect_uri' => $redirectUri,
                ],
                'timeout' => 30,
                'max_redirects' => 0,
            ]);
            $status = $response->getStatusCode();
            $body = $response->toArray(false);
        } catch (ExceptionInterface $e) {
            throw new BigQueryException('Google could not be reached to finish signing in: '.TokenCache::transportMessage($e), previous: $e);
        }
        if ($status !== 200) {
            $error = is_string($body['error'] ?? null) ? $body['error'] : 'HTTP '.$status;
            $description = is_string($body['error_description'] ?? null) ? ' ('.$body['error_description'].')' : '';
            $hint = $error === 'invalid_client' ? ' Check the OAuth client ID and secret.' : ($error === 'redirect_uri_mismatch' ? ' Add the redirect URI shown on the BigQuery page to the OAuth client.' : '');

            throw new BigQueryException('Google did not finish the sign-in: '.TokenCache::clean($error.$description).$hint);
        }
        $refreshToken = $body['refresh_token'] ?? null;
        if (!is_string($refreshToken) || $refreshToken === '') {
            throw new BigQueryException('Google did not return a long-lived sign-in. Remove Aggregate’s access under your Google account’s third-party connections, then sign in again.');
        }
        $scopes = is_string($body['scope'] ?? null) ? explode(' ', $body['scope']) : [];
        if (!in_array(GoogleCredentials::BIGQUERY_SCOPE, $scopes, true)) {
            $this->revoke($refreshToken);

            throw new BigQueryException('BigQuery access was not granted. Sign in again and allow access to BigQuery.');
        }

        return ['refresh_token' => $refreshToken, 'account' => self::email($body['id_token'] ?? null)];
    }

    /** Asks Google to end the sign-in; a failure leaves nothing to clean up locally. */
    public function revoke(#[\SensitiveParameter] string $token): bool
    {
        try {
            return $this->http->request('POST', self::REVOKE_URL, [
                'body' => ['token' => $token],
                'timeout' => 15,
                'max_redirects' => 0,
            ])->getStatusCode() === 200;
        } catch (ExceptionInterface) {
            return false;
        }
    }

    /**
     * The account's email from the ID token Google returned directly to this
     * server over TLS; it is shown to administrators, not used to authorize.
     */
    private static function email(mixed $idToken): string
    {
        if (!is_string($idToken) || substr_count($idToken, '.') !== 2) {
            return '';
        }
        $payload = base64_decode(strtr(explode('.', $idToken)[1], '-_', '+/'), true);
        $claims = is_string($payload) ? json_decode($payload, true) : null;
        $email = is_array($claims) ? ($claims['email'] ?? null) : null;

        return is_string($email) && strlen($email) <= 254 && preg_match('/^[^@\s]+@[^@\s]+$/D', $email) === 1 ? $email : '';
    }
}
