<?php

declare(strict_types=1);

namespace App\Tests\Service\BigQuery;

use App\Service\AggregateConfigLoader;
use App\Service\BigQuery\BigQuerySettings;
use App\Service\BigQuery\BigQueryViewCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class BigQuerySettingsTest extends TestCase
{
    private string $projectDir;
    private array $environment;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        foreach (array_keys(BigQuerySettings::defaults()) as $key) {
            unset($_ENV[strtoupper($key)], $_SERVER[strtoupper($key)]);
        }
        $this->projectDir = sys_get_temp_dir().'/aggregate-bigquery-settings-'.bin2hex(random_bytes(6));
        mkdir($this->projectDir.'/config', 0700, true);
    }

    protected function tearDown(): void
    {
        [$_ENV, $_SERVER] = $this->environment;
        foreach (glob($this->projectDir.'/config/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->projectDir.'/config');
        rmdir($this->projectDir);
    }

    public function testDefaultsAreOffAndSelectEveryApprovedViewOnly(): void
    {
        $settings = $this->settings([])->toArray();

        self::assertFalse($settings['bigquery_enabled']);
        self::assertSame('aggregate', $settings['bigquery_dataset']);
        self::assertSame('US', $settings['bigquery_location']);
        self::assertSame('service_account', $settings['bigquery_auth']);
        self::assertSame(array_keys(BigQueryViewCatalog::APPROVED), $settings['bigquery_views']);
        self::assertSame([], $settings['bigquery_private_views']);
        self::assertSame(60, $settings['bigquery_interval_minutes']);
        self::assertSame($this->projectDir.'/config/secrets/bigquery-service-account.json', $this->settings([])->credentialsPath());
    }

    public function testPrivateViewsNeedTheirOwnListAndUnknownViewsAreRejected(): void
    {
        try {
            BigQuerySettings::validate(['bigquery_views' => ['bi_anonymous_events_v1', 'analytics_custom_events_v1']]);
            self::fail('A private view was accepted among the approved views.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('bigquery_private_views', $e->getMessage());
        }
        foreach ([['bigquery_views' => ['events']], ['bigquery_private_views' => ['bi_anonymous_events_v1']], ['bigquery_private_views' => ['events']]] as $invalid) {
            try {
                BigQuerySettings::validate($invalid);
                self::fail('An invalid view list was accepted: '.json_encode($invalid));
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        $valid = BigQuerySettings::validate([
            'bigquery_views' => ['bi_dim_geo_area_v1', 'bi_anonymous_events_v1', 'bi_anonymous_events_v1'],
            'bigquery_private_views' => ' analytics_archived_goals_v1, analytics_custom_events_v1 ',
        ]);
        // Catalog order, duplicates removed, comma-separated lists from the environment.
        self::assertSame(['bi_anonymous_events_v1', 'bi_dim_geo_area_v1'], $valid['bigquery_views']);
        self::assertSame(['analytics_custom_events_v1', 'analytics_archived_goals_v1'], $valid['bigquery_private_views']);
        self::assertSame(['bi_anonymous_events_v1', 'bi_dim_geo_area_v1', 'analytics_custom_events_v1', 'analytics_archived_goals_v1'], BigQuerySettings::selectedViews($valid));
    }

    #[DataProvider('invalidValues')]
    public function testInvalidValuesFailClosed(array $values): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BigQuerySettings::validate($values);
    }

    public static function invalidValues(): iterable
    {
        yield 'enabled text' => [['bigquery_enabled' => 'sometimes']];
        yield 'enabled without views' => [['bigquery_enabled' => true, 'bigquery_views' => []]];
        yield 'uppercase project' => [['bigquery_project_id' => 'My-Project']];
        yield 'short project' => [['bigquery_project_id' => 'abc']];
        yield 'project ending in hyphen' => [['bigquery_project_id' => 'my-project-']];
        yield 'dataset with hyphen' => [['bigquery_dataset' => 'my-dataset']];
        yield 'empty dataset' => [['bigquery_dataset' => '']];
        yield 'location with space' => [['bigquery_location' => 'us central']];
        yield 'unknown sign-in' => [['bigquery_auth' => 'password']];
        yield 'empty key path' => [['bigquery_credentials_file' => '']];
        yield 'key path with newline' => [['bigquery_credentials_file' => "config/a\nb.json"]];
        yield 'client id' => [['bigquery_oauth_client_id' => 'not-a-client']];
        yield 'interval' => [['bigquery_interval_minutes' => 5]];
        yield 'interval text' => [['bigquery_interval_minutes' => 'hourly']];
        yield 'views mapping' => [['bigquery_views' => ['a' => 'bi_anonymous_events_v1']]];
    }

    public function testValuesAreNormalized(): void
    {
        $settings = BigQuerySettings::validate([
            'bigquery_enabled' => '1',
            'bigquery_project_id' => ' my-analytics-123 ',
            'bigquery_location' => 'eu',
            'bigquery_interval_minutes' => '1440',
            'bigquery_oauth_client_id' => '123-abc.apps.googleusercontent.com',
        ]);

        self::assertTrue($settings['bigquery_enabled']);
        self::assertSame('my-analytics-123', $settings['bigquery_project_id']);
        self::assertSame('EU', $settings['bigquery_location']);
        self::assertSame('europe-west2', BigQuerySettings::validate(['bigquery_location' => 'Europe-West2'])['bigquery_location']);
        self::assertSame(1440, $settings['bigquery_interval_minutes']);
        self::assertSame('example.com:my-project', BigQuerySettings::validate(['bigquery_project_id' => 'example.com:my-project'])['bigquery_project_id']);
    }

    public function testSaveWritesOnlyValuesTheEnvironmentDoesNotControlAndKeepsOtherSettings(): void
    {
        $settings = $this->settings(['admin_token' => 'kept', 'environments' => ['prod' => ['bigquery_dataset' => 'prod_only']]]);
        $_ENV['BIGQUERY_LOCATION'] = 'europe-west2';
        $_ENV['BIGQUERY_PRIVATE_VIEWS'] = '';

        $settings->save([
            'bigquery_enabled' => '1',
            'bigquery_dataset' => 'analytics',
            'bigquery_location' => 'US',
            'bigquery_private_views' => ['analytics_custom_events_v1'],
        ]);

        $yaml = Yaml::parseFile($this->projectDir.'/config/aggregate.yaml');
        self::assertSame('kept', $yaml['admin_token']);
        self::assertSame(['bigquery_dataset' => 'prod_only'], $yaml['environments']['prod']);
        self::assertTrue($yaml['environments']['test']['bigquery_enabled']);
        self::assertSame('analytics', $yaml['environments']['test']['bigquery_dataset']);
        self::assertArrayNotHasKey('bigquery_location', $yaml['environments']['test']);
        self::assertArrayNotHasKey('bigquery_private_views', $yaml['environments']['test']);
        self::assertSame('europe-west2', $settings->toArray()['bigquery_location']);
        self::assertSame([], $settings->toArray()['bigquery_private_views']);
        self::assertTrue($settings->getEnvironmentOverrides()['bigquery_private_views']);
        self::assertFalse($settings->getEnvironmentOverrides()['bigquery_dataset']);
    }

    public function testInvalidSaveWritesNothingAndUnexpectedKeysAreRejected(): void
    {
        $settings = $this->settings(['bigquery_dataset' => 'before']);
        $before = file_get_contents($this->projectDir.'/config/aggregate.yaml');
        foreach ([['bigquery_dataset' => 'no-hyphens'], ['admin_token' => 'x'], ['bigquery_enabled' => true, 'bigquery_views' => []]] as $candidate) {
            try {
                $settings->save($candidate);
                self::fail('An invalid BigQuery setting was saved.');
            } catch (\InvalidArgumentException) {
                self::assertSame($before, file_get_contents($this->projectDir.'/config/aggregate.yaml'));
            }
        }
    }

    public function testInvalidYamlFailsClosed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->settings(['bigquery_private_views' => ['bi_anonymous_events_v1']])->toArray();
    }

    public function testKeyFileCanBeStoredOnlyAtTheDefaultPathItManages(): void
    {
        self::assertTrue($this->settings([])->canStoreKeyFile());
        self::assertFalse($this->settings(['bigquery_credentials_file' => '/etc/aggregate/key.json'])->canStoreKeyFile());
        self::assertSame('/etc/aggregate/key.json', $this->settings(['bigquery_credentials_file' => '/etc/aggregate/key.json'])->credentialsPath());
        $_ENV['BIGQUERY_CREDENTIALS_FILE'] = BigQuerySettings::DEFAULT_CREDENTIALS_FILE;
        self::assertFalse($this->settings([])->canStoreKeyFile());
    }

    private function settings(array $values): BigQuerySettings
    {
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump($values, 4, 2));

        return new BigQuerySettings(new AggregateConfigLoader($this->projectDir, 'test'), $this->projectDir);
    }
}
