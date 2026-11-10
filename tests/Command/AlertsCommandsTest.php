<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\AlertsDestinationCommand;
use App\Command\AlertsEvaluateCommand;
use App\Command\AlertsRuleCommand;
use App\Command\AlertsStatusCommand;
use App\Command\AlertsTestCommand;
use App\Repository\EventRepository;
use App\Service\AggregateConfigLoader;
use App\Service\Alerts\AlertEvaluator;
use App\Service\Alerts\AlertRuleValidator;
use App\Service\Alerts\AlertSecrets;
use App\Service\Alerts\AlertSettings;
use App\Service\Alerts\WebhookSender;
use App\Service\AppBranding;
use App\Service\Email\EmailProviders;
use App\Service\Email\EmailSecrets;
use App\Service\Email\EmailSettings;
use App\Service\Email\TransactionalMailer;
use App\Service\FeatureFlags;
use App\Service\Operations\AuditTrail;
use App\Service\Operations\ProcessingTasks;
use App\Service\WebsiteConfigManager;
use App\Service\WebsiteScopeCatalog;
use App\Tests\Service\Alerts\AlertsTestDatabase;
use App\Tests\Service\Email\EmailTestKit;
use App\Tests\Service\Operations\OperationsDatabase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Yaml\Yaml;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class AlertsCommandsTest extends TestCase
{
    private const TOKEN_A = '019c7e21-89ab-7cde-8f01-23456789abcd';

    private string $projectDir;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/aggregate-alerts-commands-'.bin2hex(random_bytes(8));
        mkdir($this->projectDir.'/config', 0700, true);
        $this->connection = AlertsTestDatabase::connection();

        file_put_contents($this->projectDir.'/config/websites.yaml', Yaml::dump([
            'websites' => [
                ['name' => 'Geektown USA', 'token' => self::TOKEN_A, 'domain' => 'some.domain.com'],
            ],
        ], 6, 2));
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump([
            'app_host' => 'https://analytics.example.com',
            'feature_flags' => [
                'alerts' => ['enabled' => true, 'hide_from_navigation' => false],
            ],
        ], 6, 2));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectDir);
    }

    public function testDestinationRuleTestEvaluateAndStatusCommandsWorkAndShareValidation(): void
    {
        $destTester = new CommandTester($this->destinationCommand());
        self::assertSame(Command::SUCCESS, $destTester->execute([
            'action' => 'create',
            '--id' => 'geektown-hub',
            '--label' => 'Geektown Hub',
            '--type' => 'webhook',
            '--url' => 'https://hooks.geektown.example/alerts/ingest',
            '--generate-secret' => true,
        ]));
        self::assertStringContainsString('Webhook signing secret (shown once, copy it now):', $destTester->getDisplay());

        // Reject invalid HTTP webhook URL with the same validator message.
        self::assertSame(Command::INVALID, $destTester->execute([
            'action' => 'create',
            '--id' => 'bad-hook',
            '--label' => 'Bad Hook',
            '--type' => 'webhook',
            '--url' => 'http://hooks.geektown.example/alerts',
        ]));
        self::assertStringContainsString('Webhook URLs must use HTTPS.', $destTester->getDisplay());

        $ruleTester = new CommandTester($this->ruleCommand());
        self::assertSame(Command::SUCCESS, $ruleTester->execute([
            'action' => 'create',
            '--id' => 'geektown-weekly',
            '--website' => self::TOKEN_A,
            '--source' => 'events',
            '--condition' => 'fewer_than',
            '--threshold' => '5',
            '--window' => 'week',
            '--destination' => ['geektown-hub'],
        ]));
        self::assertStringContainsString('fewer_than_5_per_week', $ruleTester->getDisplay());

        // Test command sends a test alert and does not change rule status.
        $testTester = new CommandTester($this->testCommand());
        self::assertSame(Command::SUCCESS, $testTester->execute(['destination' => 'geektown-hub']));
        self::assertStringContainsString('Rule status did not change', (string) preg_replace('/\s+/', ' ', $testTester->getDisplay()));

        // Status command in JSON never exposes full webhook URL or secret.
        $statusTester = new CommandTester($this->statusCommand());
        self::assertSame(Command::SUCCESS, $statusTester->execute(['--json' => true]));
        $json = json_decode($statusTester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($json['enabled']);
        self::assertSame('waiting', $json['rules'][0]['state']);
        self::assertSame('hooks.geektown.example', $json['destinations'][0]['target']);
        self::assertStringNotContainsString('/alerts/ingest', $statusTester->getDisplay());

        // Evaluate command evaluates completed UTC hour and fires rule when 0 events exist.
        $evalTester = new CommandTester($this->evaluateCommand());
        self::assertSame(Command::SUCCESS, $evalTester->execute(['--json' => true]));
        $evalResult = json_decode($evalTester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ran', $evalResult['status']);
        self::assertSame(1, $evalResult['sent']);
    }

    private function settings(): AlertSettings
    {
        $config = new AggregateConfigLoader($this->projectDir, 'test');
        $websites = new WebsiteConfigManager($this->projectDir);
        $scopes = new WebsiteScopeCatalog($this->projectDir, 'test');
        $validator = new AlertRuleValidator($websites, $scopes);

        return new AlertSettings($config, $validator, $websites, $scopes);
    }

    private function destinationCommand(): AlertsDestinationCommand
    {
        $config = new AggregateConfigLoader($this->projectDir, 'test');

        return new AlertsDestinationCommand($this->settings(), new AlertSecrets($this->projectDir), new FeatureFlags($config));
    }

    private function ruleCommand(): AlertsRuleCommand
    {
        $config = new AggregateConfigLoader($this->projectDir, 'test');

        return new AlertsRuleCommand($this->settings(), new FeatureFlags($config));
    }

    private function testCommand(): AlertsTestCommand
    {
        $config = new AggregateConfigLoader($this->projectDir, 'test');

        return new AlertsTestCommand($this->evaluator(), new FeatureFlags($config));
    }

    private function evaluateCommand(): AlertsEvaluateCommand
    {
        $config = new AggregateConfigLoader($this->projectDir, 'test');

        return new AlertsEvaluateCommand($this->evaluator(), new FeatureFlags($config));
    }

    private function statusCommand(): AlertsStatusCommand
    {
        $config = new AggregateConfigLoader($this->projectDir, 'test');

        return new AlertsStatusCommand(
            $this->settings(),
            new AlertSecrets($this->projectDir),
            $this->evaluator(),
            $this->mailer($config),
            new FeatureFlags($config),
        );
    }

    private function evaluator(): AlertEvaluator
    {
        $config = new AggregateConfigLoader($this->projectDir, 'test');
        $audit = new AuditTrail($this->connection);
        $tasks = new ProcessingTasks($this->connection, $audit);
        $eventsRepo = $this->createStub(EventRepository::class);
        $eventsRepo->method('countEventsUpTo')->willReturn(0);
        $clock = new MockClock('2025-06-02 14:05:00 UTC');

        $webhookClient = new MockHttpClient(new MockResponse('{"ok":true}', ['http_code' => 202]));
        $webhookSender = new WebhookSender(
            $webhookClient,
            new AlertSecrets($this->projectDir),
            new AlertRuleValidator(),
            new NullLogger(),
            $clock,
            publicAddressesOnly: false,
        );

        return new AlertEvaluator(
            $this->settings(),
            $eventsRepo,
            $tasks,
            $audit,
            $this->connection,
            $webhookSender,
            $this->mailer($config),
            $config,
            new FeatureFlags($config),
            new NullLogger(),
            $clock,
        );
    }

    private function mailer(AggregateConfigLoader $config): TransactionalMailer
    {
        $tasks = new ProcessingTasks($this->connection, new AuditTrail($this->connection));
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 2).'/templates'), ['strict_variables' => true, 'autoescape' => 'name']);

        return new TransactionalMailer(
            new EmailSettings($config, EmailProviders::builtIn()),
            new EmailSecrets($this->projectDir),
            $tasks,
            $twig,
            new AppBranding($config, $this->projectDir),
            $config,
            new NullLogger(),
            EmailTestKit::transports(new MockHttpClient(new MockResponse('', ['http_code' => 202]))),
        );
    }
}
