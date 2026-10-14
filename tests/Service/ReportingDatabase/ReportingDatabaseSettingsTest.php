<?php

declare(strict_types=1);

namespace App\Tests\Service\ReportingDatabase;

use App\Service\AggregateConfigLoader;
use App\Service\BigQuery\BigQueryViewCatalog;
use App\Service\ReportingDatabase\ReportingDatabaseSettings;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class ReportingDatabaseSettingsTest extends TestCase
{
    private string $projectDir;
    private array $environment;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        foreach (array_keys(ReportingDatabaseSettings::defaults()) as $key) {
            unset($_ENV[strtoupper($key)], $_SERVER[strtoupper($key)]);
        }
        $this->projectDir = sys_get_temp_dir().'/aggregate-reporting-settings-'.bin2hex(random_bytes(6));
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

        self::assertFalse($settings['reporting_database_enabled']);
        self::assertSame(array_keys(BigQueryViewCatalog::APPROVED), $settings['reporting_database_views']);
        self::assertSame([], $settings['reporting_database_private_views']);
        self::assertSame(60, $settings['reporting_database_interval_minutes']);
    }

    public function testPrivateViewsNeedTheirOwnListAndUnknownViewsAreRejected(): void
    {
        try {
            ReportingDatabaseSettings::validate(['reporting_database_views' => ['bi_anonymous_events_v1', 'analytics_custom_events_v1']]);
            self::fail('A private view was accepted among the approved views.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('reporting_database_private_views', $e->getMessage());
        }
        foreach ([
            ['reporting_database_views' => ['events']],
            ['reporting_database_private_views' => ['bi_anonymous_events_v1']],
            ['reporting_database_private_views' => ['events']],
            ['reporting_database_views' => 'bi_anonymous_events_v1; DROP TABLE events'],
            ['reporting_database_views' => ['a' => 'bi_anonymous_events_v1']],
            ['reporting_database_enabled' => 'sometimes'],
            ['reporting_database_interval_minutes' => 5],
            ['reporting_database_interval_minutes' => '60 minutes'],
            ['reporting_database_enabled' => true, 'reporting_database_views' => [], 'reporting_database_private_views' => []],
        ] as $invalid) {
            try {
                ReportingDatabaseSettings::validate($invalid);
                self::fail('An invalid setting was accepted: '.json_encode($invalid));
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        $valid = ReportingDatabaseSettings::validate([
            'reporting_database_enabled' => 'yes',
            'reporting_database_views' => ['bi_dim_geo_area_v1', 'bi_anonymous_events_v1', 'bi_anonymous_events_v1'],
            'reporting_database_private_views' => ' analytics_archived_goals_v1, analytics_custom_events_v1 ',
            'reporting_database_interval_minutes' => '1440',
        ]);
        // Catalog order, duplicates removed, comma-separated lists from the environment.
        self::assertTrue($valid['reporting_database_enabled']);
        self::assertSame(['bi_anonymous_events_v1', 'bi_dim_geo_area_v1'], $valid['reporting_database_views']);
        self::assertSame(['analytics_custom_events_v1', 'analytics_archived_goals_v1'], $valid['reporting_database_private_views']);
        self::assertSame(1440, $valid['reporting_database_interval_minutes']);
        self::assertSame(['bi_anonymous_events_v1', 'bi_dim_geo_area_v1', 'analytics_custom_events_v1', 'analytics_archived_goals_v1'], ReportingDatabaseSettings::selectedViews($valid));
    }

    public function testSavingWritesOnlyTheseKeysAndKeepsEnvironmentControlledValues(): void
    {
        $_ENV['REPORTING_DATABASE_INTERVAL_MINUTES'] = '15';
        $settings = $this->settings(['admin_token' => 'kept']);

        $settings->save(['reporting_database_enabled' => '1', 'reporting_database_views' => ['bi_anonymous_events_v1'], 'reporting_database_interval_minutes' => '1440']);

        $yaml = Yaml::parseFile($this->projectDir.'/config/aggregate.yaml');
        self::assertSame('kept', $yaml['admin_token']);
        self::assertTrue($yaml['reporting_database_enabled']);
        self::assertSame(['bi_anonymous_events_v1'], $yaml['reporting_database_views']);
        self::assertArrayNotHasKey('reporting_database_interval_minutes', $yaml);
        self::assertSame(15, $settings->toArray()['reporting_database_interval_minutes']);
        self::assertTrue($settings->getEnvironmentOverrides()['reporting_database_interval_minutes']);
        self::assertFalse($settings->getEnvironmentOverrides()['reporting_database_enabled']);
    }

    public function testNothingIsSavedWhenAnyValueIsInvalidOrUnexpected(): void
    {
        $settings = $this->settings(['reporting_database_views' => ['bi_dim_geo_area_v1']]);
        $before = (string) file_get_contents($this->projectDir.'/config/aggregate.yaml');

        foreach ([
            ['reporting_database_enabled' => '1', 'reporting_database_interval_minutes' => '7'],
            ['reporting_database_views' => ['bi_anonymous_events_v1'], 'reporting_database_url' => 'mysql://root@localhost/x'],
        ] as $candidate) {
            try {
                $settings->save($candidate);
                self::fail('An invalid candidate was saved.');
            } catch (\InvalidArgumentException) {
                self::assertSame($before, file_get_contents($this->projectDir.'/config/aggregate.yaml'));
            }
        }
    }

    private function settings(array $values): ReportingDatabaseSettings
    {
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump($values, 4, 2));

        return new ReportingDatabaseSettings(new AggregateConfigLoader($this->projectDir, 'test'));
    }
}
