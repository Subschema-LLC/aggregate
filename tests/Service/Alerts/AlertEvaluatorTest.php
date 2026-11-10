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

final class AlertEvaluatorTest extends TestCase
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

    public function testFiresOnceSuppressesContinuingBreachAndRecoversWithMatchingIncidentIdEvenWhenAuditSwitchIsOff(): void
    {
        // Turn off the operations audit switch and verify alerts are still recorded (always: true).
        $this->writeConfig([
            'audit_trail_areas' => ['operations' => false],
            'alerts' => [
                'destinations' => [
                    [
                        'id' => 'geektown-hub',
                        'label' => 'Geektown Hub',
                        'type' => 'webhook',
                        'url' => 'https://hooks.geektown.example/alerts/ingest?secret=path-secret',
                    ],
                    [
                        'id' => 'ops-email',
                        'label' => 'Ops email',
                        'type' => 'email',
                        'recipients' => ['ops@example.com'],
                    ],
                ],
                'rules' => [
                    [
                        'id' => 'geektown-hourly',
                        'enabled' => true,
                        'covers' => 'website:'.self::TOKEN_A,
                        'source' => 'events',
                        'privacy_mode' => 'all',
                        'condition' => 'fewer_than',
                        'threshold' => 5,
                        'window' => 'hour',
                        'destinations' => ['geektown-hub', 'ops-email'],
                    ],
                ],
            ],
        ]);

        // Only 2 events in [13:00, 14:00), and 10 events in the open hour [14:00, 15:00) which must NOT count yet.
        $this->addEvent(self::TOKEN_A, '2025-06-02 13:10:00 UTC');
        $this->addEvent(self::TOKEN_A, '2025-06-02 13:20:00 UTC');
        for ($i = 0; $i < 10; ++$i) {
            $this->addEvent(self::TOKEN_A, '2025-06-02 14:02:00 UTC');
        }

        $evaluator = $this->evaluator();

        //Before first check, rule status is 'waiting'.
        self::assertSame('waiting', $evaluator->statuses()['rules']['geektown-hourly']['state']);

        // 1. First evaluation at 14:05 UTC checks [13:00, 14:00) -> breached -> fires once!
        $first = $evaluator->evaluate(TaskTrigger::schedule());
        self::assertSame('ran', $first['status']);
        self::assertSame(2, $first['sent'], 'Sent 1 webhook + 1 email.');
        self::assertCount(1, $this->webhookDeliveries);
        self::assertCount(1, $this->emailDeliveries);

        $firingPayload = $this->webhookDeliveries[0]['payload'];
        self::assertSame('firing', $firingPayload['type']);
        self::assertSame('2025-06-02T14:00:00Z', $firingPayload['window_end']);
        self::assertSame('fewer_than_5_per_hour', $firingPayload['alert_condition']);
        self::assertSame('https://analytics.example.com', $firingPayload['installation']);

        $statusAfterFire = $evaluator->statuses();
        self::assertSame(1, $statusAfterFire['firing_count']);
        self::assertSame('firing', $statusAfterFire['rules']['geektown-hourly']['state']);
        self::assertSame('Firing since 2025-06-02 14:00 UTC', $statusAfterFire['rules']['geektown-hourly']['label']);

        // Re-running in the same hour is not_due.
        self::assertSame('not_due', $evaluator->evaluate(TaskTrigger::schedule())['status']);

        // 2. Purge all audit_trail rows AND advance to 15:05 UTC with still 0 events in [14:00, 15:00):
        // firing state in processing_tasks.fingerprint prevents duplicate firing alerts!
        $this->storedEvents[self::TOKEN_A] = [];
        $this->connection->executeStatement('DELETE FROM audit_trail');
        $this->clock->modify('+1 hour');

        $second = $evaluator->evaluate(TaskTrigger::schedule());
        self::assertSame('ran', $second['status']);
        self::assertSame(0, $second['sent'], 'Continuing breach sends 0 duplicate alerts even after audit_trail purge.');
        self::assertSame(2, $second['suppressed']);
        self::assertCount(1, $this->webhookDeliveries);
        self::assertCount(1, $this->emailDeliveries);

        // 3. Advance to 16:05 UTC with 5 events in [15:00, 16:00) -> recovers once with the same incident ID!
        for ($i = 0; $i < 5; ++$i) {
            $this->addEvent(self::TOKEN_A, '2025-06-02 15:15:00 UTC');
        }
        $this->clock->modify('+1 hour');

        $third = $evaluator->evaluate(TaskTrigger::schedule());
        self::assertSame('ran', $third['status']);
        self::assertSame(2, $third['sent']);
        self::assertCount(2, $this->webhookDeliveries);
        self::assertCount(2, $this->emailDeliveries);

        $recoveredPayload = $this->webhookDeliveries[1]['payload'];
        self::assertSame('recovered', $recoveredPayload['type']);
        self::assertSame('2025-06-02T16:00:00Z', $recoveredPayload['window_end']);
        self::assertSame($firingPayload['incident'], $recoveredPayload['incident'], 'Recovered alert shares the firing incident ID.');
        self::assertNotSame($firingPayload['id'], $recoveredPayload['id'], 'Recovered alert has its own idempotency ID.');

        self::assertSame('ok', $evaluator->statuses()['rules']['geektown-hourly']['state']);

        // Verify recent alerts and privacy/redaction invariants on processing_tasks and audit_trail.
        $recent = $evaluator->recentAlerts();
        self::assertNotEmpty($recent['entries']);
        $this->assertNoSensitiveDataStoredInDatabase();
    }

    public function testConsentBannerPairFiresEnhancedRuleWhileAllModesRuleStaysOk(): void
    {
        $this->writeConfig([
            'alerts' => [
                'destinations' => [
                    [
                        'id' => 'geektown-hub',
                        'label' => 'Geektown Hub',
                        'type' => 'webhook',
                        'url' => 'https://hooks.geektown.example/alerts/ingest',
                    ],
                ],
                'rules' => [
                    [
                        'id' => 'consent-banner-watch',
                        'enabled' => true,
                        'covers' => 'website:'.self::TOKEN_A,
                        'source' => 'events',
                        'privacy_mode' => 'enhanced',
                        'condition' => 'no_events',
                        'window' => 'day',
                        'destinations' => ['geektown-hub'],
                    ],
                    [
                        'id' => 'whole-site-watch',
                        'enabled' => true,
                        'covers' => 'website:'.self::TOKEN_A,
                        'source' => 'events',
                        'privacy_mode' => 'all',
                        'condition' => 'no_events',
                        'window' => 'day',
                        'destinations' => ['geektown-hub'],
                    ],
                ],
            ],
        ]);

        // Site has anonymous events in the last 24h, but 0 enhanced events (broken consent banner).
        $this->addEvent(self::TOKEN_A, '2025-06-02 08:00:00 UTC', 'anonymous', 'page_view');

        $evaluator = $this->evaluator();
        $evaluator->evaluate(TaskTrigger::schedule());

        self::assertCount(1, $this->webhookDeliveries);
        self::assertSame('consent-banner-watch', $this->webhookDeliveries[0]['payload']['rule']);

        $statuses = $evaluator->statuses()['rules'];
        self::assertSame('firing', $statuses['consent-banner-watch']['state']);
        self::assertSame('ok', $statuses['whole-site-watch']['state']);
    }

    public function testTrackingFailuresSourceAndGroupRuleAndCatchUpCapAtTwentyFourHours(): void
    {
        $this->writeConfig([
            'alerts' => [
                'destinations' => [
                    [
                        'id' => 'geektown-hub',
                        'label' => 'Geektown Hub',
                        'type' => 'webhook',
                        'url' => 'https://hooks.geektown.example/alerts/ingest',
                    ],
                ],
                'rules' => [
                    [
                        'id' => 'group-failures',
                        'enabled' => true,
                        'covers' => 'group:geektown-network',
                        'source' => 'tracking_failures',
                        'condition' => 'more_than',
                        'threshold' => 1,
                        'window' => 'day',
                        'destinations' => ['geektown-hub'],
                    ],
                ],
            ],
        ]);

        $tasks = new ProcessingTasks($this->connection, new AuditTrail($this->connection));
        $evaluator = $this->evaluator();

        // First run at 2025-06-02 14:05 UTC with 0 failures -> both websites become OK.
        $evaluator->evaluate(TaskTrigger::schedule());
        self::assertCount(0, $this->webhookDeliveries);
        self::assertSame('ok', $evaluator->statuses()['rules']['group-failures']['state']);

        // Simulate a 30-hour scheduler outage and 2 failures on TOKEN_A in the last 2 hours.
        $this->clock->modify('+30 hours'); // 2025-06-03 20:05:00 UTC
        $f1 = $tasks->start('tracking_event_store', self::TOKEN_A, TaskTrigger::schedule(), new \DateTimeImmutable('2025-06-03 18:10:00 UTC'));
        self::assertNotNull($f1);
        $tasks->fail($f1, new \DateTimeImmutable('2025-06-03 18:10:00 UTC'), 'DB error 1');
        $f2 = $tasks->start('tracking_ingest', 'anonymous:'.self::TOKEN_A, TaskTrigger::schedule(), new \DateTimeImmutable('2025-06-03 19:20:00 UTC'));
        self::assertNotNull($f2);
        $tasks->fail($f2, new \DateTimeImmutable('2025-06-03 19:20:00 UTC'), 'Ingest error 2');

        $result = $evaluator->evaluate(TaskTrigger::schedule());
        self::assertSame(AlertEvaluator::MAX_CATCHUP_HOURS, $result['evaluated_hours'], 'Catch-up caps at 24 hours.');
        self::assertCount(1, $this->webhookDeliveries, 'Only TOKEN_A breached; sends 1 newest transition.');
        self::assertSame(self::TOKEN_A, $this->webhookDeliveries[0]['payload']['website']);
        self::assertSame('more_than_1_tracking_failures_per_day', $this->webhookDeliveries[0]['payload']['alert_condition']);
    }

    public function testSendTestSupportsAnyListedRecipientAndDoesNotChangeRuleState(): void
    {
        $this->writeConfig([
            'alerts' => [
                'destinations' => [
                    [
                        'id' => 'ops-email',
                        'label' => 'Ops email',
                        'type' => 'email',
                        'recipients' => ['first@example.com', 'second@example.com'],
                    ],
                ],
                'rules' => [
                    [
                        'id' => 'geektown-weekly',
                        'enabled' => true,
                        'covers' => 'website:'.self::TOKEN_A,
                        'source' => 'events',
                        'condition' => 'fewer_than',
                        'threshold' => 5,
                        'window' => 'week',
                        'destinations' => ['ops-email'],
                    ],
                ],
            ],
        ]);

        $evaluator = $this->evaluator();
        self::assertSame('waiting', $evaluator->statuses()['rules']['geektown-weekly']['state']);

        $testResult = $evaluator->sendTest('ops-email', TaskTrigger::dashboard('scott'), 'second@example.com');
        self::assertTrue($testResult['sent']);
        self::assertCount(1, $this->emailDeliveries);
        self::assertSame('second@example.com', $this->emailDeliveries[0]['to']);
        self::assertSame('waiting', $evaluator->statuses()['rules']['geektown-weekly']['state'], 'Test send never changes rule state.');

        $recent = $evaluator->recentAlerts()['entries'];
        self::assertCount(1, $recent);
        self::assertSame('test', $recent[0]['type']);
        self::assertSame('Ops email', $recent[0]['destination']);
        $this->assertNoSensitiveDataStoredInDatabase();
    }

    public function testAlertsJobReportsScheduleAndRunsDueViaScheduler(): void
    {
        $this->writeConfig([
            'alerts' => [
                'destinations' => [
                    [
                        'id' => 'geektown-hub',
                        'label' => 'Geektown Hub',
                        'type' => 'webhook',
                        'url' => 'https://hooks.geektown.example/alerts/ingest',
                    ],
                ],
                'rules' => [
                    [
                        'id' => 'geektown-weekly',
                        'enabled' => true,
                        'covers' => 'website:'.self::TOKEN_A,
                        'source' => 'events',
                        'condition' => 'no_events',
                        'window' => 'hour',
                        'destinations' => ['geektown-hub'],
                    ],
                ],
            ],
        ]);

        $config = new AggregateConfigLoader($this->projectDir, 'test');
        $websites = new WebsiteConfigManager($this->projectDir);
        $scopes = new WebsiteScopeCatalog($this->projectDir, 'test');
        $settings = new AlertSettings($config, new AlertRuleValidator($websites, $scopes), $websites, $scopes);
        $features = new FeatureFlags($config);
        $tasks = new ProcessingTasks($this->connection, new AuditTrail($this->connection));
        $job = new AlertsJob($this->evaluator(), $settings, $features, $tasks, $this->clock, new NullLogger());

        self::assertSame('alerts', $job->name());
        self::assertSame('Alerts', $job->label());
        self::assertTrue($job->status()['enabled']);

        $run = $job->runDue(TaskTrigger::schedule());
        self::assertSame('ran', $run['status']);
        self::assertSame('not_due', $job->runDue(TaskTrigger::schedule())['status']);
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
