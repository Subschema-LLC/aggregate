<?php

declare(strict_types=1);

namespace App\Tests\Service\ForensicDatabase;

use App\Service\AggregateConfigLoader;
use App\Service\ForensicDatabase\ForensicCatalog;
use App\Service\ForensicDatabase\ForensicDatabaseSettings;
use App\Service\ViewSync\ViewCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class ForensicDatabaseSettingsTest extends TestCase
{
    private string $projectDir;
    private array $environment;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        foreach (array_keys(ForensicDatabaseSettings::defaults()) as $key) {
            unset($_ENV[strtoupper($key)], $_SERVER[strtoupper($key)]);
        }
        $this->projectDir = sys_get_temp_dir().'/aggregate-forensic-settings-'.bin2hex(random_bytes(6));
        mkdir($this->projectDir.'/config', 0700, true);
    }

    protected function tearDown(): void
    {
        [$_ENV, $_SERVER] = $this->environment;
        array_map('unlink', glob($this->projectDir.'/config/*') ?: []);
        rmdir($this->projectDir.'/config');
        rmdir($this->projectDir);
    }

    public function testItIsOffByDefaultAndCopiesEverythingDailyOnceTurnedOn(): void
    {
        self::assertSame([
            'forensic_database_enabled' => false,
            'forensic_database_tables' => array_keys(ForensicCatalog::TABLES),
            'forensic_database_views' => array_keys(ViewCatalog::PRIVATE),
            'forensic_database_interval_minutes' => 1440,
            'forensic_database_access_restricted' => false,
        ], $this->settings([])->toArray());
        self::assertSame([...array_keys(ForensicCatalog::TABLES), ...array_keys(ViewCatalog::PRIVATE)], ForensicDatabaseSettings::selectedViews(ForensicDatabaseSettings::validate([])));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalid(): iterable
    {
        yield 'a private view among the tables' => [['forensic_database_tables' => ['analytics_custom_events_v1']], 'list private views under forensic_database_views'];
        yield 'an unknown table' => [['forensic_database_tables' => ['events']], 'events is not a table the forensic database copies'];
        yield 'an approved view' => [['forensic_database_views' => ['bi_anonymous_events_v1']], 'Approved views belong in the reporting database.'];
        yield 'nothing to copy while on' => [['forensic_database_enabled' => true, 'forensic_database_tables' => [], 'forensic_database_views' => []], 'Choose at least one table or view'];
        yield 'an unknown interval' => [['forensic_database_interval_minutes' => 5], 'The sync interval must be one of'];
        yield 'an unclear confirmation' => [['forensic_database_access_restricted' => 'maybe'], 'forensic_database_access_restricted must be true or false.'];
    }

    /** @param array<string, mixed> $values */
    #[DataProvider('invalid')]
    public function testInvalidValuesAreRefused(array $values, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        ForensicDatabaseSettings::validate($values);
    }

    public function testListsKeepCatalogOrderAndTheEnvironmentWins(): void
    {
        $settings = $this->settings([
            'forensic_database_tables' => ['forensic_archive_goals_v1', 'forensic_events_v1'],
            'forensic_database_views' => [],
        ]);
        self::assertSame(['forensic_events_v1', 'forensic_archive_goals_v1'], $settings->toArray()['forensic_database_tables']);

        $_ENV['FORENSIC_DATABASE_ACCESS_RESTRICTED'] = 'true';
        $_ENV['FORENSIC_DATABASE_VIEWS'] = '';
        self::assertTrue($settings->accessRestricted());
        self::assertSame([], $settings->toArray()['forensic_database_views']);
        self::assertTrue($settings->getEnvironmentOverrides()['forensic_database_access_restricted']);
    }

    public function testSavingWritesOnlyItsOwnKeys(): void
    {
        $settings = $this->settings(['admin_token' => 'kept']);
        $settings->save(['forensic_database_enabled' => true, 'forensic_database_access_restricted' => true]);

        $yaml = Yaml::parseFile($this->projectDir.'/config/aggregate.yaml');
        self::assertSame('kept', $yaml['admin_token']);
        self::assertTrue($yaml['forensic_database_enabled']);
        self::assertTrue($yaml['forensic_database_access_restricted']);
    }

    /** @param array<string, mixed> $values */
    private function settings(array $values): ForensicDatabaseSettings
    {
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump($values, 4, 2));

        return new ForensicDatabaseSettings(new AggregateConfigLoader($this->projectDir, 'test'));
    }
}
