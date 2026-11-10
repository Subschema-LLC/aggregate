<?php

declare(strict_types=1);

namespace App\Tests\Service\Alerts;

use App\Repository\EventRepository;
use App\Service\AggregateConfigLoader;
use App\Service\Alerts\AlertEvaluator;
use App\Service\Alerts\AlertRuleValidator;
use App\Service\Alerts\AlertSecrets;
use App\Service\Alerts\AlertSettings;
use App\Service\Alerts\AlertsJob;
use App\Service\Alerts\WebhookSender;
use App\Service\AppBranding;
use App\Service\Email\EmailProviders;
use App\Service\Email\EmailSecrets;
use App\Service\Email\EmailSettings;
use App\Service\Email\Provider\PostmarkProvider;
use App\Service\Email\TransactionalMailer;
use App\Service\FeatureFlags;
use App\Service\Operations\AuditTrail;
use App\Service\Operations\AuditTrailSettings;
use App\Service\Operations\ProcessingTasks;
use App\Service\Operations\TaskTrigger;
use App\Service\WebsiteConfigManager;
use App\Service\WebsiteScopeCatalog;
use App\Tests\Service\Email\EmailTestKit;
use App\Tests\Service\Operations\OperationsDatabase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Yaml\Yaml;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Delivery state, retries, catch-up and closing incidents: what happens to an
 * alert after its rule breaches, across runs.
 */
final class AlertDeliveryStateTest extends TestCase
{
    private const TOKEN_A = '019c7e21-89ab-7cde-8f01-23456789abcd';
    private const TOKEN_B = '019c7e21-89ab-7cde-8f01-23456789abce';

    private string $projectDir;
    private Connection $connection;
    private MockClock $clock;
    /** @var array<string, list<array{created_at: \DateTimeImmutable, privacy_mode: string, event_name: string}>> */
    private array $storedEvents = [];
    /** @var list<array{url: string, payload: array<string, mixed>, headers: array<string, mixed>}> */
    private array $webhookDeliveries = [];
    /** @var list<array{to: string, subject: string, text: string}> */
    private array $emailDeliveries = [];
    private int $webhookStatus = 202;
    private int $webhookAttempts = 0;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/aggregate-alert-evaluator-'.bin2hex(random_bytes(8));
        mkdir($this->projectDir.'/config', 0700, true);
        $this->connection = AlertsTestDatabase::connection();
        $this->clock = new MockClock('2025-06-02 14:05:00 UTC');

        file_put_contents($this->projectDir.'/config/websites.yaml', Yaml::dump([
            'websites' => [
                ['name' => 'Geektown USA', 'token' => self::TOKEN_A, 'domain' => 'some.domain.com'],
                ['name' => 'Geektown EU', 'token' => self::TOKEN_B, 'domain' => 'eu.domain.com'],
            ],
        ], 6, 2));
        file_put_contents($this->projectDir.'/config/website-scopes.yaml', Yaml::dump([
            'groups' => [
                'geektown-network' => [
                    'name' => 'Geektown Network',
                    'members' => [self::TOKEN_A, self::TOKEN_B],
                ],
            ],
        ], 6, 2));

