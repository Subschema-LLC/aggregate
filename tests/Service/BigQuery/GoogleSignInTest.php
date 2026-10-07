<?php

declare(strict_types=1);

namespace App\Tests\Service\BigQuery;

use App\Service\BigQuery\BigQueryException;
use App\Service\BigQuery\GoogleSignIn;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GoogleSignInTest extends TestCase
{
    public function testStartAsksForOfflineBigQueryAccessWithPkce(): void
    {
        $start = (new GoogleSignIn(new MockHttpClient()))->start('123-abc.apps.googleusercontent.com', 'https://analytics.example.com/dashboard/bigquery/google/callback');
        self::assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $start['url']);
        parse_str((string) parse_url($start['url'], PHP_URL_QUERY), $query);

        self::assertSame('123-abc.apps.googleusercontent.com', $query['client_id']);
        self::assertSame('https://analytics.example.com/dashboard/bigquery/google/callback', $query['redirect_uri']);
        self::assertSame('code', $query['response_type']);
        self::assertSame('https://www.googleapis.com/auth/bigquery openid email', $query['scope']);
        self::assertSame('offline', $query['access_type']);
        self::assertSame('consent', $query['prompt']);
        self::assertSame($start['state'], $query['state']);
        self::assertSame('S256', $query['code_challenge_method']);
        self::assertSame(rtrim(strtr(base64_encode(hash('sha256', $start['verifier'], true)), '+/', '-_'), '='), $query['code_challenge']);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43,}$/', $start['state']);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43,128}$/', $start['verifier']);
        self::assertNotSame($start['state'], (new GoogleSignIn(new MockHttpClient()))->start('c.apps.googleusercontent.com', 'https://x.test/cb')['state']);
    }

    public function testExchangeKeepsOnlyTheRefreshTokenAndTheAccountEmail(): void
    {
        $requests = [];
        $idToken = 'header.'.rtrim(strtr(base64_encode(json_encode(['email' => 'admin@example.com', 'sub' => '1'])), '+/', '-_'), '=').'.signature';
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests, $idToken): MockResponse {
            parse_str($options['body'], $body);
            $requests[] = [$url, $body];

            return new MockResponse(json_encode([
                'access_token' => 'short-lived', 'expires_in' => 3599, 'refresh_token' => 'long-lived',
                'scope' => 'openid https://www.googleapis.com/auth/bigquery https://www.googleapis.com/auth/userinfo.email', 'id_token' => $idToken,
            ]), ['http_code' => 200]);
        });

        $result = (new GoogleSignIn($http))->exchange('auth-code', 'verifier', 'client.apps.googleusercontent.com', 'secret', 'https://analytics.example.com/cb');

        self::assertSame(['refresh_token' => 'long-lived', 'account' => 'admin@example.com'], $result);
        self::assertSame('https://oauth2.googleapis.com/token', $requests[0][0]);
        self::assertSame([
            'grant_type' => 'authorization_code', 'code' => 'auth-code', 'code_verifier' => 'verifier',
            'client_id' => 'client.apps.googleusercontent.com', 'client_secret' => 'secret', 'redirect_uri' => 'https://analytics.example.com/cb',
        ], $requests[0][1]);
    }

    public function testExchangeRejectsMissingRefreshTokenOrBigQueryScopeAndRevokesAPartialGrant(): void
    {
        $noRefresh = new GoogleSignIn(new MockHttpClient(new MockResponse(json_encode(['access_token' => 'a', 'scope' => 'https://www.googleapis.com/auth/bigquery']), ['http_code' => 200])));
        try {
            $noRefresh->exchange('c', 'v', 'id', 's', 'https://x.test/cb');
            self::fail('A sign-in without a refresh token was accepted.');
        } catch (BigQueryException $e) {
            self::assertStringContainsString('long-lived sign-in', $e->getMessage());
        }

        $calls = [];
        $partial = new GoogleSignIn(new MockHttpClient(static function (string $method, string $url) use (&$calls): MockResponse {
            $calls[] = $url;

            return str_ends_with($url, '/revoke')
                ? new MockResponse('', ['http_code' => 200])
                : new MockResponse(json_encode(['access_token' => 'a', 'refresh_token' => 'r', 'scope' => 'openid email']), ['http_code' => 200]);
        }));
        try {
            $partial->exchange('c', 'v', 'id', 's', 'https://x.test/cb');
            self::fail('A sign-in without BigQuery access was accepted.');
        } catch (BigQueryException $e) {
            self::assertStringContainsString('BigQuery access was not granted', $e->getMessage());
        }
        self::assertSame(['https://oauth2.googleapis.com/token', 'https://oauth2.googleapis.com/revoke'], $calls);
    }

    public function testGoogleErrorsExplainTheLikelyFix(): void
    {
        foreach (['redirect_uri_mismatch' => 'Add the redirect URI', 'invalid_client' => 'Check the OAuth client ID and secret'] as $error => $hint) {
            $signIn = new GoogleSignIn(new MockHttpClient(new MockResponse(json_encode(['error' => $error]), ['http_code' => 400])));
            try {
                $signIn->exchange('c', 'v', 'id', 's', 'https://x.test/cb');
                self::fail('A failed exchange was accepted.');
            } catch (BigQueryException $e) {
                self::assertStringContainsString($hint, $e->getMessage());
            }
        }
        self::assertTrue((new GoogleSignIn(new MockHttpClient(new MockResponse('', ['http_code' => 200]))))->revoke('token'));
        self::assertFalse((new GoogleSignIn(new MockHttpClient(new MockResponse('', ['http_code' => 400]))))->revoke('token'));
    }
}
