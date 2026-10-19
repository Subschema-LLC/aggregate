<?php

declare(strict_types=1);

namespace App\Tests\Service\Email;

use App\Service\AggregateConfigLoader;
use App\Service\Email\EmailProviders;
use App\Service\Email\EmailSettings;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

final class EmailSettingsTest extends TestCase
{
    private string $projectDir;
    private array $environment;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        $this->projectDir = sys_get_temp_dir().'/aggregate-email-settings-'.bin2hex(random_bytes(8));
        mkdir($this->projectDir.'/config', 0700, true);
    }

    protected function tearDown(): void
    {
        [$_ENV, $_SERVER] = $this->environment;
        (new Filesystem())->remove($this->projectDir);
    }

    public function testEmailStartsOffWithNoServiceChosen(): void
    {
        $settings = $this->settings()->toArray();

        self::assertFalse($settings['email_enabled']);
        self::assertSame('', $settings['email_provider']);
        self::assertSame('outbound', $settings['email_postmark_message_stream']);
        self::assertSame('global', $settings['email_sendgrid_region']);
        self::assertSame('us', $settings['email_mailgun_region']);
        self::assertSame(587, $settings['email_smtp_port']);
        self::assertSame('starttls', $settings['email_smtp_encryption']);
    }

    public function testTurningEmailOnNeedsAServiceAndASender(): void
    {
        foreach ([
            [['email_enabled' => true], 'Choose the email service'],
            [['email_enabled' => true, 'email_provider' => 'postmark'], 'Enter the address email is sent from'],
            [['email_provider' => 'acme'], 'email_provider must be one of postmark, sendgrid, mailgun, smtp, dsn'],
            [['email_from_address' => 'not an address'], 'email_from_address must be an email address'],
            [['email_reply_to' => 'nobody@'], 'email_reply_to must be an email address'],
            [['email_from_name' => "Two\nlines"], 'email_from_name must be a name'],
            [['email_enabled' => 'maybe'], 'email_enabled must be true or false'],
        ] as [$values, $message]) {
            try {
                $this->settings()->validate($values);
                self::fail('Accepted '.json_encode($values));
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }

        $valid = $this->settings()->validate(['email_enabled' => 'true', 'email_provider' => ' Postmark ', 'email_from_address' => ' no-reply@example.com ']);
        self::assertTrue($valid['email_enabled']);
        self::assertSame('postmark', $valid['email_provider']);
        self::assertSame('no-reply@example.com', $valid['email_from_address']);
    }

    public function testSavingWritesYamlButLeavesEnvironmentControlledSettings(): void
    {
        $_ENV['EMAIL_FROM_ADDRESS'] = 'ops@example.com';
        $_ENV['EMAIL_REPLY_TO'] = '';

        $saved = $this->settings()->save([
            'email_enabled' => '1',
            'email_provider' => 'sendgrid',
            'email_from_address' => 'ignored@example.com',
            'email_from_name' => 'Example Analytics',
            'email_reply_to' => 'help@example.com',
            'email_sendgrid_region' => 'eu',
        ]);

        self::assertSame('ops@example.com', $saved['email_from_address'], 'The environment wins');
        self::assertSame('', $saved['email_reply_to'], 'An empty environment variable still overrides YAML');
        $yaml = Yaml::parseFile($this->projectDir.'/config/aggregate.yaml');
        self::assertTrue($yaml['email_enabled']);
        self::assertSame('sendgrid', $yaml['email_provider']);
        self::assertSame('eu', $yaml['email_sendgrid_region']);
        self::assertArrayNotHasKey('email_from_address', $yaml);
        self::assertArrayNotHasKey('email_reply_to', $yaml);
        $overrides = $this->settings()->environmentOverrides();
        self::assertTrue($overrides['email_from_address']);
        self::assertTrue($overrides['email_reply_to']);
        self::assertFalse($overrides['email_provider']);
    }

    public function testAnInvalidCandidateOrUnknownSettingSavesNothing(): void
    {
        foreach ([['email_provider' => 'mailgun', 'email_enabled' => true, 'email_from_address' => 'a@example.com'], ['email_password' => 'x']] as $candidate) {
            try {
                $this->settings()->save($candidate);
                self::fail('Saved '.json_encode($candidate));
            } catch (\InvalidArgumentException) {
            }
        }

        self::assertFileDoesNotExist($this->projectDir.'/config/aggregate.yaml');
    }

    public function testEnvironmentVariablesConfigureEmailWithoutYaml(): void
    {
        $_ENV['EMAIL_ENABLED'] = 'true';
        $_ENV['EMAIL_PROVIDER'] = 'smtp';
        $_ENV['EMAIL_FROM_ADDRESS'] = 'no-reply@example.com';
        $_ENV['EMAIL_SMTP_HOST'] = 'smtp.example.com';
        $_ENV['EMAIL_SMTP_PORT'] = '2525';

        $settings = $this->settings()->toArray();

        self::assertTrue($settings['email_enabled']);
        self::assertSame('smtp.example.com', $settings['email_smtp_host']);
        self::assertSame(2525, $settings['email_smtp_port']);
    }

    private function settings(): EmailSettings
    {
        return new EmailSettings(new AggregateConfigLoader($this->projectDir, 'test'), EmailProviders::builtIn());
    }
}
