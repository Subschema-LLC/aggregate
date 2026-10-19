<?php

declare(strict_types=1);

namespace App\Tests\Service\Mcp;

use App\Service\Mcp\OAuth\ClientResolver;
use App\Service\Mcp\OAuth\OAuthClient;
use App\Service\Mcp\OAuth\OAuthError;
use App\Service\Mcp\OAuth\OAuthStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Who an assistant is: a client registered here, or a client ID metadata
 * document published at the HTTPS URL that is its client ID.
 */
final class ClientResolverTest extends TestCase
{
    private const DOCUMENT = 'https://assistant.example/oauth/client.json';

    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/aggregate-mcp-clients-'.bin2hex(random_bytes(8));
        mkdir($this->projectDir.'/config', 0700, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectDir);
    }

    public function testARegisteredClientIsFoundAndAnUnknownOneIsRefused(): void
    {
        $store = new OAuthStore($this->projectDir);
        $client = $store->registerClient('Claude', ['https://claude.ai/api/mcp/auth_callback']);
        $resolver = new ClientResolver($store, new MockHttpClient(), false);

        self::assertSame('Claude', $resolver->resolve($client->id)->name);
        $this->assertRefused($resolver, 'aggc_0000000000000000', 'not registered');
        $this->assertRefused($resolver, 'some-client', 'client_id must be');
        $this->assertRefused($resolver, 'http://assistant.example/client.json', 'client_id must be');
        $this->assertRefused($resolver, 'https://assistant.example/', 'client_id must be');
    }

    public function testAMetadataDocumentDescribesAPublicClient(): void
    {
        $requests = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [$method, $url];

            return new MockResponse(json_encode([
                'client_id' => self::DOCUMENT,
                'client_name' => 'Example Code',
                'redirect_uris' => ['http://localhost/callback', 'http://127.0.0.1/callback'],
                'token_endpoint_auth_method' => 'none',
            ]));
        });
        $client = (new ClientResolver(new OAuthStore($this->projectDir), $http, false))->resolve(self::DOCUMENT);

        self::assertSame([['GET', self::DOCUMENT]], $requests);
        self::assertSame(OAuthClient::METADATA_DOCUMENT, $client->kind);
        self::assertSame('Example Code', $client->name);
        self::assertTrue($client->allowsRedirect('http://127.0.0.1:53111/callback'));
        self::assertFalse($client->allowsRedirect('http://evil.example/callback'));
        self::assertFalse(file_exists($this->projectDir.'/'.OAuthStore::FILE), 'A metadata document client is not stored');
    }

    public function testABadMetadataDocumentIsRefused(): void
    {
        foreach ([
            'another client_id' => [new MockResponse(json_encode(['client_id' => 'https://elsewhere.example/c.json', 'redirect_uris' => ['https://a.example/cb']])), 'does not name itself'],
            'confidential' => [new MockResponse(json_encode(['client_id' => self::DOCUMENT, 'redirect_uris' => ['https://a.example/cb'], 'token_endpoint_auth_method' => 'client_secret_basic'])), 'confidential'],
            'no redirects' => [new MockResponse(json_encode(['client_id' => self::DOCUMENT])), 'redirect_uris'],
            'plain HTTP redirect' => [new MockResponse(json_encode(['client_id' => self::DOCUMENT, 'redirect_uris' => ['http://a.example/cb']])), 'HTTPS'],
            'not JSON' => [new MockResponse('<html>'), 'not JSON'],
            'not found' => [new MockResponse('', ['http_code' => 404]), 'HTTP 404'],
            'redirected' => [new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location' => 'https://elsewhere.example/']]), 'HTTP 302'],
            'too large' => [new MockResponse(str_repeat(' ', ClientResolver::MAX_DOCUMENT_BYTES + 1)), '64 KiB'],
        ] as $case => [$response, $message]) {
            $resolver = new ClientResolver(new OAuthStore($this->projectDir), new MockHttpClient($response), false);
            try {
                $resolver->resolve(self::DOCUMENT);
                self::fail($case);
            } catch (OAuthError $error) {
                self::assertSame('invalid_client', $error->error, $case);
                self::assertStringContainsString($message, $error->getMessage(), $case);
            }
        }
    }

    public function testPrivateAddressesAreNeverFetched(): void
    {
        $resolver = new ClientResolver(new OAuthStore($this->projectDir), new MockHttpClient(new MockResponse('{}')));
        $this->assertRefused($resolver, 'https://127.0.0.1/client.json', 'could not be fetched');
    }

    private function assertRefused(ClientResolver $resolver, string $clientId, string $message): void
    {
        try {
            $resolver->resolve($clientId);
            self::fail($clientId);
        } catch (OAuthError $error) {
            self::assertSame('invalid_client', $error->error, $clientId);
            self::assertStringContainsString($message, $error->getMessage(), $clientId);
        }
    }
}
