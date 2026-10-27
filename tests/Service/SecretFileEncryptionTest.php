<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AggregateConfigLoader;
use App\Service\BigQuery\BigQuerySecrets;
use App\Service\BigQuery\BigQuerySettings;
use App\Service\Email\EmailCredential;
use App\Service\Email\EmailSecrets;
use App\Service\Mcp\OAuth\OAuthStore;
use App\Service\Operations\ConfigurationChanges;
use App\Service\SecretFileEncryption;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class SecretFileEncryptionTest extends TestCase
{
    private const KEY_ONE = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
    private const KEY_TWO = 'fedcba9876543210fedcba9876543210fedcba9876543210fedcba9876543210';

    private string $projectDir;
    private array $savedEnvironment;

    protected function setUp(): void
    {
        $this->savedEnvironment = [$_ENV, $_SERVER];
        unset($_ENV[SecretFileEncryption::ENV], $_SERVER[SecretFileEncryption::ENV]);
        $this->projectDir = sys_get_temp_dir().'/aggregate-secret-encryption-'.bin2hex(random_bytes(8));
        (new Filesystem())->mkdir($this->projectDir.'/config/secrets', 0700);
    }

    protected function tearDown(): void
    {
        [$_ENV, $_SERVER] = $this->savedEnvironment;
        ConfigurationChanges::finish();
        (new Filesystem())->remove($this->projectDir);
    }

    public function testEmailBigQueryAndMcpOAuthSecretsAreEncryptedAtRestWhenKeyIsSet(): void
    {
        $this->setKey(self::KEY_ONE);

        // 1. Email secrets
        $smtpPassword = new EmailCredential('smtp_password', 'SMTP password', 'The password for SMTP.');
        $email = new EmailSecrets($this->projectDir);
        $email->set($smtpPassword, 'smtp-super-secret-password');
        $rawEmail = (string) file_get_contents($email->path());
        self::assertTrue(SecretFileEncryption::isEncrypted($rawEmail));
        self::assertStringNotContainsString('smtp-super-secret-password', $rawEmail);
        self::assertSame('smtp-super-secret-password', $email->get($smtpPassword));

        // 2. BigQuery service-account key and OAuth sign-in
        $config = new AggregateConfigLoader($this->projectDir, 'test');
        $bigQuery = new BigQuerySecrets(new BigQuerySettings($config, $this->projectDir), $this->projectDir);
        $serviceAccountJson = $this->validServiceAccountJson();
        $bigQuery->storeServiceAccountKey($serviceAccountJson);
        $rawKeyFile = (string) file_get_contents($this->projectDir.'/'.BigQuerySettings::DEFAULT_CREDENTIALS_FILE);
        self::assertTrue(SecretFileEncryption::isEncrypted($rawKeyFile));
        self::assertStringNotContainsString('BEGIN PRIVATE KEY', $rawKeyFile);
        self::assertSame('analytics-sync@example-project.iam.gserviceaccount.com', $bigQuery->serviceAccountKey()?->clientEmail);

        $bigQuery->storeClientSecret('GOCSPX-secret-client-value');
        $bigQuery->storeSignIn('1//refresh-token-secret', 'client-123.apps.googleusercontent.com', 'admin@example.com', new \DateTimeImmutable('2026-10-25T12:00:00Z'));
        $rawSignIn = (string) file_get_contents($this->projectDir.'/'.BigQuerySecrets::SIGN_IN_FILE);
        self::assertTrue(SecretFileEncryption::isEncrypted($rawSignIn));
        self::assertStringNotContainsString('1//refresh-token-secret', $rawSignIn);
        self::assertSame('1//refresh-token-secret', $bigQuery->refreshToken());

        // 3. MCP OAuth store
        $oauth = new OAuthStore($this->projectDir);
        $client = $oauth->registerClient('Claude Desktop', ['https://claude.ai/api/mcp/auth_callback']);
        $rawOAuth = (string) file_get_contents($oauth->path());
        self::assertTrue(SecretFileEncryption::isEncrypted($rawOAuth));
        self::assertStringNotContainsString('Claude Desktop', $rawOAuth);
        self::assertSame('Claude Desktop', $oauth->client($client->id)?->name);
    }

    public function testExistingPlainTextFilesRemainReadableAndUpgradeOnSave(): void
    {
        $postmark = new EmailCredential('postmark_server_token', 'Server token', 'The Postmark token.');
        $smtpPassword = new EmailCredential('smtp_password', 'SMTP password', 'The password for SMTP.');
        $email = new EmailSecrets($this->projectDir);
        $email->set($postmark, 'plain-postmark-token');
        self::assertFalse(SecretFileEncryption::isEncrypted((string) file_get_contents($email->path())));

        $this->setKey(self::KEY_ONE);
        self::assertSame('plain-postmark-token', $email->get($postmark));

        $email->set($smtpPassword, 'new-smtp-secret');
        $raw = (string) file_get_contents($email->path());
        self::assertTrue(SecretFileEncryption::isEncrypted($raw));
        self::assertStringNotContainsString('plain-postmark-token', $raw);
        self::assertSame('plain-postmark-token', $email->get($postmark));
        self::assertSame('new-smtp-secret', $email->get($smtpPassword));
    }

    public function testMissingWrongOrSwappedFileKeyFailsClosed(): void
    {
        $this->setKey(self::KEY_ONE);
        $smtpPassword = new EmailCredential('smtp_password', 'SMTP password', 'The password for SMTP.');
        $email = new EmailSecrets($this->projectDir);
        $email->set($smtpPassword, 'smtp-secret');
        $oauth = new OAuthStore($this->projectDir);
        $oauth->registerClient('Assistant', ['https://assistant.example/callback']);

        // Swapping encrypted files across paths fails AEAD associated-data verification
        copy($email->path(), $oauth->path());
        try {
            $oauth->connections();
            self::fail('Swapped secret file must not decrypt under another path.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('could not be decrypted', $e->getMessage());
        }

        // Wrong key fails closed
        $this->setKey(self::KEY_TWO);
        try {
            $email->read();
            self::fail('Wrong key must fail decryption.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('could not be decrypted', $e->getMessage());
        }

        // Missing key fails closed
        unset($_ENV[SecretFileEncryption::ENV], $_SERVER[SecretFileEncryption::ENV]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Set AGGREGATE_SECRETS_KEY in the environment');
        $email->read();
    }

    private function setKey(string $key): void
    {
        $_ENV[SecretFileEncryption::ENV] = $_SERVER[SecretFileEncryption::ENV] = $key;
    }

    private function validServiceAccountJson(): string
    {
        $keyPair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($keyPair);
        openssl_pkey_export($keyPair, $pem);

        return json_encode([
            'type' => 'service_account',
            'project_id' => 'example-project',
            'private_key_id' => str_repeat('a', 40),
            'private_key' => $pem,
            'client_email' => 'analytics-sync@example-project.iam.gserviceaccount.com',
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ], JSON_THROW_ON_ERROR);
    }
}
