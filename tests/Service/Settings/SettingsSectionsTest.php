<?php

declare(strict_types=1);

namespace App\Tests\Service\Settings;

use App\Service\AggregateConfigLoader;
use App\Service\Alerts\AlertSettings;
use App\Service\AnalyticsDataLifecyclePolicy;
use App\Service\AppBranding;
use App\Service\BrandingLogoManager;
use App\Service\BrandingSettingsService;
use App\Service\DocumentationLinks;
use App\Service\FeatureFlags;
use App\Service\ForensicDatabase\ForensicDatabaseSettings;
use App\Service\GeneralSettings;
use App\Service\InternalTrafficSettings;
use App\Service\Operations\AuditTrailSettings;
use App\Service\ParsedYamlCache;
use App\Service\Settings\Section\AuditTrailSection;
use App\Service\Settings\Section\BrandingSection;
use App\Service\Settings\Section\ClusterSection;
use App\Service\Settings\Section\CollectionSection;
use App\Service\Settings\Section\DataLifecycleSection;
use App\Service\Settings\Section\FeatureFlagsSection;
use App\Service\Settings\Section\ForensicDatabaseSection;
use App\Service\Settings\Section\GeneralSection;
use App\Service\Settings\Section\OrganizationTrafficSection;
use App\Service\Settings\Section\PageSpeedSection;
use App\Service\Settings\Section\ReportingApiSection;
use App\Service\Settings\SettingsSections;
use App\Service\TrackerBuilds;
use App\Service\TrackingFailureSettings;
use App\Service\WebsiteConfigManager;
use App\Service\WebsiteScopeCatalog;
use App\Service\WebsiteScopeEditor;
use App\Service\WebsiteScopeResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/**
 * The sections app:config reaches, on a real configuration: each takes the
 * page's own checks and writes what the page writes, and nothing when a
 * value is refused.
 */
final class SettingsSectionsTest extends TestCase
{
    private const VARIABLES = ['RATE_LIMIT_PER_MINUTE', 'APP_HOST', 'TRACKER_OMIT_UNUSED_FEATURES', 'COLLECTION_BASE_URL', 'DOCUMENTATION_URL', 'ANALYTICS_ARCHIVE_AFTER_DAYS', 'BOT_TRAFFIC'];

