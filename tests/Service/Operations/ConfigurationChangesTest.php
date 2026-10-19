<?php

declare(strict_types=1);

namespace App\Tests\Service\Operations;

use App\Service\Operations\ConfigurationChanges;
use PHPUnit\Framework\TestCase;

final class ConfigurationChangesTest extends TestCase
{
    protected function tearDown(): void
    {
        ConfigurationChanges::finish();
    }

    public function testNothingIsCollectedOutsideAnAction(): void
    {
        ConfigurationChanges::record('config/aggregate.yaml', ['brand_name']);
        ConfigurationChanges::recordMapping('config/aggregate.yaml', ['a' => 1], ['a' => 2]);
        self::assertFalse(ConfigurationChanges::isRecording());

        ConfigurationChanges::start();
        self::assertSame([], ConfigurationChanges::finish());
    }

    public function testFilesAndSettingNamesAreCollectedSortedAndWithoutValues(): void
    {
        ConfigurationChanges::start();
        ConfigurationChanges::recordMapping('config/aggregate.yaml', [
            'brand_name' => 'Old',
            'rate_limit_per_minute' => 100,
            'feature_flags' => ['updates' => ['enabled' => true], 'bigquery' => ['enabled' => true]],
            'removed' => 'x',
        ], [
            'brand_name' => 'New secret-looking value',
            'rate_limit_per_minute' => 100,
            'feature_flags' => ['updates' => ['enabled' => false], 'bigquery' => ['enabled' => true]],
            'added' => true,
        ]);
        ConfigurationChanges::record('.env.local', ['BIGQUERY_OAUTH_CLIENT_SECRET']);
        ConfigurationChanges::record('config/secrets/bigquery-service-account.json');
        ConfigurationChanges::record('.env.local', ['APP_HOST']);
        self::assertTrue(ConfigurationChanges::isRecording());

        $changes = ConfigurationChanges::finish();
        self::assertSame([
            '.env.local' => ['APP_HOST', 'BIGQUERY_OAUTH_CLIENT_SECRET'],
            'config/aggregate.yaml' => ['added', 'brand_name', 'feature_flags.updates', 'removed'],
            'config/secrets/bigquery-service-account.json' => [],
        ], $changes);
        self::assertFalse(ConfigurationChanges::isRecording());
        $described = ConfigurationChanges::describe($changes);
        self::assertSame('Changed .env.local: APP_HOST, BIGQUERY_OAUTH_CLIENT_SECRET; aggregate.yaml: added, brand_name, feature_flags.updates, removed; secrets/bigquery-service-account.json', $described);
        self::assertStringNotContainsString('New secret-looking value', (string) $described);
    }

    public function testReorderedMappingsAndUnchangedValuesAreNotChanges(): void
    {
        self::assertSame([], ConfigurationChanges::changedNames(
            ['tag_manager' => ['b' => 2, 'a' => ['y' => 1, 'x' => 2]], 'list' => [1, 2]],
            ['tag_manager' => ['a' => ['x' => 2, 'y' => 1], 'b' => 2], 'list' => [1, 2]],
        ));
        // A reordered list is a change; deeper settings are named by their parent.
        self::assertSame(['tag_manager.a', 'list'], ConfigurationChanges::changedNames(
            ['tag_manager' => ['a' => ['x' => 1]], 'list' => [1, 2]],
            ['tag_manager' => ['a' => ['x' => 2]], 'list' => [2, 1]],
        ));
        // A setting set to null where it was missing is a change.
        self::assertSame(['empty'], ConfigurationChanges::changedNames([], ['empty' => null]));
        // A nested mapping replaced by a scalar is named once.
        self::assertSame(['feature_flags'], ConfigurationChanges::changedNames(['feature_flags' => ['a' => true]], ['feature_flags' => 'off']));
    }

    public function testAFileWithManyChangesListsAtMostFiftyNames(): void
    {
        ConfigurationChanges::start();
        $names = array_map(static fn (int $number): string => sprintf('setting_%03d', $number), range(1, 80));
        ConfigurationChanges::record('config/aggregate.yaml', $names);

        self::assertCount(50, ConfigurationChanges::finish()['config/aggregate.yaml']);
        self::assertNull(ConfigurationChanges::describe([]));
    }

    public function testSavingTheSameValuesRecordsNothing(): void
    {
        ConfigurationChanges::start();
        ConfigurationChanges::recordMapping('config/aggregate.yaml', ['brand_name' => 'Same'], ['brand_name' => 'Same']);

        self::assertSame([], ConfigurationChanges::finish());
    }

    public function testStartingAgainForgetsTheEarlierAction(): void
    {
        ConfigurationChanges::start();
        ConfigurationChanges::record('config/websites.yaml', ['websites']);
        ConfigurationChanges::start();

        self::assertSame([], ConfigurationChanges::finish());
    }
}
