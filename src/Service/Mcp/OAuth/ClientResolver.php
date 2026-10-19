<?php

declare(strict_types=1);

namespace App\Service\Mcp\OAuth;

use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Finds the assistant behind a client_id:
 *
 * - aggc_…, a client registered here (OAuthStore::registerClient);
 * - an HTTPS URL, a client ID metadata document its maker publishes there.
 *   It is fetched once per sign-in, from public addresses only, within a few
 *   seconds and 64 KiB, without following redirects, and must name that URL
 *   as its client_id and list the redirect URIs. Only public clients, which
 *   prove themselves with PKCE, are accepted.
 */
class ClientResolver
{
    public const MAX_DOCUMENT_BYTES = 65536;
    public const TIMEOUT = 5.0;

    private readonly HttpClientInterface $httpClient;

    public function __construct(
        private readonly OAuthStore $store,
        HttpClientInterface $httpClient,
        bool $publicAddressesOnly = true,
    ) {
        $this->httpClient = $publicAddressesOnly ? new NoPrivateNetworkHttpClient($httpClient) : $httpClient;
    }

    /** @throws OAuthError invalid_client */
    public function resolve(string $clientId): OAuthClient
    {
        if (str_starts_with($clientId, OAuthStore::CLIENT_PREFIX)) {
            try {
                $client = $this->store->client($clientId);
            } catch (\RuntimeException $error) {
                throw new OAuthError('temporarily_unavailable', 'Assistant sign-in is unavailable: its settings file cannot be read. An administrator needs to correct it.', 503, $error);
            }

            return $client ?? throw new OAuthError('invalid_client', 'This assistant is not registered here, or its registration has expired. Remove and add the connector again.', 401);
        }
        if (self::isMetadataDocumentUrl($clientId)) {
            return $this->fetch($clientId);
        }

        throw new OAuthError('invalid_client', 'client_id must be a client registered here or the HTTPS address of a client ID metadata document.', 401);
    }

    public static function isMetadataDocumentUrl(string $clientId): bool
    {
        if (strlen($clientId) > RedirectUri::MAX_LENGTH || preg_match('/[\x00-\x20\x7f#]/', $clientId) === 1) {
            return false;
        }
        $parts = parse_url($clientId);
        if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') === '') {
            return false;
        }
        $path = $parts['path'] ?? '';

        return !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['fragment']) && $path !== '' && $path !== '/';
    }

    private function fetch(string $url): OAuthClient
    {
        $unavailable = static fn (string $why, ?\Throwable $previous = null): OAuthError => new OAuthError('invalid_client', 'The assistant\'s client metadata document at '.$url.' '.$why, 401, $previous);
        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => ['Accept' => 'application/json'],
                'timeout' => self::TIMEOUT,
                'max_duration' => self::TIMEOUT,
                'max_redirects' => 0,
                'buffer' => false,
            ]);
            if ($response->getStatusCode() !== 200) {
                throw $unavailable('answered HTTP '.$response->getStatusCode().'.');
            }
            $body = '';
            foreach ($this->httpClient->stream($response) as $chunk) {
                $body .= $chunk->getContent();
                if (strlen($body) > self::MAX_DOCUMENT_BYTES) {
                    $response->cancel();
                    throw $unavailable('is larger than 64 KiB.');
                }
            }
        } catch (OAuthError $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw $unavailable('could not be fetched.', $error);
        }
        try {
            $document = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw $unavailable('is not JSON.', $error);
        }
        if (!is_array($document) || ($document['client_id'] ?? null) !== $url) {
            throw $unavailable('does not name itself as the client_id.');
        }
        $method = $document['token_endpoint_auth_method'] ?? 'none';
        if ($method !== 'none') {
            throw $unavailable('describes a confidential client ('.(is_string($method) ? $method : 'unknown').'); only public clients using PKCE are supported.');
        }
        $uris = $document['redirect_uris'] ?? null;
        if (!is_array($uris) || $uris === [] || !array_is_list($uris) || count($uris) > OAuthStore::MAX_REDIRECT_URIS) {
            throw $unavailable('must list 1 to '.OAuthStore::MAX_REDIRECT_URIS.' redirect_uris.');
        }
        try {
            $uris = array_map(RedirectUri::assertValid(...), $uris);
        } catch (\InvalidArgumentException $error) {
            throw $unavailable('lists an invalid redirect URI: '.$error->getMessage(), $error);
        }
        $name = $document['client_name'] ?? null;
        $name = is_string($name) && trim($name) !== '' && mb_strlen($name) <= OAuthStore::MAX_CLIENT_NAME && preg_match('/\p{C}/u', $name) !== 1
            ? trim($name)
            : RedirectUri::host($url);

        return new OAuthClient($url, $name, $uris, OAuthClient::METADATA_DOCUMENT);
    }
}
