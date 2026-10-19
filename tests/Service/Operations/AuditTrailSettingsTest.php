<?php

declare(strict_types=1);

namespace App\Tests\Service\Operations;

use App\Service\AggregateConfigLoader;
use App\Service\Operations\AuditTrail;
use App\Service\Operations\AuditTrailSettings;
use App\Service\Operations\ConfigurationChanges;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class AuditTrailSettingsTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/aggregate-audit-settings-'.bin2hex(random_bytes(8));
        mkdir($this->projectDir.'/config', 0700, true);
    }

    protected function tearDown(): void
    {
        ConfigurationChanges::finish();
        foreach (glob($this->projectDir.'/config/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->projectDir.'/config');
        rmdir($this->projectDir);
    }

    public function testEveryAreaIsRecordedByDefaultExceptSignIns(): void
    {
        $settings = $this->settings(null);

        self::assertSame([
            'settings' => true, 'users' => true, 'websites' => true, 'integrations' => true,
            'updates' => true, 'operations' => true, 'task' => true, 'sign_ins' => false,
        ], $settings->all());
        self::assertTrue($settings->isRecorded(AuditTrailSettings::TASKS));
        self::assertFalse($settings->isRecorded(AuditTrailSettings::SIGN_INS));
        self::assertFalse($settings->isRecorded('unknown'));
        foreach (AuditTrailSettings::AREAS as $definition) {
            self::assertNotSame('', $definition['label']);
            self::assertNotSame('', $definition['description']);
        }
    }

    public function testSavingKeepsTheOtherSwitchesAndNamesTheChangedOnes(): void
    {
        $settings = $this->settings(['operations' => false]);
        ConfigurationChanges::start();

        $settings->save(['sign_ins' => true, 'settings' => false]);

        $saved = (new AuditTrailSettings(new AggregateConfigLoader($this->projectDir, 'test')))->all();
        self::assertSame('Kept', Yaml::parseFile($this->projectDir.'/config/aggregate.yaml')['brand_name']);
        self::assertFalse($saved['operations'], 'an earlier switch is kept');
        self::assertTrue($saved['sign_ins']);
        self::assertFalse($saved['settings']);
        self::assertTrue($saved['users']);
        self::assertSame(['config/aggregate.yaml' => ['audit_trail_areas.settings', 'audit_trail_areas.sign_ins']], ConfigurationChanges::finish());
    }

    #[DataProvider('invalidValues')]
    public function testInvalidYamlRecordsEveryAreaAsByDefault(mixed $value, string $message): void
    {
        $settings = $this->settings($value);

        self::assertTrue($settings->isRecorded(AuditTrailSettings::SETTINGS));
        self::assertFalse($settings->isRecorded(AuditTrailSettings::SIGN_INS));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $settings->all();
    }

    public static function invalidValues(): iterable
    {
        yield 'a list' => [['settings', 'users'], 'must be a mapping'];
        yield 'an unknown area' => [['settings' => true, 'everything' => false], 'unknown area'];
        yield 'not a boolean' => [['settings' => 'off'], 'audit_trail_areas.settings must be true or false'];
        yield 'a string' => ['all', 'must be a mapping'];
    }

    public function testInvalidChangesAreRefusedBeforeAnythingIsWritten(): void
    {
        $settings = $this->settings(['users' => false]);
        $before = file_get_contents($this->projectDir.'/config/aggregate.yaml');

        foreach ([['everything' => true], ['users' => 1], ['users' => 'true']] as $changes) {
            try {
                $settings->save($changes);
                self::fail('Saved '.json_encode($changes));
            } catch (\InvalidArgumentException) {
            }
        }
        self::assertSame($before, file_get_contents($this->projectDir.'/config/aggregate.yaml'));
    }

    public function testSavingOverAnInvalidValueReplacesItAndSavingDefaultsWritesNothing(): void
    {
        $settings = $this->settings(['settings' => 'off']);
        $settings->save(['settings' => false]);
        self::assertSame(['settings' => false], Yaml::parseFile($this->projectDir.'/config/aggregate.yaml')[AuditTrailSettings::KEY]);

        $settings = $this->settings(null);
        $before = file_get_contents($this->projectDir.'/config/aggregate.yaml');
        $settings->save(AuditTrailSettings::defaults());
        self::assertSame($before, file_get_contents($this->projectDir.'/config/aggregate.yaml'));
    }

    public function testTheAuditTrailSkipsAreasThatAreOffUnlessTheEntryMustBeKept(): void
    {
        $connection = OperationsDatabase::connection();
        $trail = new AuditTrail($connection, $this->settings(['task' => false, 'settings' => false]));
        $now = new \DateTimeImmutable('2026-10-18 12:00:00 UTC');

        $trail->record('task', 'bigquery_sync', 'succeeded', $now);
        $trail->record('settings', 'branding_saved', 'succeeded', $now, null, 'scott');
        $trail->record('settings', 'audit_settings_saved', 'succeeded', $now, null, 'scott', null, null, true);
        $trail->record('users', 'user_created', 'succeeded', $now, 'ana', 'scott');
        // Categories outside the areas (from older versions) are always kept.
        $trail->record('legacy', 'something', 'succeeded', $now);

        self::assertSame(['audit_settings_saved', 'user_created', 'something'], $connection->fetchFirstColumn('SELECT operation FROM audit_trail ORDER BY id'));
    }

    private function settings(mixed $areas): AuditTrailSettings
    {
        $config = ['brand_name' => 'Kept'];
        if ($areas !== null) {
            $config[AuditTrailSettings::KEY] = $areas;
        }
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump($config, 4, 2));

        return new AuditTrailSettings(new AggregateConfigLoader($this->projectDir, 'test'));
    }
}