    private string $directory;
    private array $environment;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        foreach (self::VARIABLES as $name) {
            unset($_ENV[$name], $_SERVER[$name]);
        }
        $this->directory = sys_get_temp_dir().'/aggregate-settings-'.bin2hex(random_bytes(6));
        (new Filesystem())->mkdir($this->directory.'/config');
        $this->write(['app_host' => 'https://admin.example.com']);
        file_put_contents($this->directory.'/config/websites.yaml', Yaml::dump(['websites' => [
            ['name' => 'Store', 'domain' => 'store.example', 'token' => 'store-token'],
        ]]));
    }

    protected function tearDown(): void
    {
        [$_ENV, $_SERVER] = $this->environment;
        (new Filesystem())->remove($this->directory);
    }

    public function testGeneralSettingsSaveOnlyTheNamedSettingAndRefuseALimitOfZero(): void
    {
        $section = new GeneralSection($this->config(), new GeneralSettings($this->config(), new DocumentationLinks($this->config())));

        $section->apply(['rate_limit_per_minute' => 250]);
        self::assertSame(['app_host' => 'https://admin.example.com', 'rate_limit_per_minute' => 250], $this->saved());

        foreach ([0, -5, 'abc'] as $refused) {
            try {
                $section->apply(['rate_limit_per_minute' => $refused]);
                self::fail('A rate limit of '.var_export($refused, true).' is refused.');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('at least 1', $e->getMessage());
            }
        }
        self::assertSame(250, $this->saved()['rate_limit_per_minute']);

        $this->expectExceptionMessage('not in the general settings');
        $section->apply(['brand_name' => 'Acme']);
    }

    public function testDataLifecycleChecksEverySettingTogether(): void
    {
        $section = new DataLifecycleSection($this->config(), new AnalyticsDataLifecyclePolicy($this->config()));

        $section->apply([AnalyticsDataLifecyclePolicy::KEY_QUEUE_RETENTION_DAYS => 14]);
        self::assertSame(14, $this->saved()[AnalyticsDataLifecyclePolicy::KEY_QUEUE_RETENTION_DAYS]);
        self::assertArrayNotHasKey(AnalyticsDataLifecyclePolicy::KEY_ARCHIVE_AFTER_DAYS, $this->saved(), 'Only the named setting is written.');

        $this->expectException(\InvalidArgumentException::class);
        $section->apply([AnalyticsDataLifecyclePolicy::KEY_QUEUE_RETENTION_DAYS => 0]);
    }

    public function testCheckValidatesWithoutWriting(): void
    {
        $section = new DataLifecycleSection($this->config(), new AnalyticsDataLifecyclePolicy($this->config()));
        $before = $this->saved();

        self::assertSame(['The values are valid. Nothing was saved (--check).'], $section->apply([AnalyticsDataLifecyclePolicy::KEY_QUEUE_RETENTION_DAYS => 30], check: true));
        self::assertSame($before, $this->saved());
    }

    public function testClusterAlertDestinationsMustExist(): void
    {
        $alerts = $this->createMock(AlertSettings::class);
        $alerts->method('destinations')->willReturn(['ops' => ['id' => 'ops']]);
        $section = new ClusterSection($this->config(), $alerts);

        $section->apply(['cluster_alert_destinations' => ['ops'], 'cluster_config_max_age_minutes' => 10]);
        self::assertSame(['ops'], $this->saved()['cluster_alert_destinations']);
        $section->apply(['cluster_alert_destinations' => 'all']);
        self::assertNull($this->saved()['cluster_alert_destinations']);

        $this->expectExceptionMessage('No alert destination is called pager');
        $section->apply(['cluster_alert_destinations' => 'ops,pager']);
    }

    public function testFeatureFlagsAuditSwitchesApiAndMarkerUseTheirServices(): void
    {
        (new FeatureFlagsSection($this->config(), new FeatureFlags($this->config())))->apply(['alerts' => ['enabled' => true]]);
        self::assertTrue($this->saved()['feature_flags']['alerts']['enabled']);

        (new AuditTrailSection($this->config(), new AuditTrailSettings($this->config())))->apply(['users' => false]);
        self::assertSame(['users' => false], $this->saved()['audit_trail_areas']);

        (new ReportingApiSection($this->config()))->apply(['reporting_api_max_page_size' => 500]);
        self::assertSame(500, $this->saved()['reporting_api_max_page_size']);

        (new OrganizationTrafficSection($this->config(), new InternalTrafficSettings($this->config())))->apply(['internal_traffic_name' => 'teamVisit']);
        self::assertSame('teamVisit', $this->saved()['internal_traffic_name']);

        $this->expectException(\InvalidArgumentException::class);
        (new AuditTrailSection($this->config(), new AuditTrailSettings($this->config())))->apply(['users' => 'sometimes']);
    }

    public function testPageSpeedIsRefusedWhileItsEnvironmentVariableDecides(): void
    {
        $section = new PageSpeedSection($this->config(), new TrackerBuilds($this->config()));
        $section->apply(['tracker_omit_unused_features' => false]);
        self::assertFalse($this->saved()['tracker_omit_unused_features']);

        $_ENV['TRACKER_OMIT_UNUSED_FEATURES'] = $_SERVER['TRACKER_OMIT_UNUSED_FEATURES'] = 'true';
        $this->expectExceptionMessage('TRACKER_OMIT_UNUSED_FEATURES');
        $section->apply(['tracker_omit_unused_features' => false]);
    }

    public function testAddingForensicTablesNeedsTheConfirmationThePageAsksFor(): void
    {
        $this->write(['app_host' => 'https://admin.example.com', ForensicDatabaseSettings::KEY_TABLES => [], ForensicDatabaseSettings::KEY_VIEWS => []]);
        $section = new ForensicDatabaseSection($this->config(), new ForensicDatabaseSettings($this->config()));
        $tables = ForensicDatabaseSettings::defaults()[ForensicDatabaseSettings::KEY_TABLES];

        try {
            $section->apply([ForensicDatabaseSettings::KEY_TABLES => $tables]);
            self::fail('Adding a table needs --confirm.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('--confirm', $e->getMessage());
        }
        self::assertSame([], $this->saved()[ForensicDatabaseSettings::KEY_TABLES]);

        $section->apply([ForensicDatabaseSettings::KEY_TABLES => $tables], confirmed: true);
        self::assertSame($tables, $this->saved()[ForensicDatabaseSettings::KEY_TABLES]);
        $section->apply([ForensicDatabaseSettings::KEY_TABLES => []]);
        self::assertSame([], $this->saved()[ForensicDatabaseSettings::KEY_TABLES], 'Removing needs no confirmation.');
    }

    public function testBrandingGoesThroughThePageFormAndKeepsTheOperatorsLogoFile(): void
    {
        $section = new BrandingSection($this->config(), new BrandingSettingsService($this->config(), new BrandingLogoManager($this->directory, 'test'), new NullLogger()), new AppBranding($this->config(), $this->directory));

        $section->apply(['brand_primary_color' => '#336699']);
        self::assertSame(['app_host' => 'https://admin.example.com', 'brand_primary_color' => '#336699'], $this->saved(), 'Fields the command was not given are not written.');

        try {
            $section->apply(['brand_text_color' => '#eeeeee']);
            self::fail('Low contrast is refused.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('contrast', $e->getMessage());
        }

        $logo = $this->directory.'/logo.png';
        $image = imagecreatetruecolor(4, 4);
        imagepng($image, $logo);
        $section->apply(['brand_logo' => $logo]);
        self::assertFileExists($logo, 'The operator\'s file is copied, not moved.');
        self::assertMatchesRegularExpression('~^var/branding/test/logo-[a-f0-9]{32}\.png$~', $this->saved()['brand_logo_path']);
        self::assertFileExists($this->directory.'/'.$this->saved()['brand_logo_path']);

        $stored = $this->saved()['brand_logo_path'];
        $section->apply(['brand_logo' => '']);
        self::assertSame('', $this->saved()['brand_logo_path']);
        self::assertFileDoesNotExist($this->directory.'/'.$stored, 'Removing the logo deletes the uploaded file.');
    }

    public function testCollectionControlsGoThroughThePageFormForTheInstallationAndAGroup(): void
    {
        $section = new CollectionSection($this->config(), new TrackingFailureSettings($this->config()), $this->scopes());

        $section->apply(['anonymous_excluded_paths' => ['/admin', '/checkout']]);
        self::assertSame(['/admin', '/checkout'], $this->saved()['anonymous_excluded_paths']);
        try {
            $section->apply(['anonymous_excluded_paths' => ['no-slash']]);
            self::fail('The page\'s path rule applies.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('Invalid excluded path', $e->getMessage());
        }

        $id = WebsiteConfigManager::idForToken('store-token');
        $section->apply(['bot_traffic' => 'drop'], 'website:'.$id);
        $overlay = (new WebsiteScopeCatalog($this->directory, 'test'))->readOverlay('websites', $id)['values'];
        self::assertSame(['bot_traffic' => 'drop', 'bot_user_agents' => []], $overlay, 'Only the changed section is overridden.');
        self::assertSame('drop', $section->values('website:'.$id)['bot_traffic']);
        self::assertArrayNotHasKey('bot_traffic', $this->saved(), 'The installation keeps its own value.');

        $section->unset(['bot_traffic'], 'website:'.$id);
        self::assertSame([], (new WebsiteScopeCatalog($this->directory, 'test'))->readOverlay('websites', $id)['values']);

        $this->expectExceptionMessage('belongs to the whole installation');
        $section->apply(['tracking_retry_enabled' => false], 'website:'.$id);
    }

    public function testEverySectionRecordsTheSameAuditActionAsItsPage(): void
    {
        foreach (SettingsSections::AUDIT_ACTIONS as $name => [$area, $action]) {
            self::assertArrayHasKey($area, AuditTrailSettings::AREAS, $name.' records under a known area.');
            self::assertMatchesRegularExpression('/^[a-z_]+$/', $action);
        }
    }

    private function config(): AggregateConfigLoader
    {
        return new AggregateConfigLoader($this->directory, 'test', parsedYaml: new ParsedYamlCache($this->directory.'/var/cache'));
    }

    private function scopes(): WebsiteScopeEditor
    {
        $loader = $this->config();

        return new WebsiteScopeEditor(
            new WebsiteScopeCatalog($this->directory, 'test'),
            new WebsiteScopeResolver($loader, new WebsiteScopeCatalog($this->directory, 'test')),
            new WebsiteConfigManager($this->directory),
            $loader,
        );
    }

    private function write(array $settings): void
    {
        file_put_contents($this->directory.'/config/aggregate.yaml', Yaml::dump($settings, 4));
    }

    /** @return array<string, mixed> */
    private function saved(): array
    {
        return (array) Yaml::parseFile($this->directory.'/config/aggregate.yaml');
    }
}
