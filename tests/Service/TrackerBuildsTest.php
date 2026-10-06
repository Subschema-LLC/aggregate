<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AggregateConfigLoader;
use App\Service\TrackerBuilds;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TrackerBuildsTest extends TestCase
{
    /**
     * @param array<string, mixed> $collection
     * @param array<string, mixed> $customData
     */
    #[DataProvider('servedSettings')]
    public function testSmallerBuildOnlyWhereThePageCannotTurnTheFeatureOn(bool $enabled, array $collection, array $customData, string $expected): void
    {
        self::assertSame($expected, TrackerBuilds::select($enabled, $collection, $customData));
    }

    public static function servedSettings(): iterable
    {
        $off = ['pageSequenceEnabled' => false, 'pageSequenceMethod' => 'session_storage'];
        $on = ['pageSequenceEnabled' => true, 'pageSequenceMethod' => 'url_parameter'];
        yield 'page depth off' => [true, ['profile' => 'standard'], $off, TrackerBuilds::WITHOUT_PAGE_DEPTH];
        yield 'page depth on' => [true, ['profile' => 'standard'], $on, TrackerBuilds::FULL];
        // A copy with no served page-depth setting lets the page decide, as the tracker does.
        yield 'page depth not served' => [true, ['profile' => 'standard'], [], TrackerBuilds::FULL];
        yield 'strict profile' => [true, ['profile' => 'strict'], $off, TrackerBuilds::STRICT];
        yield 'any profile but standard is strict' => [true, ['profile' => 'relaxed'], $on, TrackerBuilds::STRICT];
        yield 'setting off' => [false, ['profile' => 'strict'], $off, TrackerBuilds::FULL];
    }

    public function testTheSettingIsOnByDefaultAndReadFromYamlOrTheEnvironment(): void
    {
        self::assertTrue((new TrackerBuilds($this->config([])))->enabled());
        self::assertFalse((new TrackerBuilds($this->config([TrackerBuilds::CONFIG_KEY => false])))->enabled());

        $_ENV['TRACKER_OMIT_UNUSED_FEATURES'] = 'off';
        try {
            $builds = new TrackerBuilds($this->config([TrackerBuilds::CONFIG_KEY => true]));
            self::assertFalse($builds->enabled());
            self::assertTrue($builds->hasEnvironmentOverride());
        } finally {
            unset($_ENV['TRACKER_OMIT_UNUSED_FEATURES']);
        }
    }

    public function testValuesAreValidatedBeforeSaving(): void
    {
        foreach ([true, 1, '1', 'true', 'Yes', ' on '] as $value) {
            self::assertTrue(TrackerBuilds::normalize($value), var_export($value, true));
        }
        foreach ([false, 0, '0', 'false', 'no', 'off'] as $value) {
            self::assertFalse(TrackerBuilds::normalize($value), var_export($value, true));
        }
        foreach (['maybe', '', 2, null, []] as $value) {
            try {
                TrackerBuilds::normalize($value);
                self::fail('accepted '.var_export($value, true));
            } catch (\InvalidArgumentException) {
            }
        }
    }

    public function testEveryBuildNamesItsSwitches(): void
    {
        foreach (TrackerBuilds::SWITCHES as $switches) {
            self::assertSame(['withPageDepth', 'withStandardProfile'], array_keys($switches));
        }
        // The Terser build defines the same builds and switches.
        $script = (string) file_get_contents(dirname(__DIR__, 2).'/scripts/build-js.cjs');
        foreach (TrackerBuilds::SWITCHES as $build => $switches) {
            self::assertStringContainsString(sprintf("%s: {withPageDepth: %s, withStandardProfile: %s}", $build === 'full' || $build === 'strict' ? $build : "'".$build."'", var_export($switches['withPageDepth'], true), var_export($switches['withStandardProfile'], true)), $script);
        }
    }

    /** @param array<string, mixed> $settings */
    private function config(array $settings): AggregateConfigLoader
    {
        $directory = sys_get_temp_dir().'/aggregate-tracker-builds-'.bin2hex(random_bytes(6));
        mkdir($directory.'/config', 0777, true);
        file_put_contents($directory.'/config/aggregate.yaml', \Symfony\Component\Yaml\Yaml::dump($settings));
        register_shutdown_function(static function () use ($directory): void {
            (new \Symfony\Component\Filesystem\Filesystem())->remove($directory);
        });

        return new AggregateConfigLoader($directory, 'test');
    }
}
