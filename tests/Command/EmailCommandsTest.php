<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\EmailStatusCommand;
use App\Command\EmailTestCommand;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\AggregateConfigLoader;
use App\Service\AppBranding;
use App\Service\Email\EmailProviders;
use App\Service\Email\EmailSecrets;
use App\Service\Email\EmailSettings;
use App\Service\Email\Provider\SendgridProvider;
use App\Service\Email\TransactionalMailer;
use App\Service\Operations\AuditTrail;
use App\Service\Operations\ProcessingTasks;
use App\Tests\Service\Email\EmailTestKit;
use App\Tests\Service\Operations\OperationsDatabase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Yaml\Yaml;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class EmailCommandsTest extends TestCase
{
    private string $projectDir;
    private array $environment;
    private Connection $connection;
    /** @var list<string> */
    private array $recipients = [];

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        foreach (['EMAIL_ENABLED', 'EMAIL_PROVIDER', 'EMAIL_SENDGRID_API_KEY'] as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
        }
        $this->projectDir = sys_get_temp_dir().'/aggregate-email-commands-'.bin2hex(random_bytes(8));
        mkdir($this->projectDir.'/config', 0700, true);
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump(['email_provider' => 'sendgrid', 'email_from_address' => 'no-reply@example.com']));
        (new EmailSecrets($this->projectDir))->set((new SendgridProvider())->credential(), 'SG.secret-key');
        $this->connection = OperationsDatabase::connection();
    }

    protected function tearDown(): void
    {
        [$_ENV, $_SERVER] = $this->environment;
        (new Filesystem())->remove($this->projectDir);
    }

    public function testATestGoesToAnAddressOrAUsersAddressWhileEmailIsOff(): void
    {
        $tester = new CommandTester(new EmailTestCommand($this->mailer(), $this->users()));

        self::assertSame(Command::SUCCESS, $tester->execute(['recipient' => 'you@example.com']));
        self::assertStringContainsString('message ID is sg-1', $tester->getDisplay());
        self::assertSame(Command::SUCCESS, $tester->execute(['recipient' => 'scott']));
        self::assertSame(['you@example.com', 'scott@example.com'], $this->recipients);
        self::assertSame(['command', 'command'], $this->connection->fetchFirstColumn('SELECT triggered_by FROM processing_tasks'));

        self::assertSame(Command::FAILURE, $tester->execute(['recipient' => 'nobody']));
        self::assertStringContainsString('No user with that username has an email address', $tester->getDisplay());
        self::assertSame(Command::FAILURE, $tester->execute(['recipient' => 'not@valid@']));
        self::assertCount(2, $this->recipients);
    }

    public function testStatusShowsWhereCredentialsComeFromButNeverTheirValues(): void
    {
        $tester = new CommandTester(new EmailStatusCommand($this->settings(), new EmailSecrets($this->projectDir), $this->mailer(), $this->tasks()));

        self::assertSame(Command::FAILURE, $tester->execute(['--json' => true]), 'Nothing is sent while email is off');
        $status = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($status['enabled']);
        self::assertSame('sendgrid', $status['provider']);
        self::assertSame('Email is turned off.', $status['problem']);
        self::assertSame(['credential' => 'sendgrid_api_key', 'source' => 'file'], $status['credentials']['sendgrid']);
        self::assertNull($status['credentials']['postmark']['source']);
        self::assertNull($status['sends']['test']);
        self::assertStringNotContainsString('secret-key', $tester->getDisplay());

        (new CommandTester(new EmailTestCommand($this->mailer(), $this->users())))->execute(['recipient' => 'you@example.com']);
        $_SERVER['EMAIL_ENABLED'] = 'true';
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Email is on and sends through SendGrid from no-reply@example.com', $tester->getDisplay());
        self::assertStringContainsString('sg-1', $tester->getDisplay());
        self::assertStringNotContainsString('you@example.com', $tester->getDisplay());
    }

    private function settings(): EmailSettings
    {
        return new EmailSettings(new AggregateConfigLoader($this->projectDir, 'test'), EmailProviders::builtIn());
    }

    private function tasks(): ProcessingTasks
    {
        return new ProcessingTasks($this->connection, new AuditTrail($this->connection));
    }

    private function mailer(): TransactionalMailer
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->recipients[] = json_decode((string) $options['body'], true)['personalizations'][0]['to'][0]['email'];

            return new MockResponse('', ['http_code' => 202, 'response_headers' => ['x-message-id' => 'sg-1']]);
        });
        $config = new AggregateConfigLoader($this->projectDir, 'test');

        return new TransactionalMailer($this->settings(), new EmailSecrets($this->projectDir), $this->tasks(), new Environment(new FilesystemLoader(dirname(__DIR__, 2).'/templates'), ['autoescape' => 'name']), new AppBranding($config, $this->projectDir), $config, new NullLogger(), EmailTestKit::transports($client));
    }

    private function users(): UserRepository
    {
        $user = (new User())->setUsername('scott');
        $user->setEmail('scott@example.com');
        $users = $this->createStub(UserRepository::class);
        $users->method('findOneBy')->willReturnCallback(static fn (array $criteria): ?User => $criteria === ['username' => 'scott'] ? $user : null);

        return $users;
    }
}
