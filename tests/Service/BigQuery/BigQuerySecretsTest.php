<?php

declare(strict_types=1);

namespace App\Tests\Service\BigQuery;

use App\Service\AggregateConfigLoader;
use App\Service\BigQuery\BigQueryCredentialsFactory;
use App\Service\BigQuery\BigQueryException;
use App\Service\BigQuery\BigQuerySecrets;
use App\Service\BigQuery\BigQuerySettings;
use App\Service\BigQuery\GoogleCloudCredentials;
use App\Service\BigQuery\GoogleSignInCredentials;
use App\Service\BigQuery\ServiceAccountCredentials;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Yaml\Yaml;

final class BigQuerySecretsTest extends TestCase
{
    private string $projectDir;
    private array $environment;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        foreach ([...array_keys(BigQuerySettings::defaults()), BigQuerySecrets::CLIENT_SECRET_ENV] as $key) {
            unset($_ENV[strtoupper($key)], $_SERVER[strtoupper($key)]);
        }
        $this->projectDir = sys_get_temp_dir().'/aggregate-bigquery-secrets-'.bin2hex(random_bytes(6));
        mkdir($this->projectDir.'/config', 0700, true);
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump(['bigquery_oauth_client_id' => '123-abc.apps.googleusercontent.com']));
    }

    protected function tearDown(): void
    {
        [$_ENV, $_SERVER] = $this->environment;
        foreach ([...(glob($this->projectDir.'/config/secrets/{,.}*', GLOB_BRACE) ?: []), ...(glob($this->projectDir.'/config/*') ?: [])] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        @rmdir($this->projectDir.'/config/secrets');
        rmdir($this->projectDir.'/config');
        rmdir($this->projectDir);
    }

    public function testKeysAreValidatedAndStoredReadableOnlyByTheApplication(): void
    {
        $secrets = $this->secrets();
        self::assertNull($secrets->serviceAccountKey());

        try {
            $secrets->storeServiceAccountKey('{"type":"authorized_user"}');
            self::fail('A user credential was stored as a key.');
        } catch (\InvalidArgumentException) {
            self::assertFileDoesNotExist($this->projectDir.'/config/secrets/bigquery-service-account.json');
        }

        $key = $secrets->storeServiceAccountKey("\n".GoogleCredentialsTest::keyJson()."\n");
        $path = $this->projectDir.'/config/secrets/bigquery-service-account.json';
        self::assertSame(0600, fileperms($path) & 0777);
        self::assertSame(0700, fileperms(dirname($path)) & 0777);
        self::assertSame($key->clientEmail, $secrets->serviceAccountKey()?->clientEmail);
        self::assertSame([], glob(dirname($path).'/.bigquery-*') ?: []);

        $secrets->removeServiceAccountKey();
        self::assertFileDoesNotExist($path);
    }

    public function testAKeyManagedElsewhereIsReadButNeverWritten(): void
    {
        file_put_contents($this->projectDir.'/config/managed.json', GoogleCredentialsTest::keyJson());
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump(['bigquery_credentials_file' => 'config/managed.json']));
        $secrets = $this->secrets();

        self::assertNotNull($secrets->serviceAccountKey());
        $this->expectException(\InvalidArgumentException::class);
        $secrets->storeServiceAccountKey(GoogleCredentialsTest::keyJson());
    }

    public function testGoogleSignInIsStoredSeparatelyAndANewClientDropsTheOldToken(): void
    {
        $secrets = $this->secrets();
        $secrets->storeClientSecret(' client-secret ');
        $secrets->storeSignIn('refresh-1', '123-abc.apps.googleusercontent.com', 'admin@example.com', new \DateTimeImmutable('2026-10-07 12:00:00', new \DateTimeZone('UTC')));

        self::assertSame('client-secret', $secrets->clientSecret());
        self::assertSame('refresh-1', $secrets->refreshToken());
        self::assertSame(['account' => 'admin@example.com', 'connected_at' => '2026-10-07T12:00:00+00:00'], $secrets->signInConnection());
        self::assertSame(0600, fileperms($this->projectDir.'/'.BigQuerySecrets::SIGN_IN_FILE) & 0777);

        $secrets->storeClientSecret('client-secret');
        self::assertSame('refresh-1', $secrets->refreshToken());
        $secrets->storeClientSecret('another-secret');
        self::assertNull($secrets->refreshToken());

        $_ENV[BigQuerySecrets::CLIENT_SECRET_ENV] = 'from-environment';
        self::assertSame('from-environment', $secrets->clientSecret());
        self::assertTrue($secrets->clientSecretFromEnvironment());

        $secrets->storeSignIn('refresh-2', 'x.apps.googleusercontent.com', '', new \DateTimeImmutable());
        $secrets->clearSignIn();
        self::assertNull($secrets->signInConnection());

        $this->expectException(\InvalidArgumentException::class);
        $secrets->storeClientSecret("bad secret\n");
    }

    public function testTheFactoryExplainsWhatIsMissingForEachSignInMethod(): void
    {
        $factory = new BigQueryCredentialsFactory($this->secrets(), new MockHttpClient());
        $settings = BigQuerySettings::validate(['bigquery_oauth_client_id' => '123-abc.apps.googleusercontent.com']);

        foreach ([
            [$settings, 'No service account key is installed'],
            [[...$settings, 'bigquery_auth' => 'google_sign_in'], 'needs an OAuth client ID and secret'],
        ] as [$candidate, $message]) {
            try {
                $factory->create($candidate);
                self::fail('Missing credentials were accepted.');
            } catch (BigQueryException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
        self::assertInstanceOf(GoogleCloudCredentials::class, $factory->create([...$settings, 'bigquery_auth' => 'google_cloud']));

        $secrets = $this->secrets();
        $secrets->storeClientSecret('client-secret');
        $signIn = [...$settings, 'bigquery_auth' => 'google_sign_in'];
        try {
            $factory->create($signIn);
            self::fail('A missing sign-in was accepted.');
        } catch (BigQueryException $e) {
            self::assertStringContainsString('No administrator has signed in', $e->getMessage());
        }
        $secrets->storeSignIn('refresh', 'other.apps.googleusercontent.com', '', new \DateTimeImmutable());
        try {
            $factory->create($signIn);
            self::fail('A sign-in for another client was accepted.');
        } catch (BigQueryException $e) {
            self::assertStringContainsString('different OAuth client', $e->getMessage());
        }
        $secrets->storeSignIn('refresh', '123-abc.apps.googleusercontent.com', 'admin@example.com', new \DateTimeImmutable());
        self::assertInstanceOf(GoogleSignInCredentials::class, $factory->create($signIn));

        $secrets->storeServiceAccountKey(GoogleCredentialsTest::keyJson());
        $credentials = $factory->create($settings);
        self::assertInstanceOf(ServiceAccountCredentials::class, $credentials);
        self::assertSame('my-analytics-123', BigQueryCredentialsFactory::project($settings, $credentials));
        self::assertSame('chosen-project', BigQueryCredentialsFactory::project([...$settings, 'bigquery_project_id' => 'chosen-project'], $credentials));
    }

    private function secrets(): BigQuerySecrets
    {
        return new BigQuerySecrets(new BigQuerySettings(new AggregateConfigLoader($this->projectDir, 'test'), $this->projectDir), $this->projectDir);
    }
}
