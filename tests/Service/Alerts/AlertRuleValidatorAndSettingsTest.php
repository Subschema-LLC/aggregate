<?php

declare(strict_types=1);

namespace App\Tests\Service\Alerts;

use App\Service\AggregateConfigLoader;
use App\Service\Alerts\AlertRuleValidator;
use App\Service\Alerts\AlertSecrets;
use App\Service\Alerts\AlertSettings;
use App\Service\AnalyticsDataLifecyclePolicy;
use App\Service\Operations\ConfigurationChanges;
use App\Service\WebsiteConfigManager;
use App\Service\WebsiteScopeCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

final class AlertRuleValidatorAndSettingsTest extends TestCase
{
    private const TOKEN_A = '019c7e21-89ab-7cde-8f01-23456789abcd';
    private const TOKEN_B = '019c7e21-89ab-7cde-8f01-23456789abce';

    private string $projectDir;
    private array $environment;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        foreach (['ALERTS_WEBHOOK_SECRET', 'ALERTS_WEBHOOK_SECRET_GEEKTOWN_HUB', 'AGGREGATE_SECRETS_KEY'] as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
        }
        $this->projectDir = sys_get_temp_dir().'/aggregate-alerts-settings-'.bin2hex(random_bytes(8));
        mkdir($this->projectDir.'/config', 0700, true);
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
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump([
            'app_host' => 'https://analytics.example.com',
            'retention_enabled' => true,
            'anonymous_retention_days' => 365,
            'enhanced_retention_days' => 365,
            'processing_tasks_retention_days' => 90,
        ], 6, 2));
    }

    protected function tearDown(): void
    {
        [$_ENV, $_SERVER] = $this->environment;
        ConfigurationChanges::finish();
        (new Filesystem())->remove($this->projectDir);
    }

    public function testDestinationsAndRulesRoundTripThroughYamlAndResolveWebsitesAndGroups(): void
    {
        $settings = $this->settings();

        $webhook = $settings->saveDestination([
            'id' => 'geektown-hub',
            'label' => 'Geektown Hub',
            'type' => 'webhook',
            'url' => 'https://hooks.geektown.example/alerts/ingest?token=secret-query',
        ]);
        self::assertSame('hooks.geektown.example', $webhook['host']);

        $email = $settings->saveDestination([
            'id' => 'ops-email',
            'label' => 'Ops email',
            'type' => 'email',
            'recipients' => "ops@example.com\noncall@example.com, OPS@example.com",
        ]);
        self::assertSame(['ops@example.com', 'oncall@example.com'], $email['recipients']);

        $rule = $settings->saveRule([
            'id' => 'geektown-weekly',
            'enabled' => true,
            'covers' => 'group:geektown-network',
            'source' => 'events',
            'privacy_mode' => 'enhanced',
            'event_name' => 'page_view',
            'condition' => 'fewer_than',
            'threshold' => 5,
            'window' => 'week',
            'destinations' => ['geektown-hub', 'ops-email'],
        ]);

        self::assertSame('fewer_than_5_per_week', AlertRuleValidator::formatAlertCondition($rule));
        self::assertSame('Fewer than 5 enhanced "page_view" events in 1 week (168 h)', AlertSettings::describeCondition($rule));
        self::assertSame('Group: Geektown Network (2 websites)', $settings->describeCovers($rule));
        self::assertCount(2, $settings->coveredWebsites($rule));

        // Hand-written rule in YAML loads and can be edited through AlertSettings.
        $raw = Yaml::parseFile($this->projectDir.'/config/aggregate.yaml');
        $raw['alerts']['rules'][] = [
            'id' => 'failures-daily',
            'covers' => 'website:'.self::TOKEN_A,
            'source' => 'tracking_failures',
            'condition' => 'more_than',
            'threshold' => 10,
            'window' => 'day',
            'destinations' => ['ops-email'],
        ];
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump($raw, 6, 2));

        $reloaded = $this->settings();
        $failureRule = $reloaded->rule('failures-daily');
        self::assertNotNull($failureRule);
        self::assertSame('more_than_10_tracking_failures_per_day', AlertRuleValidator::formatAlertCondition($failureRule));

        // Cannot delete a destination while a rule still uses it.
        try {
            $reloaded->deleteDestination('ops-email');
            self::fail('Expected destination in use to be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('used by rule', $e->getMessage());
        }

        $reloaded->deleteRule('failures-daily');
        $reloaded->deleteRule('geektown-weekly');
        $reloaded->deleteDestination('ops-email');
        self::assertSame(['geektown-hub'], array_keys($reloaded->destinations()));
    }

    #[DataProvider('invalidWebhookUrls')]
    public function testValidatorRejectsNonHttpsAndPrivateOrLocalWebhookUrls(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->validator()->validateWebhookUrl($url);
    }

    public static function invalidWebhookUrls(): iterable
    {
        yield 'plain http' => ['http://hooks.example.com/alerts'];
        yield 'localhost' => ['https://localhost/alerts'];
        yield 'subdomain of localhost' => ['https://api.localhost/alerts'];
        yield 'dot local' => ['https://receiver.local/alerts'];
        yield 'dot internal' => ['https://receiver.internal/alerts'];
        yield 'ipv4 loopback' => ['https://127.0.0.1/alerts'];
        yield 'ipv4 private 10/8' => ['https://10.0.0.1/alerts'];
        yield 'ipv4 private 172.16/12' => ['https://172.16.5.4/alerts'];
        yield 'ipv4 private 192.168/16' => ['https://192.168.1.10/alerts'];
        yield 'ipv4 link-local metadata' => ['https://169.254.169.254/latest/meta-data'];
        yield 'ipv4 carrier-grade nat' => ['https://100.64.0.1/alerts'];
        yield 'ipv6 loopback' => ['https://[::1]/alerts'];
        yield 'ipv6 unique local' => ['https://[fd00::1]/alerts'];
        yield 'ipv6 link-local' => ['https://[fe80::1]/alerts'];
        yield 'ipv4-mapped ipv6 loopback' => ['https://[::ffff:127.0.0.1]/alerts'];
        yield 'ipv4-mapped ipv6 private' => ['https://[::ffff:10.0.0.1]/alerts'];
        yield 'embedded credentials' => ['https://user:pass@hooks.example.com/alerts'];
        yield 'url fragment' => ['https://hooks.example.com/alerts#frag'];
    }

    #[DataProvider('invalidRules')]
    public function testValidatorRejectsInvalidRulesWithClearMessages(array $rule, string $expectedMessageFragment): void
    {
        try {
            $this->validator()->validateRule($rule, ['geektown-hub']);
            self::fail('Expected invalid rule to be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString($expectedMessageFragment, $e->getMessage());
        }
    }

    public static function invalidRules(): iterable
    {
        $base = [
            'id' => 'valid-rule',
            'covers' => 'website:'.self::TOKEN_A,
            'source' => 'events',
            'privacy_mode' => 'all',
            'event_name' => '',
            'condition' => 'fewer_than',
            'threshold' => 5,
            'window' => 'day',
            'destinations' => ['geektown-hub'],
        ];

        yield 'unknown website' => [
            array_replace($base, ['covers' => 'website:unknown-site-token']),
            'not registered in config/websites.yaml',
        ];
        yield 'unknown website group' => [
            array_replace($base, ['covers' => 'group:missing-group']),
            'Website group "missing-group" does not exist',
        ];
        yield 'fewer_than threshold zero' => [
            array_replace($base, ['condition' => 'fewer_than', 'threshold' => 0]),
            'Threshold (N) must be between 1 and',
        ];
        yield 'no_events with non-zero threshold' => [
            array_replace($base, ['condition' => 'no_events', 'threshold' => 3]),
            'Threshold must be 0 or omitted when condition is no_events',
        ];
        yield 'tracking_failures with privacy filter' => [
            array_replace($base, ['source' => 'tracking_failures', 'privacy_mode' => 'enhanced', 'condition' => 'more_than']),
            'apply only to the events source',
        ];
        yield 'tracking_failures with fewer_than condition' => [
            array_replace($base, ['source' => 'tracking_failures', 'condition' => 'fewer_than']),
            'Condition for the tracking_failures source must be more_than',
        ];
        yield 'events with more_than condition' => [
            array_replace($base, ['source' => 'events', 'condition' => 'more_than']),
            'Condition for the events source must be no_events or fewer_than',
        ];
        yield 'unsafe event name' => [
            array_replace($base, ['event_name' => 'bad event name!']),
            'safe event-name format',
        ];
        yield 'unknown destination' => [
            array_replace($base, ['destinations' => ['unknown-dest']]),
            'Unknown alert destination "unknown-dest"',
        ];
        yield 'unsupported window' => [
            array_replace($base, ['window' => 'month']),
            'Window must be hour',
        ];
    }

    public function testValidatorRejectsWindowLongerThanLiveRetention(): void
    {
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump([
            'analytics_retention_enabled' => true,
            'analytics_anonymous_retention_days' => 3,
            'analytics_enhanced_retention_days' => 30,
        ], 6, 2));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('longer than the live event retention period (3 days)');

        $this->validator()->validateRule([
            'id' => 'weekly-rule',
            'covers' => 'all',
            'source' => 'events',
            'privacy_mode' => 'all',
            'condition' => 'no_events',
            'window' => 'week',
            'destinations' => ['geektown-hub'],
        ], ['geektown-hub']);
    }

    public function testSecretsAreStoredEncryptedAndNeverLeakIntoConfigurationChanges(): void
    {
        $_ENV['AGGREGATE_SECRETS_KEY'] = $_SERVER['AGGREGATE_SECRETS_KEY'] = 'test-aggregate-secrets-key-for-encryption-12345';
        ConfigurationChanges::start();
        $secrets = new AlertSecrets($this->projectDir);

        $generated = $secrets->generate();
        self::assertSame(64, strlen($generated));
        self::assertSame($generated, $secrets->get());
        self::assertSame(AlertSecrets::SOURCE_FILE, $secrets->source());

        $rawFile = (string) file_get_contents($secrets->path());
        self::assertStringNotContainsString($generated, $rawFile, 'Secret file is encrypted at rest when AGGREGATE_SECRETS_KEY is set.');

        $changes = ConfigurationChanges::finish();
        self::assertSame([AlertSecrets::FILE => [AlertSecrets::DEFAULT_KEY]], $changes);
        self::assertStringNotContainsString($generated, (string) json_encode($changes));

        $_ENV['ALERTS_WEBHOOK_SECRET'] = $_SERVER['ALERTS_WEBHOOK_SECRET'] = 'env-override-secret';
        self::assertSame('env-override-secret', $secrets->get());
        self::assertSame(AlertSecrets::SOURCE_ENVIRONMENT, $secrets->source());
        self::assertTrue($secrets->isEnvironmentControlled());
    }

    public function testADestinationsOwnSecretWinsAndDescribeSaysWhichSecretSigns(): void
    {
        $secrets = new AlertSecrets($this->projectDir);
        self::assertSame('No signing secret', $secrets->describe('geektown-hub'));

        $secrets->set('shared-secret');
        self::assertSame('Shared secret ('.AlertSecrets::FILE.')', $secrets->describe('geektown-hub'));
        self::assertSame('shared-secret', $secrets->get('geektown-hub'));

        $own = $secrets->generate('geektown-hub');
        $secrets->set('rotated-shared-secret');
        self::assertSame($own, $secrets->get('geektown-hub'), 'Rotating the shared secret does not change a destination with its own.');
        self::assertSame('Own secret ('.AlertSecrets::FILE.')', $secrets->describe('geektown-hub'));
        self::assertTrue($secrets->hasOwnInFile('geektown-hub'));

        $_ENV['ALERTS_WEBHOOK_SECRET_GEEKTOWN_HUB'] = $_SERVER['ALERTS_WEBHOOK_SECRET_GEEKTOWN_HUB'] = 'env-own';
        self::assertSame('Own secret (ALERTS_WEBHOOK_SECRET_GEEKTOWN_HUB)', $secrets->describe('geektown-hub'));
        self::assertTrue($secrets->hasOwnInEnvironment('geektown-hub'));
        unset($_ENV['ALERTS_WEBHOOK_SECRET_GEEKTOWN_HUB'], $_SERVER['ALERTS_WEBHOOK_SECRET_GEEKTOWN_HUB']);

        $secrets->remove('geektown-hub');
        self::assertSame('Shared secret ('.AlertSecrets::FILE.')', $secrets->describe('geektown-hub'));
    }

    public function testDestinationIdsThatWouldShareASecretAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('would share a signing secret');

        $this->validator()->validateAll(['destinations' => [
            ['id' => 'geektown-hub', 'label' => 'Hub', 'type' => 'webhook', 'url' => 'https://hooks.geektown.example/a'],
            ['id' => 'geektown_hub', 'label' => 'Hub 2', 'type' => 'webhook', 'url' => 'https://hooks.geektown.example/b'],
        ]]);
    }

    public function testLoadingKeepsARuleWhoseWebsiteWasRemovedAndNamesTheProblem(): void
    {
        $settings = $this->settings();
        $settings->saveDestination(['id' => 'geektown-hub', 'label' => 'Hub', 'type' => 'webhook', 'url' => 'https://hooks.geektown.example/a']);
        $settings->saveRule(['id' => 'eu-hourly', 'covers' => 'website:'.self::TOKEN_B, 'condition' => 'no_events', 'window' => 'day', 'destinations' => ['geektown-hub']]);
        $settings->saveRule(['id' => 'usa-hourly', 'covers' => 'website:'.self::TOKEN_A, 'condition' => 'no_events', 'window' => 'day', 'destinations' => ['geektown-hub']]);

        file_put_contents($this->projectDir.'/config/websites.yaml', Yaml::dump([
            'websites' => [['name' => 'Geektown USA', 'token' => self::TOKEN_A, 'domain' => 'some.domain.com']],
        ], 6, 2));
        $settings = $this->settings();

        $rules = $settings->rules();
        self::assertCount(2, $rules);
        self::assertStringContainsString('is not registered', (string) $settings->referenceProblem($rules['eu-hourly']));
        self::assertNull($settings->referenceProblem($rules['usa-hourly']));

        // Other rules can still be saved, and the stale one deleted.
        $settings->saveRule(['id' => 'usa-daily', 'covers' => 'website:'.self::TOKEN_A, 'condition' => 'no_events', 'window' => 'day', 'destinations' => ['geektown-hub']]);
        $settings->deleteRule('eu-hourly');
        self::assertSame(['usa-hourly', 'usa-daily'], array_keys($this->settings()->rules()));

        // Saving the stale rule itself still checks its website.
        $this->expectExceptionMessage('is not registered');
        $this->settings()->saveRule(['id' => 'eu-again', 'covers' => 'website:'.self::TOKEN_B, 'condition' => 'no_events', 'window' => 'day', 'destinations' => ['geektown-hub']]);
    }

    public function testThresholdIsCappedSoACountNeverReadsMoreThanOneHundredThousandRows(): void
    {
        $this->expectExceptionMessage('between 1 and 100000');
        $this->validator()->validateRule([
            'id' => 'too-big',
            'covers' => 'website:'.self::TOKEN_A,
            'condition' => 'fewer_than',
            'threshold' => 100001,
            'window' => 'day',
            'destinations' => ['geektown-hub'],
        ], ['geektown-hub']);
    }

    public function testInstallationUrlNormalizationLowercasesSchemeAndHostAndPreservesSubfolder(): void
    {
        self::assertSame(
            'https://analytics.example.com/sub/path',
            AlertSettings::normalizeInstallationUrl('HTTPS://Analytics.Example.COM/sub/path/'),
        );
        self::assertSame(
            'https://analytics.example.com:8443',
            AlertSettings::normalizeInstallationUrl('https://ANALYTICS.EXAMPLE.COM:8443/'),
        );
    }

    private function validator(): AlertRuleValidator
    {
        $config = new AggregateConfigLoader($this->projectDir, 'test');
        $websites = new WebsiteConfigManager($this->projectDir);
        $scopes = new WebsiteScopeCatalog($this->projectDir, 'test');
        $lifecycle = new AnalyticsDataLifecyclePolicy($config);

        return new AlertRuleValidator($websites, $scopes, $lifecycle);
    }

    private function settings(): AlertSettings
    {
        $config = new AggregateConfigLoader($this->projectDir, 'test');
        $websites = new WebsiteConfigManager($this->projectDir);
        $scopes = new WebsiteScopeCatalog($this->projectDir, 'test');
        $lifecycle = new AnalyticsDataLifecyclePolicy($config);
        $validator = new AlertRuleValidator($websites, $scopes, $lifecycle);

        return new AlertSettings($config, $validator, $websites, $scopes);
    }
}