        (new AlertSecrets($this->projectDir))->set('webhook-secret-key-123');
        (new EmailSecrets($this->projectDir))->set((new PostmarkProvider())->credential(), 'postmark-token-123');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectDir);
    }

    /** @return array<string, mixed> */
    private function twoRuleConfig(): array
    {
        return [
            'alerts' => [
                'destinations' => [
                    ['id' => 'hub', 'label' => 'Hub', 'type' => 'webhook', 'url' => 'https://hooks.geektown.example/in'],
                    ['id' => 'hub2', 'label' => 'Hub 2', 'type' => 'webhook', 'url' => 'https://hooks2.geektown.example/in'],
                ],
                'rules' => [
                    ['id' => 'rule-a', 'covers' => 'website:'.self::TOKEN_A, 'condition' => 'no_events', 'window' => 'hour', 'destinations' => ['hub']],
                    ['id' => 'rule-b', 'covers' => 'website:'.self::TOKEN_B, 'condition' => 'no_events', 'window' => 'hour', 'destinations' => ['hub']],
                ],
            ],
        ];
    }

    public function testRemovingOneWebsiteDoesNotStopOtherRulesOrBlockDeletingTheBrokenRule(): void
    {
        $this->writeConfig($this->twoRuleConfig());
        // A routine admin action: website A is removed from websites.yaml.
        file_put_contents($this->projectDir.'/config/websites.yaml', Yaml::dump([
            'websites' => [['name' => 'Geektown EU', 'token' => self::TOKEN_B, 'domain' => 'eu.domain.com']],
        ], 6, 2));
        file_put_contents($this->projectDir.'/config/website-scopes.yaml', Yaml::dump(['groups' => []], 6, 2));

        $result = $this->evaluator()->evaluate(TaskTrigger::schedule());
        self::assertNotSame('failed', $result['status'], 'One stale rule should not stop every rule: '.$result['message']);
        self::assertCount(1, $this->webhookDeliveries, 'rule-b (website B, breached) should still fire.');

        $config = new AggregateConfigLoader($this->projectDir, 'test');
        $websites = new WebsiteConfigManager($this->projectDir);
        $scopes = new WebsiteScopeCatalog($this->projectDir, 'test');
        $settings = new AlertSettings($config, new AlertRuleValidator($websites, $scopes), $websites, $scopes);
        $settings->deleteRule('rule-a'); // must not throw
        self::assertNull($settings->rule('rule-a'));
    }

    public function testFailedFiringIsRetriedNextRunInsteadOfReportedAsAlreadyAlerted(): void
    {
        $this->writeConfig($this->oneRuleConfig());
        $evaluator = $this->evaluator();

        $this->webhookStatus = 503;
        $first = $evaluator->evaluate(TaskTrigger::schedule());
        self::assertSame(1, $first['failed']);

        $this->webhookStatus = 202; // the receiver is back an hour later
        $this->clock->modify('+1 hour');
        $attemptsBefore = $this->webhookAttempts;
        $second = $evaluator->evaluate(TaskTrigger::schedule());

        self::assertGreaterThan($attemptsBefore, $this->webhookAttempts, 'The undelivered firing alert should be retried.');
        self::assertSame(1, $second['sent']);
        foreach ($evaluator->recentAlerts()['entries'] as $entry) {
            self::assertStringNotContainsString('Already alerted', $entry['reason'], 'Nothing was ever delivered for this gap.');
        }
    }

    public function testContinuingBreachDoesNotPushRealAlertsOutOfRecentAlerts(): void
    {
        $this->writeConfig(['alerts' => [
            'destinations' => [
                ['id' => 'hub', 'label' => 'Hub', 'type' => 'webhook', 'url' => 'https://hooks.geektown.example/in'],
                ['id' => 'hub2', 'label' => 'Hub 2', 'type' => 'webhook', 'url' => 'https://hooks2.geektown.example/in'],
            ],
            'rules' => [
                ['id' => 'grp', 'covers' => 'group:geektown-network', 'condition' => 'no_events', 'window' => 'hour', 'destinations' => ['hub', 'hub2']],
            ],
        ]]);
        $evaluator = $this->evaluator();
        for ($hour = 0; $hour < 6; ++$hour) {
            $evaluator->evaluate(TaskTrigger::schedule());
            $this->clock->modify('+1 hour');
        }

        self::assertCount(4, $this->webhookDeliveries, '2 websites x 2 destinations fired once.');
        $sent = array_filter($evaluator->recentAlerts()['entries'], static fn (array $e): bool => $e['outcome'] === 'sent');
        self::assertNotEmpty($sent, 'Six hours of one breach should not hide the alerts that were actually sent.');
    }

    public function testCatchUpNeverSendsARecoveryWhoseFiringWasNotSent(): void
    {
        $this->writeConfig($this->oneRuleConfig());
        $this->addEvent(self::TOKEN_A, '2025-06-02 13:30:00 UTC'); // healthy at the first run (14:05)
        $evaluator = $this->evaluator();
        $evaluator->evaluate(TaskTrigger::schedule());

        // The scheduler stops; the site is dead 14:00-18:00 and recovers 18:00-19:00.
        $this->addEvent(self::TOKEN_A, '2025-06-02 18:30:00 UTC');
        $this->clock->modify('+5 hours'); // resumes at 19:05
        $evaluator->evaluate(TaskTrigger::schedule());

        self::assertSame([], $this->webhookDeliveries, 'An incident that opened and closed during catch-up sends nothing.');
        self::assertSame('ok', $evaluator->statuses()['rules']['rule-a']['state']);
    }

    public function testCatchUpThatRecoversAndBreaksAgainClosesTheOldIncidentBeforeFiring(): void
    {
        $this->writeConfig($this->oneRuleConfig());
        $evaluator = $this->evaluator();
        $evaluator->evaluate(TaskTrigger::schedule()); // 14:05: no events, fires
        self::assertSame(['firing'], $this->types());
        $oldIncident = $this->webhookDeliveries[0]['payload']['incident'];

        // Scheduler stops. Traffic returns 15:00-16:00 only, then stops again.
        $this->addEvent(self::TOKEN_A, '2025-06-02 15:30:00 UTC');
        $this->clock->modify('+3 hours'); // resumes at 17:05
        $evaluator->evaluate(TaskTrigger::schedule());

        self::assertSame(['firing', 'recovered', 'firing'], $this->types());
        self::assertSame($oldIncident, $this->webhookDeliveries[1]['payload']['incident']);
        self::assertSame('2025-06-02T16:00:00Z', $this->webhookDeliveries[1]['payload']['window_end']);
        self::assertNotSame($oldIncident, $this->webhookDeliveries[2]['payload']['incident']);
        self::assertSame('2025-06-02T17:00:00Z', $this->webhookDeliveries[2]['payload']['window_end']);
    }

    public function testUndeliveredAlertIsRetriedHourlyForTwentyFourHoursThenGivenUp(): void
    {
        $this->writeConfig($this->oneRuleConfig());
        $this->webhookStatus = 503;
        $evaluator = $this->evaluator();
        for ($hour = 0; $hour < 26; ++$hour) {
            $evaluator->evaluate(TaskTrigger::schedule());
            $this->clock->modify('+1 hour');
        }

        self::assertSame(AlertEvaluator::RETRY_HOURS * WebhookSender::MAX_ATTEMPTS, $this->webhookAttempts);
        $failures = array_filter($evaluator->recentAlerts(30)['entries'], static fn (array $e): bool => $e['outcome'] === 'failed');
        self::assertStringContainsString('Gave up', (string) reset($failures)['reason']);
        self::assertStringContainsString('Will retry', (string) end($failures)['reason']);
    }

    public function testRecoveryIsNotSentToADestinationThatNeverReceivedTheFiring(): void
    {
        $this->writeConfig($this->oneRuleConfig());
        $this->webhookStatus = 503;
        $evaluator = $this->evaluator();
        $evaluator->evaluate(TaskTrigger::schedule()); // fires, undeliverable

        $this->webhookStatus = 202;
        $this->addEvent(self::TOKEN_A, '2025-06-02 14:30:00 UTC');
        $this->clock->modify('+1 hour');
        $result = $evaluator->evaluate(TaskTrigger::schedule()); // recovered before the firing got through

        self::assertSame([], $this->webhookDeliveries);
        self::assertSame(1, $result['suppressed']);
        $latest = $evaluator->recentAlerts()['entries'][0];
        self::assertSame(['recovered', 'suppressed'], [$latest['type'], $latest['outcome']]);
        self::assertStringContainsString('never reached this destination', $latest['reason']);
    }

    public function testTurningOffAFiringRuleSendsARecoveryAtTheNextCheck(): void
    {
        $this->writeConfig($this->oneRuleConfig());
        $evaluator = $this->evaluator();
        $evaluator->evaluate(TaskTrigger::schedule());

        $config = $this->oneRuleConfig();
        $config['alerts']['rules'][0]['enabled'] = false;
        $this->writeConfig($config);
        $evaluator = $this->evaluator(); // the config loader keeps what it read
        $evaluator->evaluate(TaskTrigger::schedule(), true); // same hour: the recovery would share window_end
        self::assertSame(['firing'], $this->types());

        $this->clock->modify('+1 hour');
        $evaluator->evaluate(TaskTrigger::schedule());
        self::assertSame(['firing', 'recovered'], $this->types());
        self::assertSame($this->webhookDeliveries[0]['payload']['incident'], $this->webhookDeliveries[1]['payload']['incident']);
        self::assertSame('2025-06-02T15:00:00Z', $this->webhookDeliveries[1]['payload']['window_end']);
        self::assertSame('off', $evaluator->statuses()['rules']['rule-a']['state']);
        self::assertFalse($evaluator->hasOpenIncidents());
    }

    public function testDeletingAFiringRuleResetsItsStateSoARecreatedRuleFiresAgain(): void
    {
        $this->writeConfig($this->oneRuleConfig());
        $evaluator = $this->evaluator();
        $evaluator->evaluate(TaskTrigger::schedule());

        $config = $this->oneRuleConfig();
        $config['alerts']['rules'] = [['id' => 'other', 'covers' => 'website:'.self::TOKEN_B, 'condition' => 'no_events', 'window' => 'hour', 'destinations' => ['hub'], 'enabled' => false]];
        $this->writeConfig($config);
        $evaluator = $this->evaluator();
        $this->clock->modify('+1 hour');
        $evaluator->evaluate(TaskTrigger::schedule());
        self::assertSame(['firing'], $this->types(), 'A deleted rule has no destinations to notify.');
        self::assertStringContainsString('deleted while firing', $evaluator->recentAlerts()['entries'][0]['reason']);

        $this->writeConfig($this->oneRuleConfig());
        $evaluator = $this->evaluator();
        $this->clock->modify('+1 hour');
        $evaluator->evaluate(TaskTrigger::schedule());
        self::assertSame(['firing', 'firing'], $this->types(), 'The recreated rule starts fresh instead of resuming the old incident.');
    }

    public function testRemovingAWebsiteFromARuleClosesItsIncident(): void
    {
        $this->writeConfig($this->oneRuleConfig());
        $evaluator = $this->evaluator();
        $evaluator->evaluate(TaskTrigger::schedule());

        $config = $this->oneRuleConfig();
        $config['alerts']['rules'][0]['covers'] = 'website:'.self::TOKEN_B;
        $this->writeConfig($config);
        $evaluator = $this->evaluator();
        $this->addEvent(self::TOKEN_B, '2025-06-02 14:30:00 UTC');
        $this->clock->modify('+1 hour');
        $evaluator->evaluate(TaskTrigger::schedule());

        self::assertSame(['firing', 'recovered'], $this->types());
        self::assertSame(self::TOKEN_A, $this->webhookDeliveries[1]['payload']['website']);
    }

    public function testRuleWhoseWebsiteWasRemovedIsShownAsNotChecked(): void
    {
        $this->writeConfig($this->twoRuleConfig());
        file_put_contents($this->projectDir.'/config/websites.yaml', Yaml::dump([
            'websites' => [['name' => 'Geektown EU', 'token' => self::TOKEN_B, 'domain' => 'eu.domain.com']],
        ], 6, 2));

        $status = $this->evaluator()->statuses()['rules'];
        self::assertSame('problem', $status['rule-a']['state']);
        self::assertStringContainsString('is not registered', $status['rule-a']['label']);
        self::assertSame('waiting', $status['rule-b']['state']);
    }

    /** @return array<string, mixed> */
    private function oneRuleConfig(): array
    {
        $config = $this->twoRuleConfig();
        $config['alerts']['rules'] = [$config['alerts']['rules'][0]];

        return $config;
    }

    /** @return list<string> */
    private function types(): array
    {
        return array_map(static fn (array $d): string => (string) $d['payload']['type'], $this->webhookDeliveries);
    }

    private function assertNoSensitiveDataStoredInDatabase(): void
    {
        foreach (['processing_tasks', 'audit_trail'] as $table) {
            foreach ($this->connection->fetchAllAssociative('SELECT * FROM '.$table) as $row) {
                $serialized = implode(' ', array_map('strval', array_filter($row, static fn (mixed $v): bool => $v !== null)));
                self::assertStringNotContainsString('ops@example.com', $serialized);
                self::assertStringNotContainsString('first@example.com', $serialized);
                self::assertStringNotContainsString('second@example.com', $serialized);
                self::assertStringNotContainsString('path-secret', $serialized);
                self::assertStringNotContainsString('webhook-secret-key-123', $serialized);
                self::assertStringNotContainsString('postmark-token-123', $serialized);
                self::assertStringNotContainsString('https://hooks.geektown.example', $serialized);
            }
        }
    }

    private function addEvent(string $token, string $time, string $mode = 'enhanced', string $name = 'page_view'): void
    {
        $this->storedEvents[$token][] = [
            'created_at' => new \DateTimeImmutable($time, new \DateTimeZone('UTC')),
            'privacy_mode' => $mode,
            'event_name' => $name,
        ];
    }

    /** @param array<string, mixed> $extra */
    private function writeConfig(array $extra): void
    {
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump(array_replace([
            'brand_name' => 'Example Analytics',
            'app_host' => 'https://analytics.example.com',
            'email_enabled' => true,
            'email_provider' => 'postmark',
            'email_from_address' => 'no-reply@example.com',
            'feature_flags' => [
                'alerts' => ['enabled' => true, 'hide_from_navigation' => false],
            ],
        ], $extra), 8, 2));
    }

    private function evaluator(): AlertEvaluator
    {
        $config = new AggregateConfigLoader($this->projectDir, 'test');
        $websites = new WebsiteConfigManager($this->projectDir);
        $scopes = new WebsiteScopeCatalog($this->projectDir, 'test');
        $validator = new AlertRuleValidator($websites, $scopes);
        $settings = new AlertSettings($config, $validator, $websites, $scopes);
        $auditSettings = new AuditTrailSettings($config);
        $audit = new AuditTrail($this->connection, $auditSettings);
        $tasks = new ProcessingTasks($this->connection, $audit);

        $eventsRepo = $this->createStub(EventRepository::class);
        $eventsRepo->method('countEventsUpTo')->willReturnCallback(
            function (string $token, \DateTimeImmutable $from, \DateTimeImmutable $to, int $cap, string $privacyMode = 'all', ?string $eventName = null): int {
                $count = 0;
                foreach ($this->storedEvents[$token] ?? [] as $ev) {
                    if ($ev['created_at'] < $from || $ev['created_at'] >= $to) {
                        continue;
                    }
                    if (($privacyMode === 'anonymous' || $privacyMode === 'enhanced') && $ev['privacy_mode'] !== $privacyMode) {
                        continue;
                    }
                    if ($eventName !== null && $eventName !== '' && $ev['event_name'] !== $eventName) {
                        continue;
                    }
                    ++$count;
                    if ($count >= $cap) {
                        return $cap;
                    }
                }

                return $count;
            },
        );

        $webhookHttp = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $decoded = json_decode((string) $options['body'], true, 512, JSON_THROW_ON_ERROR);
            ++$this->webhookAttempts;
            if ($this->webhookStatus >= 300) {
                return new MockResponse('unavailable', ['http_code' => $this->webhookStatus]);
            }
            // Only deliveries the receiver accepted are recorded.
            $this->webhookDeliveries[] = [
                'url' => $url,
                'payload' => $decoded[0],
                'headers' => $options['normalized_headers'],
            ];

            return new MockResponse('{"received":true}', ['http_code' => 202]);
        });
        $webhookSender = new WebhookSender(
            $webhookHttp,
            new AlertSecrets($this->projectDir),
            $validator,
            new NullLogger(),
            $this->clock,
            publicAddressesOnly: false,
        );

        $emailHttp = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $body = json_decode((string) $options['body'], true, 512, JSON_THROW_ON_ERROR);
            $this->emailDeliveries[] = [
                'to' => (string) $body['To'],
                'subject' => (string) $body['Subject'],
                'text' => (string) $body['TextBody'],
            ];

            return new MockResponse((string) json_encode(['MessageID' => 'pm-msg-1']), ['http_code' => 200]);
        });
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3).'/templates'), ['strict_variables' => true, 'autoescape' => 'name']);
        $mailer = new TransactionalMailer(
            new EmailSettings($config, EmailProviders::builtIn()),
            new EmailSecrets($this->projectDir),
            $tasks,
            $twig,
            new AppBranding($config, $this->projectDir),
            $config,
            new NullLogger(),
            EmailTestKit::transports($emailHttp),
            $this->clock,
        );

        return new AlertEvaluator(
            $settings,
            $eventsRepo,
            $tasks,
            $audit,
            $this->connection,
            $webhookSender,
            $mailer,
            $config,
            new FeatureFlags($config),
            new NullLogger(),
            $this->clock,
        );
    }
}
