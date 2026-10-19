<?php

declare(strict_types=1);

namespace App\Tests\Service\Email;

use App\Service\AggregateConfigLoader;
use App\Service\Email\EmailProviders;
use App\Service\Email\EmailSettings;
use App\Service\Email\Provider\DsnProvider;
use App\Service\Email\Provider\MailgunProvider;
use App\Service\Email\Provider\PostmarkProvider;
use App\Service\Email\Provider\SendgridProvider;
use App\Service\Email\Provider\SmtpProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Bridge\Mailgun\Transport\MailgunApiTransport;
use Symfony\Component\Mailer\Bridge\Postmark\Transport\PostmarkApiTransport;
use Symfony\Component\Mailer\Bridge\Sendgrid\Transport\SendgridApiTransport;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mailer\Transport\NullTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Email;

final class EmailProvidersTest extends TestCase
{
    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $requests = [];

    public function testTheBuiltInProvidersAreListedInMenuOrder(): void
    {
        self::assertSame(['postmark', 'sendgrid', 'mailgun', 'smtp', 'dsn'], EmailProviders::builtIn()->codes());
    }

    public function testEveryProviderSettingIsNamespacedAndEveryCredentialHasItsOwnVariable(): void
    {
        $variables = [];
        foreach (EmailProviders::builtIn()->all() as $code => $provider) {
            foreach (array_keys($provider->settingDefaults()) as $key) {
                self::assertStringStartsWith('email_'.$code.'_', $key);
            }
            $variables[] = $provider->credential()->environmentVariable;
        }
        self::assertSame(['EMAIL_POSTMARK_SERVER_TOKEN', 'EMAIL_SENDGRID_API_KEY', 'EMAIL_MAILGUN_API_KEY', 'EMAIL_SMTP_PASSWORD', 'EMAIL_DSN'], $variables);
        $settingVariables = array_map('strtoupper', array_keys($this->settings()->defaults()));
        self::assertSame([], array_intersect($variables, $settingVariables), 'A credential variable never doubles as a setting override');
    }

    public function testPostmarkSendsToItsApiWithTheServerTokenAndMessageStream(): void
    {
        $settings = $this->validated(['email_provider' => 'postmark', 'email_postmark_message_stream' => 'sign-in']);
        $transport = (new PostmarkProvider())->transport($this->factory(['MessageID' => 'pm-1']), $settings, 'server-token');

        self::assertInstanceOf(PostmarkApiTransport::class, $transport);
        $sent = $transport->send($this->email());

        self::assertSame('pm-1', $sent?->getMessageId());
        self::assertSame('https://api.postmarkapp.com/email', $this->requests[0]['url']);
        self::assertContains('X-Postmark-Server-Token: server-token', $this->requests[0]['options']['normalized_headers']['x-postmark-server-token']);
        $payload = $this->json($this->requests[0]['options']);
        self::assertSame('sign-in', $payload['MessageStream']);
        self::assertSame('test', $payload['Tag']);
        self::assertSame(['aggregate_task' => '7'], $payload['Metadata']);
    }

    public function testSendgridUsesTheEuRegionWhenChosen(): void
    {
        $provider = new SendgridProvider();
        $global = $provider->transport($this->factory(), $this->validated([]), 'key');
        $settings = $this->validated(['email_provider' => 'sendgrid', 'email_sendgrid_region' => 'EU']);
        $transport = $provider->transport($this->factory([], ['x-message-id' => ['sg-1']], 202), $settings, 'sendgrid-key');

        self::assertSame('sendgrid+api://api.sendgrid.com', (string) $global);
        self::assertInstanceOf(SendgridApiTransport::class, $transport);
        self::assertSame('sg-1', $transport->send($this->email())?->getMessageId());
        self::assertSame('https://api.eu.sendgrid.com/v3/mail/send', $this->requests[0]['url']);
        self::assertSame(['Authorization: Bearer sendgrid-key'], $this->requests[0]['options']['normalized_headers']['authorization']);
        self::assertSame(['test'], $this->json($this->requests[0]['options'])['categories']);
        self::assertSame(['aggregate_task' => '7'], $this->json($this->requests[0]['options'])['personalizations'][0]['custom_args']);
    }

    public function testMailgunSendsForItsDomainAndRegionWithoutTracking(): void
    {
        $settings = $this->validated(['email_provider' => 'mailgun', 'email_mailgun_domain' => 'MG.Example.com', 'email_mailgun_region' => 'eu']);
        $provider = new MailgunProvider();
        $transport = $provider->transport($this->factory(['id' => '<mg-1@mg.example.com>', 'message' => 'Queued']), $settings, 'mailgun-key');
        $email = $this->email();
        $provider->prepare($email, $settings);

        self::assertInstanceOf(MailgunApiTransport::class, $transport);
        self::assertSame('<mg-1@mg.example.com>', $transport->send($email)?->getMessageId());
        self::assertSame('https://api.eu.mailgun.net/v3/mg.example.com/messages', $this->requests[0]['url']);
        self::assertSame(['Authorization: Basic '.base64_encode('api:mailgun-key')], $this->requests[0]['options']['normalized_headers']['authorization']);
        $body = $this->multipartFields($this->requests[0]['options']);
        self::assertSame('no', $body['o:tracking'] ?? null, 'Open and click tracking stay off for transactional mail');
        self::assertSame('test', $body['o:tag'] ?? null);
    }

    public function testMailgunNeedsADomainOnceChosen(): void
    {
        $this->expectExceptionMessage('Enter the Mailgun sending domain');

        $this->validated(['email_enabled' => true, 'email_provider' => 'mailgun', 'email_from_address' => 'no-reply@example.com']);
    }

