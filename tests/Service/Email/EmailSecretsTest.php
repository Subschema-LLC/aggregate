<?php

declare(strict_types=1);

namespace App\Tests\Service\Email;

use App\Service\Email\EmailSecrets;
use App\Service\Email\Provider\MailgunProvider;
use App\Service\Email\Provider\PostmarkProvider;
use App\Service\Operations\ConfigurationChanges;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

final class EmailSecretsTest extends TestCase
{
    private string $projectDir;
    private array $environment;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        unset($_ENV['EMAIL_POSTMARK_SERVER_TOKEN'], $_SERVER['EMAIL_POSTMARK_SERVER_TOKEN'], $_ENV['EMAIL_MAILGUN_API_KEY'], $_SERVER['EMAIL_MAILGUN_API_KEY']);
        $this->projectDir = sys_get_temp_dir().'/aggregate-email-secrets-'.bin2hex(random_bytes(8));
        mkdir($this->projectDir.'/config', 0700, true);
    }

    protected function tearDown(): void
    {
        [$_ENV, $_SERVER] = $this->environment;
        (new Filesystem())->remove($this->projectDir);
    }

    public function testACredentialIsKeptPrivatelyAndOnlyItsNameIsReported(): void
    {
        $secrets = new EmailSecrets($this->projectDir);
        $postmark = (new PostmarkProvider())->credential();
        $mailgun = (new MailgunProvider())->credential();
        self::assertNull($secrets->get($postmark));
        self::assertNull($secrets->source($postmark));

        ConfigurationChanges::start();
        $secrets->set($postmark, '  server-token-1  ');
        $secrets->set($mailgun, 'key-2');
        $changes = ConfigurationChanges::finish();

        self::assertSame('server-token-1', $secrets->get($postmark));
        self::assertSame(EmailSecrets::SOURCE_FILE, $secrets->source($postmark));
        self::assertSame(['config/secrets/email.yaml' => ['mailgun_api_key', 'postmark_server_token']], $changes);
        self::assertStringNotContainsString('server-token-1', (string) json_encode($changes));
        self::assertSame(0600, fileperms($secrets->path()) & 0777);
        self::assertSame(['mailgun_api_key' => 'key-2', 'postmark_server_token' => 'server-token-1'], Yaml::parseFile($secrets->path()));

        $secrets->remove($postmark);
        self::assertNull($secrets->get($postmark));
        self::assertSame('key-2', $secrets->get($mailgun), 'Other providers keep their keys');
    }

    public function testTheEnvironmentTakesPrecedence(): void
    {
        $secrets = new EmailSecrets($this->projectDir);
        $postmark = (new PostmarkProvider())->credential();
        $secrets->set($postmark, 'from-file');
        $_SERVER['EMAIL_POSTMARK_SERVER_TOKEN'] = 'from-environment';

        self::assertSame('from-environment', $secrets->get($postmark));
        self::assertSame(EmailSecrets::SOURCE_ENVIRONMENT, $secrets->source($postmark));
        self::assertTrue($secrets->isEnvironmentControlled($postmark));
    }

    public function testAMultiLineOrEmptyValueIsRefused(): void
    {
        $secrets = new EmailSecrets($this->projectDir);
        foreach (["two\nlines", '   '] as $value) {
            try {
                $secrets->set((new PostmarkProvider())->credential(), $value);
                self::fail('Accepted an invalid credential');
            } catch (\InvalidArgumentException $e) {
                self::assertStringNotContainsString('lines', $e->getMessage(), 'The value is not echoed');
            }
        }
        self::assertFileDoesNotExist($secrets->path());
    }

    public function testAnInvalidFileProvidesNothingAndIsNotOverwritten(): void
    {
        $secrets = new EmailSecrets($this->projectDir);
        mkdir($this->projectDir.'/config/secrets', 0700);
        file_put_contents($secrets->path(), "postmark_server_token: [unclosed\n  secret-line");
        $postmark = (new PostmarkProvider())->credential();

        self::assertNull($secrets->source($postmark));
        try {
            $secrets->get($postmark);
            self::fail('Read an invalid file');
        } catch (\RuntimeException $e) {
            self::assertSame('config/secrets/email.yaml is not valid YAML.', $e->getMessage(), 'The parser message could quote the file');
        }
        try {
            $secrets->set($postmark, 'new');
            self::fail('Wrote over an invalid file');
        } catch (\RuntimeException) {
        }
        self::assertStringContainsString('secret-line', (string) file_get_contents($secrets->path()));
    }
}
