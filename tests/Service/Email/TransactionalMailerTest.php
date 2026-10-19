<?php

declare(strict_types=1);

namespace App\Tests\Service\Email;

use App\Service\AggregateConfigLoader;
use App\Service\AppBranding;
use App\Service\Email\EmailProviders;
use App\Service\Email\EmailPurpose;
use App\Service\Email\EmailSecrets;
use App\Service\Email\EmailSettings;
use App\Service\Email\Provider\PostmarkProvider;
use App\Service\Email\TransactionalMailer;
use App\Service\Operations\AuditTrail;
use App\Service\Operations\ProcessingTasks;
use App\Service\Operations\TaskTrigger;
use App\Tests\Service\Operations\OperationsDatabase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Yaml\Yaml;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class TransactionalMailerTest extends TestCase
{
    private const RECIPIENT = 'person@example.org';

    private string $projectDir;
    private array $environment;
    private Connection $connection;
    /** @var list<array{url: string, body: array<string, mixed>}> */
    private array $requests = [];
    /** @var list<array{string, string, array<string, mixed>}> */
    private array $logs = [];

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        foreach (['EMAIL_POSTMARK_SERVER_TOKEN', 'EMAIL_ENABLED', 'EMAIL_PROVIDER', 'APP_HOST'] as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
        }
        $this->projectDir = sys_get_temp_dir().'/aggregate-mailer-'.bin2hex(random_bytes(8));
        mkdir($this->projectDir.'/config', 0700, true);
        $this->connection = OperationsDatabase::connection();
        $this->configure();
        (new EmailSecrets($this->projectDir))->set((new PostmarkProvider())->credential(), 'server-token-123');
    }

    protected function tearDown(): void
    {
        [$_ENV, $_SERVER] = $this->environment;
        (new Filesystem())->remove($this->projectDir);
    }

    public function testASendIsRecordedWithTheProviderMessageIdAndNoAddress(): void
    {
        $result = $this->mailer(['MessageID' => 'b7bc2f4a-e38e-4336-af7d-e6c392c2f817'])
            ->send(EmailPurpose::Test, self::RECIPIENT, [], TaskTrigger::dashboard('scott'));

        self::assertTrue($result->sent);
        self::assertSame('b7bc2f4a-e38e-4336-af7d-e6c392c2f817', $result->messageId);
        $task = $this->connection->fetchAssociative('SELECT * FROM processing_tasks');
        self::assertSame('email_send', $task['task_type']);
        self::assertSame('test', $task['subject']);
        self::assertSame('succeeded', $task['status']);
        self::assertSame('dashboard', $task['triggered_by']);
        self::assertSame('scott', $task['requested_by']);
        self::assertSame('b7bc2f4a-e38e-4336-af7d-e6c392c2f817', $task['external_id']);
        self::assertSame('Sent through Postmark.', $task['details']);
        $audit = $this->connection->fetchAssociative('SELECT * FROM audit_trail');
        self::assertSame(['task', 'email_send', 'test', 'succeeded', 'scott'], [$audit['category'], $audit['operation'], $audit['subject'], $audit['outcome'], $audit['actor']]);
        $this->assertNoAddressIsStored();

        $body = $this->requests[0]['body'];
        self::assertSame('"Example & Co" <no-reply@example.com>', $body['From']);
        self::assertSame(self::RECIPIENT, $body['To']);
        self::assertSame('Test email from Example & Co', $body['Subject']);
        self::assertSame('test', $body['Tag']);
        self::assertSame(['aggregate_task' => (string) $task['id']], $body['Metadata']);
        self::assertSame('outbound', $body['MessageStream']);
        self::assertContains(['Name' => 'Auto-Submitted', 'Value' => 'auto-generated'], $body['Headers']);
        self::assertStringContainsString('Example &amp; Co', $body['HtmlBody'], 'HTML is escaped');
        self::assertStringContainsString('Other messages from Example & Co', $body['TextBody'], 'Text is not');
        self::assertStringContainsString('https://analytics.example.com', $body['TextBody']);
        self::assertStringContainsString('2026-10-19 12:30 UTC', $body['TextBody']);
    }

    public function testAFailureIsRecordedWithTheProvidersReasonRedacted(): void
    {
        $result = $this->mailer(['ErrorCode' => 300, 'Message' => "Invalid 'To' address: '".self::RECIPIENT."'. Token server-token-123 rejected."], 422)
            ->send(EmailPurpose::Test, self::RECIPIENT, [], TaskTrigger::command());

        self::assertFalse($result->sent);
        self::assertStringStartsWith('Postmark did not accept the message:', (string) $result->error);
        self::assertStringContainsString('Invalid \'To\' address', (string) $result->error);
        self::assertStringNotContainsString('server-token-123', (string) $result->error);
        $task = $this->connection->fetchAssociative('SELECT * FROM processing_tasks');
        self::assertSame('failed', $task['status']);
        self::assertSame($result->error, $task['details']);
        self::assertSame('failed', $this->connection->fetchOne('SELECT outcome FROM audit_trail'));
        $this->assertNoAddressIsStored();
        self::assertSame('warning', $this->logs[0][0]);
        self::assertStringNotContainsString(self::RECIPIENT, (string) json_encode($this->logs));
        self::assertStringNotContainsString('server-token-123', (string) json_encode($this->logs));
    }

    public function testNothingIsSentUntilAServiceIsSetUp(): void
    {
        $this->configure(['email_enabled' => false, 'email_provider' => '']);

        $result = $this->mailer([])->send(EmailPurpose::Test, self::RECIPIENT, [], TaskTrigger::command());

        self::assertFalse($result->sent);
        self::assertSame('Not sent: No email service is chosen.', $result->error);
        self::assertSame([], $this->requests);
        self::assertSame('failed', $this->connection->fetchOne('SELECT status FROM processing_tasks'));
    }

    public function testATestMessageCanBeSentBeforeEmailIsTurnedOn(): void
    {
        $this->configure(['email_enabled' => false]);
        $mailer = $this->mailer(['MessageID' => 'x']);

        self::assertNull($mailer->problem(null, false));
        self::assertSame('Email is turned off.', $mailer->problem());
        self::assertTrue($mailer->send(EmailPurpose::Test, self::RECIPIENT, [], TaskTrigger::command())->sent);
    }

    public function testAProblemNamesWhatIsMissing(): void
    {
        $mailer = $this->mailer([]);
        self::assertNull($mailer->problem());

        (new EmailSecrets($this->projectDir))->remove((new PostmarkProvider())->credential());
        self::assertSame('The Postmark server api token is not set.', $mailer->problem());

        $this->configure(['email_provider' => 'mailgun', 'email_enabled' => false]);
        self::assertSame('Enter the Mailgun sending domain, such as mg.example.com.', $this->mailer([])->problem(null, false));

        $this->configure(['email_from_address' => 'nobody']);
        $result = $this->mailer([])->send(EmailPurpose::Test, self::RECIPIENT, [], TaskTrigger::command());
        self::assertSame('Not sent: the email settings are invalid. email_from_address must be an email address, such as [address].', $result->error);
    }

    public function testRedactionRemovesAddressesAndGivenValues(): void
    {
        self::assertSame(
            'Rejected <[address]> using [redacted] (code 406)',
            TransactionalMailer::redact("Rejected <someone.else@example.net> using secret-key\n(code 406)", ['secret-key']),
        );
        self::assertSame('<20261019.abc@mg.example.com>', TransactionalMailer::redact('<20261019.abc@mg.example.com>', [], 191, false));
        self::assertSame('No reason was given.', TransactionalMailer::redact(" \n "));
    }

    private function assertNoAddressIsStored(): void
    {
        foreach (['processing_tasks', 'audit_trail'] as $table) {
            foreach ($this->connection->fetchAllAssociative('SELECT * FROM '.$table) as $row) {
                $stored = implode(' ', array_map('strval', array_filter($row, static fn (mixed $value): bool => $value !== null)));
                self::assertStringNotContainsString('@', $stored, $table.' holds no address');
                self::assertStringNotContainsString('server-token-123', $stored);
            }
        }
    }

    /** @param array<string, mixed> $settings */
    private function configure(array $settings = []): void
    {
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump([
            'brand_name' => 'Example & Co',
            'app_host' => 'https://analytics.example.com',
            'email_enabled' => true,
            'email_provider' => 'postmark',
            'email_from_address' => 'no-reply@example.com',
            ...$settings,
        ]));
    }

    /** @param array<string, mixed> $answer the provider's JSON answer */
    private function mailer(array $answer, int $status = 200): TransactionalMailer
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($answer, $status): MockResponse {
            $this->requests[] = ['url' => $url, 'body' => json_decode((string) $options['body'], true)];

            return new MockResponse((string) json_encode($answer), ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json']]);
        });
        $config = new AggregateConfigLoader($this->projectDir, 'test');
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3).'/templates'), ['strict_variables' => true, 'autoescape' => 'name']);
        $logger = new class($this->logs) extends AbstractLogger {
            /** @param list<array{string, string, array<string, mixed>}> $logs */
            public function __construct(private array &$logs)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logs[] = [(string) $level, (string) $message, $context];
            }
        };

        return new TransactionalMailer(
            new EmailSettings($config, EmailProviders::builtIn()),
            new EmailSecrets($this->projectDir),
            new ProcessingTasks($this->connection, new AuditTrail($this->connection)),
            $twig,
            new AppBranding($config, $this->projectDir),
            $config,
            $logger,
            EmailTestKit::transports($client),
            new MockClock('2026-10-19 12:30:00 UTC'),
        );
    }
}