    /** @return iterable<string, array{string, string, int, bool}> */
    public static function smtpEncryption(): iterable
    {
        yield 'STARTTLS is required, not just attempted' => ['starttls', 'smtp://smtp.example.com:587', 587, true];
        yield 'TLS from the start' => ['tls', 'smtps://smtp.example.com', 465, false];
        yield 'a local relay without encryption' => ['none', 'smtp://smtp.example.com', 25, false];
    }

    #[DataProvider('smtpEncryption')]
    public function testSmtpChoosesTheEncryption(string $encryption, string $name, int $port, bool $requiresStartTls): void
    {
        $settings = $this->validated(['email_provider' => 'smtp', 'email_smtp_host' => 'SMTP.example.com', 'email_smtp_port' => (string) $port, 'email_smtp_username' => 'mailer', 'email_smtp_encryption' => $encryption]);
        $transport = (new SmtpProvider())->transport($this->factory(), $settings, 'p@ss:word/with#symbols');

        self::assertInstanceOf(EsmtpTransport::class, $transport);
        self::assertSame($name, (string) $transport);
        self::assertSame($port, $transport->getStream()->getPort());
        self::assertSame($requiresStartTls, $transport->isTlsRequired());
        self::assertSame($encryption !== 'none', $transport->isAutoTls());
        self::assertSame('mailer', $transport->getUsername());
        self::assertSame('p@ss:word/with#symbols', $transport->getPassword(), 'A credential needs no URL encoding');
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidSettings(): iterable
    {
        yield 'a Postmark stream with spaces' => [['email_postmark_message_stream' => 'my stream'], 'email_postmark_message_stream'];
        yield 'an unknown SendGrid region' => [['email_sendgrid_region' => 'apac'], 'email_sendgrid_region must be global or eu'];
        yield 'a Mailgun domain that is a URL' => [['email_mailgun_domain' => 'https://mg.example.com'], 'email_mailgun_domain'];
        yield 'an SMTP port out of range' => [['email_smtp_port' => 70000], 'email_smtp_port'];
        yield 'an SMTP host with a path' => [['email_smtp_host' => 'smtp.example.com/x'], 'email_smtp_host'];
        yield 'an unknown SMTP encryption' => [['email_smtp_encryption' => 'ssl'], 'email_smtp_encryption must be starttls, tls or none'];
    }

    /** @param array<string, mixed> $values */
    #[DataProvider('invalidSettings')]
    public function testProviderSettingsAreValidatedEvenWhenAnotherProviderIsChosen(array $values, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $this->validated(['email_provider' => 'postmark', 'email_mailgun_domain' => 'mg.example.com', ...$values]);
    }

    public function testADsnBuildsAnyInstalledTransportAndNeverEchoesItsKey(): void
    {
        $provider = new DsnProvider();
        self::assertInstanceOf(NullTransport::class, $provider->transport($this->factory(), [], 'null://null'));
        self::assertSame('failover(postmark+api://api.postmarkapp.com sendgrid+api://api.sendgrid.com)', (string) $provider->transport($this->factory(), [], 'failover(postmark+api://TOKEN@default sendgrid+api://KEY@default)'));

        try {
            $provider->transport($this->factory(), [], 'ses+api://SECRETKEY:SECRET@default');
            self::fail('A scheme without its bridge is refused');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('The mailer DSN cannot be used', $e->getMessage());
            self::assertStringContainsString('symfony/amazon-mailer', $e->getMessage(), 'Says which package to install');
            self::assertStringNotContainsString('SECRETKEY', $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed>          $json    the provider's answer
     * @param array<string, list<string>>   $headers its response headers
     */
    private function factory(array $json = [], array $headers = [], int $status = 200): \Symfony\Component\Mailer\Transport
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($json, $headers, $status): MockResponse {
            if (is_callable($options['body'] ?? null)) {
                $read = '';
                while ('' !== $chunk = $options['body'](16372)) {
                    $read .= $chunk;
                }
                $options['body'] = $read;
            }
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse((string) json_encode($json), ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json', ...array_map(static fn (array $v): string => $v[0], $headers)]]);
        });

        return EmailTestKit::transports($client);
    }

    /**
     * The JSON body the mock client received.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function json(array $options): array
    {
        return json_decode((string) $options['body'], true, 512, JSON_THROW_ON_ERROR);
    }

    private function email(): Email
    {
        $email = (new Email())->from('no-reply@example.com')->to('person@example.org')->subject('Hello')->text('Hi');
        $email->getHeaders()->add(new TagHeader('test'));
        $email->getHeaders()->add(new MetadataHeader('aggregate_task', '7'));

        return $email;
    }

    /**
     * The fields of a multipart form body as the mock client received it.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, string>
     */
    private function multipartFields(array $options): array
    {
        $content = (string) $options['body'];
        preg_match_all('/name="([^"]+)"\r\n(?:[^\r\n]+\r\n)*\r\n(.*?)\r\n--/s', $content, $matches, PREG_SET_ORDER);
        $fields = [];
        foreach ($matches as [, $name, $value]) {
            $fields[$name] = $value;
        }

        return $fields;
    }

    private function settings(): EmailSettings
    {
        return new EmailSettings(new AggregateConfigLoader(sys_get_temp_dir().'/aggregate-email-providers-none', 'test'), EmailProviders::builtIn());
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function validated(array $values): array
    {
        return $this->settings()->validate($values);
    }
}
