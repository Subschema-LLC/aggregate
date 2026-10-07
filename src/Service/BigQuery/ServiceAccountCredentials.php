<?php

declare(strict_types=1);

namespace App\Service\BigQuery;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Signs in as a service account with its JSON key (OAuth 2.0 JWT bearer grant). */
final class ServiceAccountCredentials implements GoogleCredentials
{
    private TokenCache $cache;

    /** @param \Closure(): int|null $clock */
    public function __construct(
        private readonly ServiceAccountKey $key,
        private readonly HttpClientInterface $http,
        private ?\Closure $clock = null,
    ) {
        $this->clock ??= static fn (): int => time();
        $this->cache = new TokenCache($this->clock);
    }

    public function accessToken(): string
    {
        return $this->cache->get(function (): array {
            $assertion = $this->key->assertion(self::BIGQUERY_SCOPE, ($this->clock)());

            return TokenCache::parse(fn () => $this->http->request('POST', $this->key->tokenUri, [
                'body' => [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $assertion,
                ],
                'timeout' => 30,
                'max_redirects' => 0,
            ]), 'The service account key was not accepted.');
        });
    }

    public function identity(): string
    {
        return $this->key->clientEmail;
    }

    public function projectId(): string
    {
        return $this->key->projectId;
    }
}
