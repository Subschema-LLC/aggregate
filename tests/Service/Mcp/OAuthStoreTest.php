<?php

declare(strict_types=1);

namespace App\Tests\Service\Mcp;

use App\Service\Mcp\OAuth\OAuthClient;
use App\Service\Mcp\OAuth\OAuthError;
use App\Service\Mcp\OAuth\OAuthStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/**
 * Assistant sign-in state in config/secrets/mcp-oauth.yaml: hashes only,
 * single-use codes bound to their client, redirect and PKCE verifier,
 * refresh tokens replaced on every use, and revocation.
 */
final class OAuthStoreTest extends TestCase
{
    private const REDIRECT = 'https://assistant.example/callback';
    private const VERIFIER = 'a-verifier-that-is-long-enough-to-be-valid-0123456789';

    private string $projectDir;
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/aggregate-mcp-oauth-'.bin2hex(random_bytes(8));
        mkdir($this->projectDir.'/config', 0700, true);
        $this->clock = new MockClock('2026-10-19 02:00:00 UTC');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectDir);
    }

    public function testASignInIssuesTokensStoredOnlyAsHashes(): void
    {
        [$client, $code] = $this->approve(views: ['bi_anonymous_daily_v1']);
        self::assertMatchesRegularExpression('/^aggcode_[a-f0-9]{8}_[A-Za-z0-9_-]{43}$/D', $code);

        [$connection, $access, $refresh] = $this->store()->exchangeCode($code, $client->id, self::REDIRECT, self::VERIFIER);

        self::assertMatchesRegularExpression('/^aggmcp_'.$connection->id.'_[A-Za-z0-9_-]{43}$/D', $access);
        self::assertMatchesRegularExpression('/^aggmcpr_'.$connection->id.'_[A-Za-z0-9_-]{43}$/D', $refresh);
        self::assertSame('Example assistant', $connection->clientName);
        self::assertSame(['bi_anonymous_daily_v1'], $connection->views);
        self::assertSame('scott', $connection->approvedBy);
        self::assertSame('2026-10-19T03:00:00+00:00', $connection->accessExpiresAt->format(\DATE_ATOM), 'Access tokens live an hour');
        self::assertTrue($connection->mayRead('bi_dim_device_class_v1', false), 'Labels come with any scope');
        self::assertFalse($connection->mayRead('bi_anonymous_events_v1', false));

        $file = (string) file_get_contents($this->store()->path());
        foreach ([$code, $access, $refresh] as $secret) {
            self::assertStringNotContainsString(substr($secret, -43), $file, 'No secret is stored');
        }
        self::assertSame([], Yaml::parse($file)['codes'], 'The code is used up');
        self::assertSame(0600, fileperms($this->store()->path()) & 0777);

        self::assertSame($connection->id, $this->store()->authenticate($access)?->id);
        self::assertNull($this->store()->authenticate($refresh), 'A refresh token is not an access token');
        self::assertNull($this->store()->authenticate($access.'x'));
        $this->clock->modify('+61 minutes');
        self::assertNull($this->store()->authenticate($access), 'Expired access tokens stop working');
    }

    public function testACodeWorksOnceForItsClientRedirectAndVerifier(): void
    {
        foreach ([
            'another client' => ['aggc_0000000000000000', self::REDIRECT, self::VERIFIER, 'another client'],
            'another redirect' => [null, 'https://assistant.example/other', self::VERIFIER, 'redirect_uri'],
            'wrong verifier' => [null, self::REDIRECT, str_repeat('x', 43), 'code verifier'],
            'malformed verifier' => [null, self::REDIRECT, 'short', 'code verifier'],
        ] as $case => [$clientId, $redirect, $verifier, $message]) {
            [$client, $code] = $this->approve();
            try {
                $this->store()->exchangeCode($code, $clientId ?? $client->id, $redirect, $verifier);
                self::fail($case);
            } catch (OAuthError $error) {
                self::assertSame('invalid_grant', $error->error, $case);
                self::assertStringContainsString($message, $error->getMessage(), $case);
            }
            // A failed exchange still uses the code up.
            $this->expectInvalidGrant(fn () => $this->store()->exchangeCode($code, $client->id, self::REDIRECT, self::VERIFIER));
        }

        [$client, $code] = $this->approve();
        $this->store()->exchangeCode($code, $client->id, self::REDIRECT, self::VERIFIER);
        $this->expectInvalidGrant(fn () => $this->store()->exchangeCode($code, $client->id, self::REDIRECT, self::VERIFIER));

        [$client, $code] = $this->approve();
        $this->clock->modify('+61 seconds');
        $this->expectInvalidGrant(fn () => $this->store()->exchangeCode($code, $client->id, self::REDIRECT, self::VERIFIER));
    }

    public function testRefreshingReplacesBothTokensAndAReusedTokenRevokesTheConnection(): void
    {
        [$client, $code] = $this->approve();
        [$connection, $access, $refresh] = $this->store()->exchangeCode($code, $client->id, self::REDIRECT, self::VERIFIER);

        $this->clock->modify('+50 minutes');
        [$renewed, $access2, $refresh2] = $this->store()->refresh($refresh, $client->id);
        self::assertSame($connection->id, $renewed->id);
        self::assertNotSame($access, $access2);
        self::assertNull($this->store()->authenticate($access), 'The old access token stops working');
        self::assertSame($connection->id, $this->store()->authenticate($access2)?->id);
        $this->expectInvalidGrant(fn () => $this->store()->refresh($refresh2, 'aggc_0000000000000000'));

        // A retry within the grace period (a lost response) gets new tokens again.
        $this->clock->modify('+10 seconds');
        [, $access3, $refresh3] = $this->store()->refresh($refresh, $client->id);
        self::assertNull($this->store()->authenticate($access2));
        self::assertSame($connection->id, $this->store()->authenticate($access3)?->id);

        // After it, a replaced token coming back means it may have been copied.
        $this->clock->modify('+5 minutes');
        try {
            $this->store()->refresh($refresh, $client->id);
            self::fail('A reused refresh token is refused.');
        } catch (OAuthError $error) {
            self::assertStringContainsString('revoked', $error->getMessage());
        }
        self::assertSame([], $this->store()->connections());
        $this->expectInvalidGrant(fn () => $this->store()->refresh($refresh3, $client->id));
    }

    public function testConnectionsCanBeRevokedByAnAdministratorOrTheirOwnClient(): void
    {
        [$client, $code] = $this->approve();
        [$first] = $this->store()->exchangeCode($code, $client->id, self::REDIRECT, self::VERIFIER);
        [$client, $code] = $this->approve();
        [$second, $access, $refresh] = $this->store()->exchangeCode($code, $client->id, self::REDIRECT, self::VERIFIER);

        self::assertSame($first->id, $this->store()->revoke($first->id)->id);
        self::assertSame([$second->id], array_keys($this->store()->connections()));

        self::assertNull($this->store()->revokeToken($access, 'aggc_0000000000000000'), 'Only the client the token was issued to may revoke it');
        self::assertSame($second->id, $this->store()->revokeToken($refresh, $client->id)?->id);
        self::assertNull($this->store()->authenticate($access));

        $this->expectException(\InvalidArgumentException::class);
        $this->store()->revoke($first->id);
    }

    public function testConnectionsExpireAndUnusedClientsArePruned(): void
    {
        $store = $this->store();
        [$client, $code] = $this->approve(expiresAt: new \DateTimeImmutable('2026-10-20 02:00:00 UTC'));
        [, $access] = $store->exchangeCode($code, $client->id, self::REDIRECT, self::VERIFIER);
        $unused = $store->registerClient('Abandoned', [self::REDIRECT]);

        $this->clock->modify('+25 hours');
        self::assertNull($store->authenticate($access), 'An expired connection authenticates nothing');
        $store->registerClient('Next', [self::REDIRECT]);

        $file = Yaml::parse((string) file_get_contents($store->path()));
        self::assertSame([], $file['connections']);
        self::assertArrayNotHasKey($unused->id, $file['clients'], 'A day-old client nothing uses is dropped');
        self::assertArrayNotHasKey($client->id, $file['clients']);
        self::assertCount(1, $file['clients']);
    }

    public function testRegistrationChecksRedirectsAndNames(): void
    {
        $store = $this->store();
        $client = $store->registerClient('', ['http://localhost/callback', 'https://claude.ai/api/mcp/auth_callback']);
        self::assertSame('Unnamed assistant', $client->name);
        self::assertSame(OAuthClient::REGISTERED, $store->client($client->id)?->kind);
        self::assertTrue($client->allowsRedirect('http://localhost:3118/callback'), 'Loopback redirects match on any port');
        self::assertFalse($client->allowsRedirect('http://localhost:3118/other'));
        self::assertFalse($client->allowsRedirect('https://claude.ai/api/mcp/auth_callback?x=1'));

        foreach ([
            [['http://assistant.example/callback'], 'HTTPS'],
            [['https://assistant.example/callback#frag'], 'fragment'],
            [['https://user:pass@assistant.example/'], 'user name'],
            [[], '1 to'],
            [['javascript:alert(1)'], 'absolute URL'],
        ] as [$uris, $message]) {
            try {
                $store->registerClient('Bad', $uris);
                self::fail($message);
            } catch (OAuthError $error) {
                self::assertSame('invalid_redirect_uri', $error->error);
                self::assertStringContainsString($message, $error->getMessage());
            }
        }
        $this->expectException(OAuthError::class);
        $store->registerClient("Bad\x07name", [self::REDIRECT]);
    }

    public function testAnInvalidFileAuthenticatesNothingAndIsNotOverwritten(): void
    {
        [$client, $code] = $this->approve();
        [, $access] = $this->store()->exchangeCode($code, $client->id, self::REDIRECT, self::VERIFIER);
        file_put_contents($this->store()->path(), "connections:\n  zz: {}\n");

        try {
            $this->store()->authenticate($access);
            self::fail('An invalid file is an error, not an empty store.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('mcp-oauth.yaml is invalid', $error->getMessage());
            self::assertStringNotContainsString('sha256', $error->getMessage());
        }
        try {
            $this->store()->registerClient('Next', [self::REDIRECT]);
            self::fail('Nothing is saved over an invalid file.');
        } catch (\RuntimeException) {
        }
        self::assertSame("connections:\n  zz: {}\n", file_get_contents($this->store()->path()));
    }

    public function testTheChallengeIsTheS256OfTheVerifier(): void
    {
        // RFC 7636 appendix B.
        self::assertSame('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', OAuthStore::challenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'));
    }

    /**
     * @param list<string>|null $views
     *
     * @return array{0: OAuthClient, 1: string}
     */
    private function approve(?array $views = null, ?\DateTimeImmutable $expiresAt = null): array
    {
        $store = $this->store();
        $client = $store->registerClient('Example assistant', [self::REDIRECT]);

        return [$client, $store->issueCode($client, self::REDIRECT, OAuthStore::challenge(self::VERIFIER), $views, [], $expiresAt, 'scott')];
    }

    private function expectInvalidGrant(callable $call): void
    {
        try {
            $call();
            self::fail('Expected invalid_grant.');
        } catch (OAuthError $error) {
            self::assertSame('invalid_grant', $error->error);
        }
    }

    private function store(): OAuthStore
    {
        return new OAuthStore($this->projectDir, $this->clock);
    }
}
