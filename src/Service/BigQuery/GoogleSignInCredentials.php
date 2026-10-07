<?php

declare(strict_types=1);

namespace App\Service\BigQuery;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Acts as the administrator who signed in with Google, using the stored refresh token. */
final class GoogleSignInCredentials implements GoogleCredentials
{
    private TokenCache $cache;

    /** @param \Closure(): int|null $clock */
    public function __construct(
        private readonly string $clientId,
        #[\SensitiveParameter] private readonly string $clientSecret,
        #[\SensitiveParameter] private readonly string $refreshToken,
        private readonly string $account,
        private readonly HttpClientInterface $http,
        ?\Closure $clock = null,
    ) {
        $this->cache = new TokenCache($clock ?? static fn (): int => time());
    }

    public function accessToken(): string
    {
        return $this->cache->get(function (): array {
            $response = fn () => $this->http->request('POST', GoogleSignIn::TOKEN_URL, [
                'body' => [
                    'grant_type' => 'refresh_token',
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'refresh_token' => $this->refreshToken,
                ],
                'timeout' => 30,
                'max_redirects' => 0,
            ]);
            try {
                return TokenCache::parse($response, 'Google did not renew the sign-in.');
            } catch (BigQueryException $e) {
                if (str_contains($e->getMessage(), 'invalid_grant')) {
                    throw new BigQueryException('The Google sign-in has expired or was revoked. Sign in with Google again. (Google ends sign-ins after 7 days while the OAuth app’s publishing status is “Testing”.)', 400, $e);
                }
                throw $e;
            }
        });
    }

    public function identity(): string
    {
        return $this->account !== '' ? $this->account : 'the Google account that signed in';
    }

    public function projectId(): string
    {
        return '';
    }

    public function __debugInfo(): array
    {
        return ['clientId' => $this->clientId, 'account' => $this->account];
    }
}
