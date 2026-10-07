<?php

declare(strict_types=1);

namespace App\Service\BigQuery;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Uses the service account attached to the Google Cloud host running
 * Aggregate (Compute Engine, Cloud Run, GKE, App Engine) through the local
 * metadata server. No key is stored.
 */
final class GoogleCloudCredentials implements GoogleCredentials
{
    public const METADATA = 'http://metadata.google.internal/computeMetadata/v1';

    private TokenCache $cache;
    private ?string $identity = null;
    private ?string $project = null;

    /** @param \Closure(): int|null $clock */
    public function __construct(
        private readonly HttpClientInterface $http,
        ?\Closure $clock = null,
    ) {
        $this->cache = new TokenCache($clock ?? static fn (): int => time());
    }

    public function accessToken(): string
    {
        return $this->cache->get(function (): array {
            $response = $this->metadata('/instance/service-accounts/default/token?scopes='.rawurlencode(self::BIGQUERY_SCOPE));

            return TokenCache::parse($response, 'The Google Cloud host did not provide an access token.');
        });
    }

    public function identity(): string
    {
        return $this->identity ??= $this->text('/instance/service-accounts/default/email');
    }

    public function projectId(): string
    {
        try {
            return $this->project ??= $this->text('/project/project-id');
        } catch (BigQueryException) {
            return '';
        }
    }

    private function text(string $path): string
    {
        $response = $this->metadata($path);
        try {
            $status = $response->getStatusCode();
            $value = trim($response->getContent(false));
        } catch (ExceptionInterface $e) {
            throw $this->unavailable($e);
        }
        if ($status !== 200 || $value === '' || strlen($value) > 254 || preg_match('/[\x00-\x20\x7F]/', $value) === 1) {
            throw new BigQueryException('The Google Cloud metadata server has no service account for this host. Attach a service account to the VM, Cloud Run service or GKE workload.');
        }

        return $value;
    }

    private function metadata(string $path): \Symfony\Contracts\HttpClient\ResponseInterface
    {
        try {
            $response = $this->http->request('GET', self::METADATA.$path, [
                'headers' => ['Metadata-Flavor' => 'Google'],
                'timeout' => 3,
                'max_duration' => 10,
                'max_redirects' => 0,
            ]);
            // Only the real metadata server answers with this header.
            if (($response->getHeaders(false)['metadata-flavor'][0] ?? null) !== 'Google') {
                throw new BigQueryException('No Google Cloud metadata server answered. “Automatic on Google Cloud” works only when Aggregate runs on Google Cloud; use a service account key elsewhere.');
            }
        } catch (ExceptionInterface $e) {
            throw $this->unavailable($e);
        }

        return $response;
    }

    private function unavailable(\Throwable $e): BigQueryException
    {
        return new BigQueryException('No Google Cloud metadata server answered. “Automatic on Google Cloud” works only when Aggregate runs on Google Cloud; use a service account key elsewhere.', previous: $e);
    }
}
