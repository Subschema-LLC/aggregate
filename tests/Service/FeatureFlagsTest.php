<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AggregateConfigLoader;
use App\Service\FeatureFlags;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class FeatureFlagsTest extends TestCase
{
    private string $projectDir;
    private array $environment;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/aggregate-feature-flags-'.bin2hex(random_bytes(8));
        mkdir($this->projectDir.'/config', 0700, true);
        $this->environment = [$_ENV, $_SERVER];
        foreach (['ANONYMOUS_TRACKING_ENABLED', 'ANONYMOUS_EXCLUDED_PATHS', 'FEATURE_FLAGS', 'UPDATES_ENABLED', 'UPDATES_HIDE_FROM_NAVIGATION'] as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }

    protected function tearDown(): void
    {
        [$_ENV, $_SERVER] = $this->environment;
        foreach (glob($this->projectDir.'/config/*') as $path) {
            unlink($path);
        }
        rmdir($this->projectDir.'/config');
        rmdir($this->projectDir);
    }

    public function testDefaultsPreserveExistingUpdatesAndSeparateMetadata(): void
    {
        $flags = $this->flags([]);

        self::assertSame(['updates' => ['enabled' => true, 'hide_from_navigation' => false]], $flags->all());
        self::assertTrue($flags->isEnabled('updates'));
        self::assertFalse($flags->isHiddenFromNavigation('updates'));
        self::assertSame('Updates', $flags->definitions()['updates']['label']);
        self::assertNotEmpty($flags->definitions()['updates']['description']);
        $flags->assertEnabled('updates');
    }

    public function testMissingConfigurationFileUsesDefaults(): void
    {
        $flags = new FeatureFlags(new AggregateConfigLoader($this->projectDir, 'test'));

        self::assertTrue($flags->isEnabled('updates'));
        self::assertFalse($flags->isHiddenFromNavigation('updates'));
    }

    public function testVisibilityDoesNotDisableAnEnabledFeature(): void
    {
        $flags = $this->flags(['feature_flags' => ['updates' => ['hide_from_navigation' => true]]]);

        self::assertTrue($flags->isEnabled('updates'));
        self::assertTrue($flags->isHiddenFromNavigation('updates'));
        $flags->assertEnabled('updates');
    }

    public function testDisabledFeatureKeepsIndependentNavigationPreference(): void
    {
        $flags = $this->flags(['feature_flags' => ['updates' => ['enabled' => false]]]);

        self::assertFalse($flags->isEnabled('updates'));
        self::assertFalse($flags->isHiddenFromNavigation('updates'));
        $this->expectException(\RuntimeException::class);
        $flags->assertEnabled('updates');
    }

    public function testUnknownFeatureFailsClosed(): void
    {
        $flags = $this->flags([]);

        self::assertFalse($flags->isEnabled('unregistered'));
        self::assertTrue($flags->isHiddenFromNavigation('unregistered'));
        $this->expectException(\RuntimeException::class);
        $flags->assertEnabled('unregistered');
    }

    #[DataProvider('invalidFlags')]
    public function testInvalidConfigurationFailsClosedAndCannotBeOverwritten(mixed $configured): void
    {
        $flags = $this->flags(['feature_flags' => $configured]);
        $before = file_get_contents($this->projectDir.'/config/aggregate.yaml');

        self::assertFalse($flags->isEnabled('updates'));
        self::assertTrue($flags->isHiddenFromNavigation('updates'));
        try {
            $flags->all();
            self::fail('Invalid feature flags were accepted.');
        } catch (\InvalidArgumentException) {
        }
        try {
            $flags->assertEnabled('updates');
            self::fail('Invalid feature flags authorized a capability.');
        } catch (\RuntimeException) {
        }
        try {
            $flags->save(['updates' => ['enabled' => true]]);
            self::fail('Invalid feature flags were overwritten.');
        } catch (\InvalidArgumentException) {
            self::assertSame($before, file_get_contents($this->projectDir.'/config/aggregate.yaml'));
        }
    }

    public static function invalidFlags(): iterable
    {
        yield 'null mapping' => [null];
        yield 'scalar mapping' => [false];
        yield 'list mapping' => [['updates']];
        yield 'unknown flag' => [['udpates' => ['enabled' => false]]];
        yield 'null options' => [['updates' => null]];
        yield 'scalar options' => [['updates' => true]];
        yield 'list options' => [['updates' => [false]]];
        yield 'unknown option' => [['updates' => ['hidden' => true]]];
        foreach (['enabled', 'hide_from_navigation'] as $option) {
            foreach (['false', 'true', '0', '1', 0, 1, null, [], ['enabled' => false]] as $index => $value) {
                yield $option.' nonboolean '.$index => [['updates' => [$option => $value]]];
            }
        }
    }

    public function testMalformedYamlFailsClosedWithoutReplacingIt(): void
    {
        $yaml = "feature_flags: [\n";
        file_put_contents($this->projectDir.'/config/aggregate.yaml', $yaml);
        $flags = new FeatureFlags(new AggregateConfigLoader($this->projectDir, 'test'));

        self::assertFalse($flags->isEnabled('updates'));
        self::assertTrue($flags->isHiddenFromNavigation('updates'));
        try {
            $flags->all();
            self::fail('Malformed YAML was accepted.');
        } catch (\RuntimeException) {
        }
        try {
            $flags->save(['updates' => ['enabled' => true]]);
            self::fail('Malformed YAML was overwritten.');
        } catch (\RuntimeException) {
            self::assertSame($yaml, file_get_contents($this->projectDir.'/config/aggregate.yaml'));
        }
    }

    public function testUnhealthyApplicationConfigurationDoesNotEnableFeatures(): void
    {
        $flags = $this->flags(['anonymous_tracking_enabled' => 'invalid']);

        self::assertFalse($flags->isEnabled('updates'));
        self::assertTrue($flags->isHiddenFromNavigation('updates'));
        $this->expectException(\RuntimeException::class);
        $flags->all();
    }

    public function testPartialSavePreservesVisibilityAndUnrelatedConfiguration(): void
    {
        $flags = $this->flags([
            'app_host' => 'https://analytics.example',
            'custom_data_properties' => ['plan' => ['consent_required' => true]],
            'feature_flags' => ['updates' => ['enabled' => true, 'hide_from_navigation' => true]],
        ]);

        $flags->save(['updates' => ['enabled' => false]]);

        self::assertSame(['updates' => ['enabled' => false, 'hide_from_navigation' => true]], $flags->all());
        self::assertSame([
            'app_host' => 'https://analytics.example',
            'custom_data_properties' => ['plan' => ['consent_required' => true]],
            'feature_flags' => ['updates' => ['enabled' => false, 'hide_from_navigation' => true]],
        ], Yaml::parseFile($this->projectDir.'/config/aggregate.yaml'));
        self::assertFalse((new FeatureFlags(new AggregateConfigLoader($this->projectDir, 'test')))->isEnabled('updates'));

        $flags->save(['updates' => ['hide_from_navigation' => false]]);
        self::assertFalse($flags->isEnabled('updates'));
        self::assertFalse($flags->isHiddenFromNavigation('updates'));
    }

    public function testSaveAddsDefaultsForOmittedOptions(): void
    {
        $flags = $this->flags(['app_host' => 'https://analytics.example']);

        $flags->save(['updates' => ['hide_from_navigation' => true]]);

        self::assertSame(['updates' => ['enabled' => true, 'hide_from_navigation' => true]], $flags->all());
        self::assertSame($flags->all(), Yaml::parseFile($this->projectDir.'/config/aggregate.yaml')['feature_flags']);
    }

    #[DataProvider('configurationLayouts')]
    public function testStaleReadersPreserveEachOthersPartialChanges(string $layout): void
    {
        $initial = ['updates' => ['enabled' => true, 'hide_from_navigation' => false]];
        $config = ['feature_flags' => $initial, 'app_host' => 'https://analytics.example'];
        if ($layout === 'nested') {
            $config = ['feature_flags' => $initial, 'environments' => ['test' => ['app_host' => 'https://analytics.example']]];
        }
        $this->flags($config);
        if ($layout === 'environment-file') {
            file_put_contents($this->projectDir.'/config/aggregate_test.yaml', Yaml::dump($config, 6, 2));
        }
        $first = new FeatureFlags(new AggregateConfigLoader($this->projectDir, 'test'));
        $second = new FeatureFlags(new AggregateConfigLoader($this->projectDir, 'test'));
        self::assertSame($initial, $first->all());
        self::assertSame($initial, $second->all());

        $first->save(['updates' => ['enabled' => false]]);
        $second->save(['updates' => ['hide_from_navigation' => true]]);

        $reloaded = new FeatureFlags(new AggregateConfigLoader($this->projectDir, 'test'));
        self::assertSame(['updates' => ['enabled' => false, 'hide_from_navigation' => true]], $reloaded->all());
        self::assertSame($reloaded->all(), $second->all());
    }

    public static function configurationLayouts(): iterable
    {
        yield 'flat file' => ['flat'];
        yield 'active environment' => ['nested'];
        yield 'environment-specific file' => ['environment-file'];
    }

    public function testSaveRejectsNewlyMalformedFlagsAfterAValidConfigurationWasCached(): void
    {
        $flags = $this->flags(['feature_flags' => ['updates' => ['enabled' => true]]]);
        self::assertTrue($flags->isEnabled('updates'));
        $malformed = "feature_flags:\n  updates:\n    enabled: 'false'\napp_host: https://latest.example\n";
        file_put_contents($this->projectDir.'/config/aggregate.yaml', $malformed);

        try {
            $flags->save(['updates' => ['hide_from_navigation' => true]]);
            self::fail('A stale reader overwrote newly malformed feature flags.');
        } catch (\InvalidArgumentException) {
            self::assertSame($malformed, file_get_contents($this->projectDir.'/config/aggregate.yaml'));
        }
    }

    public function testEmptySaveDoesNotRewriteConfiguration(): void
    {
        $yaml = "# Preserve this comment for an empty save.\napp_host: https://analytics.example\n";
        file_put_contents($this->projectDir.'/config/aggregate.yaml', $yaml);
        $flags = new FeatureFlags(new AggregateConfigLoader($this->projectDir, 'test'));

        $flags->save([]);

        self::assertSame($yaml, file_get_contents($this->projectDir.'/config/aggregate.yaml'));
    }

    #[DataProvider('invalidChanges')]
    public function testInvalidChangesDoNotPartiallySave(array $changes): void
    {
        $flags = $this->flags(['feature_flags' => ['updates' => ['enabled' => false]]]);
        $before = file_get_contents($this->projectDir.'/config/aggregate.yaml');

        try {
            $flags->save($changes);
            self::fail('Invalid changes were accepted.');
        } catch (\InvalidArgumentException) {
            self::assertSame($before, file_get_contents($this->projectDir.'/config/aggregate.yaml'));
            self::assertFalse($flags->isEnabled('updates'));
        }
    }

    public static function invalidChanges(): iterable
    {
        foreach (self::invalidFlags() as $name => [$value]) {
            if (is_array($value)) {
                yield $name => [$value];
            }
        }
        yield 'valid flag followed by unknown flag' => [[
            'updates' => ['enabled' => true],
            'unregistered' => ['enabled' => true],
        ]];
    }

    public function testActiveEnvironmentReplacesTheSharedMappingAndSavePreservesOtherEnvironments(): void
    {
        $shared = ['updates' => ['enabled' => false, 'hide_from_navigation' => true]];
        $otherEnvironment = ['app_host' => 'https://production.example', 'feature_flags' => $shared];
        $flags = $this->flags([
            'feature_flags' => $shared,
            'app_host' => 'https://shared.example',
            'environments' => [
                'test' => ['app_host' => 'https://test.example', 'feature_flags' => ['updates' => ['hide_from_navigation' => true]]],
                'prod' => $otherEnvironment,
            ],
        ]);

        self::assertTrue($flags->isEnabled('updates'), 'The active mapping replaces the shared mapping; omitted enabled uses its default.');
        self::assertTrue($flags->isHiddenFromNavigation('updates'));
        $flags->save(['updates' => ['enabled' => false]]);

        $written = Yaml::parseFile($this->projectDir.'/config/aggregate.yaml');
        self::assertSame($shared, $written['feature_flags']);
        self::assertSame('https://shared.example', $written['app_host']);
        self::assertSame($otherEnvironment, $written['environments']['prod']);
        self::assertSame('https://test.example', $written['environments']['test']['app_host']);
        self::assertSame(['updates' => ['enabled' => false, 'hide_from_navigation' => true]], $written['environments']['test']['feature_flags']);
    }

    public function testSharedMappingIsUsedWhenTheActiveEnvironmentOmitsIt(): void
    {
        $flags = $this->flags([
            'feature_flags' => ['updates' => ['enabled' => false]],
            'environments' => ['test' => ['app_host' => 'https://test.example']],
        ]);

        self::assertFalse($flags->isEnabled('updates'));
    }

    public function testEnvironmentSpecificFileWinsAndReceivesChanges(): void
    {
        $this->flags(['feature_flags' => ['updates' => ['enabled' => false]], 'app_host' => 'https://main.example']);
        $main = file_get_contents($this->projectDir.'/config/aggregate.yaml');
        file_put_contents($this->projectDir.'/config/aggregate_test.yaml', "app_host: https://test.example\nfeature_flags:\n  updates:\n    hide_from_navigation: true\n");
        $flags = new FeatureFlags(new AggregateConfigLoader($this->projectDir, 'test'));

        self::assertTrue($flags->isEnabled('updates'));
        self::assertTrue($flags->isHiddenFromNavigation('updates'));
        $flags->save(['updates' => ['enabled' => false]]);

        self::assertSame($main, file_get_contents($this->projectDir.'/config/aggregate.yaml'));
        self::assertSame([
            'app_host' => 'https://test.example',
            'feature_flags' => ['updates' => ['enabled' => false, 'hide_from_navigation' => true]],
        ], Yaml::parseFile($this->projectDir.'/config/aggregate_test.yaml'));
    }

    public function testUppercaseEnvironmentVariablesCannotOverrideYaml(): void
    {
        foreach (['FEATURE_FLAGS', 'UPDATES_ENABLED', 'UPDATES_HIDE_FROM_NAVIGATION'] as $key) {
            $_ENV[$key] = 'true';
            $_SERVER[$key] = 'true';
        }
        $flags = $this->flags(['feature_flags' => ['updates' => ['enabled' => false, 'hide_from_navigation' => false]]]);

        self::assertFalse($flags->isEnabled('updates'));
        self::assertFalse($flags->isHiddenFromNavigation('updates'));
        $flags->save(['updates' => ['enabled' => true]]);
        self::assertTrue($flags->isEnabled('updates'));
        self::assertTrue(Yaml::parseFile($this->projectDir.'/config/aggregate.yaml')['feature_flags']['updates']['enabled']);
    }

    private function flags(array $config): FeatureFlags
    {
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump($config, 6, 2));

        return new FeatureFlags(new AggregateConfigLoader($this->projectDir, 'test'));
    }
}
