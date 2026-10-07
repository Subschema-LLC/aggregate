<?php

declare(strict_types=1);

namespace App\Tests\Service\BigQuery;

use App\Service\BigQuery\BigQueryException;
use App\Service\BigQuery\GoogleCloudCredentials;
use App\Service\BigQuery\GoogleCredentials;
use App\Service\BigQuery\GoogleSignInCredentials;
use App\Service\BigQuery\ServiceAccountCredentials;
use App\Service\BigQuery\ServiceAccountKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GoogleCredentialsTest extends TestCase
{
    private static ?array $keyPair = null;

    /** @return array{private: string, public: string} */
    public static function keyPair(): array
    {
        if (self::$keyPair === null) {
            $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($resource, $private);
            self::$keyPair = ['private' => $private, 'public' => openssl_pkey_get_details($resource)['key']];
        }

        return self::$keyPair;
    }

    /** @param array<string, mixed> $overrides */
    public static function keyJson(array $overrides = []): string
    {
        return json_encode([
            'type' => 'service_account',
            'project_id' => 'my-analytics-123',
            'private_key_id' => 'abc123',
            'private_key' => self::keyPair()['private'],
            'client_email' => 'aggregate-sync@my-analytics-123.iam.gserviceaccount.com',
            'client_id' => '1234567890',
            'token_uri' => 'https://oauth2.googleapis.com/token',
            ...$overrides,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public function testServiceAccountKeySignsAVerifiableAssertionForTheBigQueryScope(): void
    {
        $key = ServiceAccountKey::fromJson(self::keyJson());
        self::assertSame('aggregate-sync@my-analytics-123.iam.gserviceaccount.com', $key->clientEmail);
        self::assertSame('my-analytics-123', $key->projectId);
        self::assertStringNotContainsString('PRIVATE KEY', print_r($key, true));

        $jwt = $key->assertion(GoogleCredentials::BIGQUERY_SCOPE, 1_800_000_000);
        [$header, $claims, $signature] = explode('.', $jwt);
        $decode = static fn (string $part): array => json_decode(base64_decode(strtr($part, '-_', '+/')), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'abc123'], $decode($header));
        self::assertSame([
            'iss' => 'aggregate-sync@my-analytics-123.iam.gserviceaccount.com',
            'scope' => 'https://www.googleapis.com/auth/bigquery',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => 1_800_000_000,
            'exp' => 1_800_003_600,
        ], $decode($claims));
        self::assertSame(1, openssl_verify($header.'.'.$claims, base64_decode(strtr($signature, '-_', '+/')), self::keyPair()['public'], OPENSSL_ALGO_SHA256));
    }

    #[DataProvider('invalidKeys')]
    public function testInvalidKeysAreRejectedWithoutEchoingThem(string $json, string $message): void
    {
        try {
            ServiceAccountKey::fromJson($json);
            self::fail('An invalid key was accepted.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString($message, $e->getMessage());
            self::assertStringNotContainsString('BEGIN', $e->getMessage());
        }
    }

    public static function invalidKeys(): iterable
    {
        yield 'not JSON' => ['{nope', 'not valid JSON'];
        yield 'list' => ['[1]', 'one JSON object'];
        yield 'workload identity' => [self::keyJson(['type' => 'external_account']), 'external_account credential file'];
        yield 'user credentials' => [self::keyJson(['type' => 'authorized_user']), 'authorized_user credential file'];
        yield 'other type' => [self::keyJson(['type' => 'api_key']), 'not a Google service account key'];
        yield 'no email' => [self::keyJson(['client_email' => 'not-an-email']), 'client_email'];
        yield 'no private key' => [self::keyJson(['private_key' => 'secret']), 'no private_key'];
        yield 'unreadable key' => [self::keyJson(['private_key' => "-----BEGIN PRIVATE KEY-----\nAAAA\n-----END PRIVATE KEY-----\n"]), 'not a readable RSA key'];
        yield 'foreign token endpoint' => [self::keyJson(['token_uri' => 'https://attacker.example/token']), 'not a Google token endpoint'];
        yield 'too large' => [str_repeat(' ', ServiceAccountKey::MAX_BYTES + 1), 'at most 64 KB'];
    }

    public function testServiceAccountTokenIsRequestedOnceAndRenewedBeforeExpiry(): void
    {
        $now = 1_800_000_000;
        $requests = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            parse_str($options['body'], $body);
            $requests[] = [$method, $url, $body];

            return new MockResponse(json_encode(['access_token' => 'token-'.count($requests), 'expires_in' => 3599, 'token_type' => 'Bearer']), ['http_code' => 200]);
        });
        $credentials = new ServiceAccountCredentials(ServiceAccountKey::fromJson(self::keyJson()), $http, static function () use (&$now): int {
            return $now;
        });

        self::assertSame('token-1', $credentials->accessToken());
        self::assertSame('token-1', $credentials->accessToken());
        $now += 3500;
        self::assertSame('token-2', $credentials->accessToken());
        self::assertCount(2, $requests);
        self::assertSame(['POST', 'https://oauth2.googleapis.com/token'], array_slice($requests[0], 0, 2));
        self::assertSame('urn:ietf:params:oauth:grant-type:jwt-bearer', $requests[0][2]['grant_type']);
        self::assertSame(3, substr_count($requests[0][2]['assertion'], '.') + 1);
        self::assertSame('aggregate-sync@my-analytics-123.iam.gserviceaccount.com', $credentials->identity());
        self::assertSame('my-analytics-123', $credentials->projectId());
    }

    public function testRejectedKeysAndUnreachableGoogleGiveSafeMessages(): void
    {
        $rejected = new ServiceAccountCredentials(ServiceAccountKey::fromJson(self::keyJson()), new MockHttpClient(new MockResponse(
            json_encode(['error' => 'invalid_grant', 'error_description' => "Invalid JWT Signature.\nmore"]),
            ['http_code' => 400],
        )));
        try {
            $rejected->accessToken();
            self::fail('A rejected key produced a token.');
        } catch (BigQueryException $e) {
            self::assertSame('The service account key was not accepted. Google answered: invalid_grant (Invalid JWT Signature. more)', $e->getMessage());
        }

        $unreachable = new ServiceAccountCredentials(ServiceAccountKey::fromJson(self::keyJson()), new MockHttpClient(static function (): never {
            throw new TransportException('Could not resolve host for "https://oauth2.googleapis.com/token?secret=1"');
        }));
        try {
            $unreachable->accessToken();
            self::fail('An unreachable token endpoint produced a token.');
        } catch (BigQueryException $e) {
            self::assertStringContainsString('Google could not be reached', $e->getMessage());
            self::assertStringNotContainsString('secret=1', $e->getMessage());
        }
    }

    public function testGoogleCloudCredentialsUseTheMetadataServerOnly(): void
    {
        $requests = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [$url, $options['headers']];
            $body = match (true) {
                str_contains($url, '/token') => json_encode(['access_token' => 'metadata-token', 'expires_in' => 3000]),
                str_contains($url, '/email') => 'vm-account@my-analytics-123.iam.gserviceaccount.com',
                default => 'my-analytics-123',
            };

            return new MockResponse($body, ['http_code' => 200, 'response_headers' => ['Metadata-Flavor' => 'Google']]);
        });
        $credentials = new GoogleCloudCredentials($http);

        self::assertSame('metadata-token', $credentials->accessToken());
        self::assertSame('vm-account@my-analytics-123.iam.gserviceaccount.com', $credentials->identity());
        self::assertSame('my-analytics-123', $credentials->projectId());
        self::assertSame('http://metadata.google.internal/computeMetadata/v1/instance/service-accounts/default/token?scopes=https%3A%2F%2Fwww.googleapis.com%2Fauth%2Fbigquery', $requests[0][0]);
        self::assertContains('Metadata-Flavor: Google', $requests[0][1]);
    }

    public function testAnythingButTheMetadataServerIsNotTrusted(): void
    {
        foreach ([
            new MockHttpClient(new MockResponse(json_encode(['access_token' => 'forged']), ['http_code' => 200])),
            new MockHttpClient(static function (): never {
                throw new TransportException('Could not resolve host: metadata.google.internal');
            }),
        ] as $http) {
            try {
                (new GoogleCloudCredentials($http))->accessToken();
                self::fail('A token was accepted from something other than the metadata server.');
            } catch (BigQueryException $e) {
                self::assertStringContainsString('works only when Aggregate runs on Google Cloud', $e->getMessage());
            }
        }
        self::assertSame('', (new GoogleCloudCredentials(new MockHttpClient(new MockResponse('', ['http_code' => 404, 'response_headers' => ['Metadata-Flavor' => 'Google']]))))->projectId());
    }

    public function testSignInCredentialsRefreshAndExplainAnEndedSignIn(): void
    {
        $requests = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            parse_str($options['body'], $body);
            $requests[] = $body;

            return new MockResponse(json_encode(['access_token' => 'user-token', 'expires_in' => 3599]), ['http_code' => 200]);
        });
        $credentials = new GoogleSignInCredentials('123-abc.apps.googleusercontent.com', 'client-secret', 'refresh-token', 'admin@example.com', $http);
        self::assertSame('user-token', $credentials->accessToken());
        self::assertSame(['grant_type' => 'refresh_token', 'client_id' => '123-abc.apps.googleusercontent.com', 'client_secret' => 'client-secret', 'refresh_token' => 'refresh-token'], $requests[0]);
        self::assertSame('admin@example.com', $credentials->identity());
        self::assertStringNotContainsString('refresh-token', print_r($credentials, true));

        $ended = new GoogleSignInCredentials('123-abc.apps.googleusercontent.com', 'client-secret', 'refresh-token', '', new MockHttpClient(new MockResponse(json_encode(['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.']), ['http_code' => 400])));
        try {
            $ended->accessToken();
            self::fail('An ended sign-in produced a token.');
        } catch (BigQueryException $e) {
            self::assertStringContainsString('Sign in with Google again', $e->getMessage());
            self::assertStringContainsString('7 days', $e->getMessage());
        }
    }
}
