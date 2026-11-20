<?php

declare(strict_types=1);

namespace App\Integrations\TrafficRanges;

use App\Entities\TrafficClass\RangeDownloads;
use App\Entities\TrafficClass\RangeSource;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Downloads published range lists over HTTPS. The request carries no
 * information about the installation beyond what any HTTP client sends.
 */
final class HttpRangeDownloads implements RangeDownloads
{
    public const MAX_BYTES = 16 * 1024 * 1024;
    public const TIMEOUT_SECONDS = 20;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly PublishedSources $sources = new PublishedSources(),
    ) {
    }

    public function sources(): array
    {
        return $this->sources->all();
    }

    public function download(RangeSource $source): array
    {
        ['url' => $url, 'format' => $format] = $this->sources->location($source);
        if (!str_starts_with($url, 'https://')) {
            throw new \RuntimeException('Range lists are downloaded over HTTPS only.');
        }
        try {
            $response = $this->http->request('GET', $url, [
                'timeout' => self::TIMEOUT_SECONDS,
                'max_redirects' => 3,
                'headers' => ['Accept' => 'application/json'],
            ]);
            $status = $response->getStatusCode();
            if ($status !== 200) {
                throw new \RuntimeException(sprintf('The list answered HTTP %d.', $status));
            }
            $body = $response->getContent();
        } catch (ExceptionInterface $e) {
            throw new \RuntimeException('The list could not be downloaded: '.$e->getMessage());
        }
        if (strlen($body) > self::MAX_BYTES) {
            throw new \RuntimeException('The list is larger than expected.');
        }

        return RangeFormats::parse($format, $body);
    }
}
