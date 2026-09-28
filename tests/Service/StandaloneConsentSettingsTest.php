<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AggregateConfigLoader;
use App\Service\CustomDataSettings;
use App\Service\SiteScriptConfig;
use App\Service\StandaloneConsentSettings;
use App\Service\TagManagerSettings;
use App\Service\WebsiteConfigManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class StandaloneConsentSettingsTest extends TestCase
{
    private string $projectDir;
    private string $firstId;
    private string $secondId;
    private SiteScriptConfig $sites;
    private StandaloneConsentSettings $settings;
    private array $environment;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/aggregate-standalone-consent-'.bin2hex(random_bytes(8));
        mkdir($this->projectDir.'/config', 0700, true);
        $this->write('config/websites.yaml', ['websites' => [
            ['name' => 'Storefront', 'domain' => 'shared.example', 'token' => 'storefront-token'],
            ['name' => 'Portal', 'domain' => 'shared.example', 'token' => 'portal-token'],
        ]]);
        $this->firstId = SiteScriptConfig::idForToken('storefront-token');
        $this->secondId = SiteScriptConfig::idForToken('portal-token');
        $this->sites = new SiteScriptConfig(new WebsiteConfigManager($this->projectDir), $this->projectDir, 'test');
        $this->settings = new StandaloneConsentSettings($this->sites);
        $this->environment = [$_ENV, $_SERVER];
        foreach (['ANONYMOUS_TRACKING_ENABLED', 'ANONYMOUS_EXCLUDED_PATHS', 'STANDALONE_CONSENT'] as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }

    protected function tearDown(): void
    {
        [$_ENV, $_SERVER] = $this->environment;
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->projectDir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->projectDir);
    }

    public function testDefaultsUseRegisteredWebsiteNamesWithoutReadingDeploymentOrEnvironmentOverrides(): void
    {
        $this->write('config/aggregate.yaml', ['standalone_consent' => ['name' => 'Deployment value', 'respect_gpc' => false]]);
        $_ENV['STANDALONE_CONSENT'] = 'invalid environment override';
        $_SERVER['STANDALONE_CONSENT'] = 'different override';
        self::assertSame(StandaloneConsentSettings::DEFAULTS, StandaloneConsentSettings::validate([]));
        self::assertSame(array_replace(StandaloneConsentSettings::DEFAULTS, ['name' => 'Storefront']), $this->settings->get($this->firstId));
        self::assertSame(array_replace(StandaloneConsentSettings::DEFAULTS, ['name' => 'Portal']), $this->settings->get($this->secondId));
        self::assertDirectoryDoesNotExist($this->projectDir.'/config/tag-manager');
    }

    public function testValidationTrimsTextAndPreservesExplicitPrivacyChoices(): void
    {
        $normalized = StandaloneConsentSettings::validate([
            'name' => '  Store privacy  ', 'privacy_policy_url' => ' https://example.org/privacy?lang=en#choices ',
            'formspree_endpoint' => ' https://formspree.io/f/AbC123 ',
            'categories' => ['analytics', 'ads_v2', 'custom-purpose'], 'respect_gpc' => false,
            'consent_lifetime_days' => 1, 'revision' => '  2026-10  ',
        ]);
        self::assertSame([
            'name' => 'Store privacy', 'privacy_policy_url' => 'https://example.org/privacy?lang=en#choices',
            'formspree_endpoint' => 'https://formspree.io/f/AbC123',
            'categories' => ['analytics', 'ads_v2', 'custom-purpose'], 'respect_gpc' => false,
            'consent_lifetime_days' => 1, 'revision' => '2026-10',
        ], $normalized);
    }

    public function testMaximumBoundsAndEmptyOptionalUrlsAreAccepted(): void
    {
        $normalized = StandaloneConsentSettings::validate([
            'name' => str_repeat('é', 60), 'revision' => str_repeat('x', 64),
            'privacy_policy_url' => '', 'formspree_endpoint' => '', 'consent_lifetime_days' => 365,
            'categories' => ['analytics', ...array_map(static fn (int $number): string => 'purpose'.$number, range(1, 9))],
        ]);
        self::assertSame(120, strlen($normalized['name']));
        self::assertSame(64, strlen($normalized['revision']));
        self::assertCount(10, $normalized['categories']);
        self::assertSame(365, $normalized['consent_lifetime_days']);
    }

    #[DataProvider('invalidSettings')]
    public function testInvalidInputsHaveFieldPathsAndCannotModifySavedYaml(mixed $submitted, string $field): void
    {
        $this->writeSite($this->firstId, ['consent_manager' => ['enabled' => false, 'name' => 'Built-in'], 'operator_note' => 'preserve']);
        $before = file_get_contents($this->sitePath($this->firstId));
        try {
            $this->settings->save($this->firstId, $submitted);
            self::fail('Invalid standalone configuration was accepted.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('standalone_consent'.$field, $exception->getMessage());
            self::assertSame($before, file_get_contents($this->sitePath($this->firstId)));
        }
    }

    public static function invalidSettings(): iterable
    {
        foreach ([null, false, 17, 'settings', ['list']] as $index => $value) {
            yield 'mapping '.$index => [$value, ''];
        }
        yield 'unsupported enabled flag' => [['enabled' => true], '.enabled'];
        yield 'unsupported browser key' => [['storageKey' => 'custom'], '.storageKey'];
        foreach (['name' => 120, 'revision' => 64] as $field => $maximum) {
            foreach ([null, false, 1, [], '', ' ', "line\nname", "bad\x7f", "bad\u{0085}", "\xFF", str_repeat('x', $maximum + 1), str_repeat('é', intdiv($maximum, 2) + 1)] as $index => $value) {
                yield $field.' '.$index => [[$field => $value], '.'.$field];
            }
        }
        foreach (['privacy_policy_url', 'formspree_endpoint'] as $field) {
            foreach ([null, false, 7, [], "https://example.org/\n", "https://example.org/\x7f", "\xFF", 'https://example.org/'.str_repeat('x', 2048)] as $index => $value) {
                yield $field.' type or length '.$index => [[$field => $value], '.'.$field];
            }
        }
        foreach (['http://example.org/privacy', '/privacy', '//example.org/privacy', 'javascript:alert(1)', 'data:text/html,hello', 'https://', 'https:///privacy', 'https://user@example.org/privacy', 'https://user:password@example.org/privacy', 'https://example.org\\@other.org/', 'https://example.org/privacy choices'] as $index => $url) {
            yield 'unsafe policy '.$index => [['privacy_policy_url' => $url], '.privacy_policy_url'];
        }
        foreach (['http://formspree.io/f/abc', 'https://formspree.io/abc', 'https://other.example/f/abc', 'https://formspree.io.evil.example/f/abc', 'https://user@formspree.io/f/abc', 'https://formspree.io:443/f/abc', 'https://formspree.io/f/', 'https://formspree.io/f/abc-def', 'https://formspree.io/f/abc_def', 'https://formspree.io/f/abc/extra', 'https://formspree.io/f/abc?recipient=other', 'https://formspree.io/f/abc#fragment'] as $index => $url) {
            yield 'unsafe endpoint '.$index => [['formspree_endpoint' => $url], '.formspree_endpoint'];
        }
        foreach ([null, 'false', 'true', 0, 1, []] as $index => $value) {
            yield 'strict GPC boolean '.$index => [['respect_gpc' => $value], '.respect_gpc'];
        }
        foreach ([null, true, false, '180', 1.5, 0, -1, 366, []] as $index => $value) {
            yield 'lifetime '.$index => [['consent_lifetime_days' => $value], '.consent_lifetime_days'];
        }
        foreach ([null, false, 'analytics', [], ['analytics' => true], ['functional'], ['analytics', ...array_map(static fn (int $number): string => 'purpose'.$number, range(1, 10))]] as $index => $value) {
            yield 'category list '.$index => [['categories' => $value], '.categories'];
        }
        foreach ([null, true, 7, '', 'Analytics', ' analytics', 'has space', 'none', 'gpc', '__proto__', 'prototype', 'constructor', '1number', str_repeat('x', 33), "marketing\n", 'analytics'] as $index => $category) {
            yield 'category '.$index => [['categories' => ['analytics', $category]], '.categories.1'];
        }
    }

    public function testSaveChangesOnlyStandaloneMappingAndPreservesOtherSitesAndDeployment(): void
    {
        $this->write('config/aggregate.yaml', ['app_host' => 'https://analytics.example', 'deployment_secret' => 'private']);
        $original = ['consent_manager' => ['enabled' => false, 'name' => 'Built-in'], 'tag_manager' => TagManagerSettings::DEFAULTS, 'operator_note' => ['keep' => true]];
        $this->writeSite($this->firstId, $original);
        $this->writeSite($this->secondId, ['standalone_consent' => ['name' => 'Neighbor']]);
        $neighbor = file_get_contents($this->sitePath($this->secondId));
        $deployment = file_get_contents($this->projectDir.'/config/aggregate.yaml');
        $registrations = file_get_contents($this->projectDir.'/config/websites.yaml');
        $this->settings->get($this->firstId);
        $original['other_writer'] = 'latest';
        $this->writeSite($this->firstId, $original);

        $saved = $this->settings->save($this->firstId, ['name' => '  Separate privacy  ', 'formspree_endpoint' => 'https://formspree.io/f/abc123']);

        self::assertSame($original + ['standalone_consent' => $saved], Yaml::parseFile($this->sitePath($this->firstId)));
        self::assertSame($saved, $this->settings->get($this->firstId));
        self::assertSame($original['consent_manager'], $this->sites->consent($this->firstId));
        self::assertSame($neighbor, file_get_contents($this->sitePath($this->secondId)));
        self::assertSame($deployment, file_get_contents($this->projectDir.'/config/aggregate.yaml'));
        self::assertSame($registrations, file_get_contents($this->projectDir.'/config/websites.yaml'));
    }

    public function testNestedEnvironmentReplacesWholeMappingAndOnlyActiveEnvironmentIsSaved(): void
    {
        $original = [
            'standalone_consent' => ['name' => 'Base', 'formspree_endpoint' => 'https://formspree.io/f/base'],
            'consent_manager' => ['enabled' => true, 'name' => 'Built-in base'],
            'environments' => [
                'test' => ['standalone_consent' => ['name' => 'Active'], 'keep' => 'active value'],
                'prod' => ['standalone_consent' => ['name' => 'Production'], 'keep' => 'production value'],
            ],
        ];
        $this->writeSite($this->firstId, $original);
        self::assertSame('', $this->settings->get($this->firstId)['formspree_endpoint']);
        self::assertSame('Active', $this->settings->get($this->firstId)['name']);
        $normalized = $this->settings->save($this->firstId, ['name' => 'Replacement', 'categories' => ['analytics']]);
        $expected = $original;
        $expected['environments']['test']['standalone_consent'] = $normalized;
        self::assertSame($expected, Yaml::parseFile($this->sitePath($this->firstId)));
    }

    public function testEnvironmentFileReplacesMainSiteConfigurationAndIsOnlyWriteTarget(): void
    {
        $this->writeSite($this->firstId, ['standalone_consent' => ['name' => 'Main', 'formspree_endpoint' => 'https://formspree.io/f/base']]);
        $this->writeSite($this->firstId, ['consent_manager' => ['enabled' => false, 'name' => 'Env built-in'], 'keep' => 'env value'], '_test');
        $before = file_get_contents($this->sitePath($this->firstId));
        self::assertSame('Storefront', $this->settings->get($this->firstId)['name']);
        self::assertSame('', $this->settings->get($this->firstId)['formspree_endpoint']);
        $saved = $this->settings->save($this->firstId, ['name' => 'Env standalone']);
        self::assertSame(['consent_manager' => ['enabled' => false, 'name' => 'Env built-in'], 'keep' => 'env value', 'standalone_consent' => $saved], Yaml::parseFile($this->sitePath($this->firstId, '_test')));
        self::assertSame($before, file_get_contents($this->sitePath($this->firstId)));
    }

    public function testExportsOnlyStandaloneSettingsAndUsesSiteScopedBrowserStorage(): void
    {
        $this->writeSite($this->firstId, ['private_key' => 'do-not-export', 'consent_manager' => ['enabled' => false, 'name' => 'Other CMP'], 'standalone_consent' => ['name' => 'Independent']]);
        self::assertSame(['standalone_consent' => $this->settings->get($this->firstId)], Yaml::parse($this->settings->exportYaml($this->firstId)));
        self::assertSame([
            'name' => 'Independent', 'privacyPolicyUrl' => '', 'formspreeEndpoint' => '',
            'categories' => ['analytics', 'functional', 'marketing'], 'respectGpc' => true,
            'consentLifetimeDays' => 180, 'revision' => '1', 'storageKey' => 'micro_consent_v2:'.$this->firstId,
        ], $this->settings->browserConfig($this->firstId));
        self::assertNotSame($this->settings->browserConfig($this->firstId)['storageKey'], $this->settings->browserConfig($this->secondId)['storageKey']);
    }

    public function testMalformedStandaloneMappingDoesNotAffectBuiltInSettingsOrIngestion(): void
    {
        $this->writeSite($this->firstId, [
            'standalone_consent' => ['respect_gpc' => 'false'],
            'consent_manager' => ['enabled' => true, 'name' => 'Working built-in'],
            'tag_manager' => TagManagerSettings::DEFAULTS,
        ]);
        $before = file_get_contents($this->sitePath($this->firstId));
        foreach ([fn () => $this->settings->get($this->firstId), fn () => $this->settings->save($this->firstId, []), fn () => $this->settings->exportYaml($this->firstId), fn () => $this->settings->browserConfig($this->firstId)] as $operation) {
            $this->assertRejected($operation, 'standalone_consent.respect_gpc');
        }
        self::assertSame($before, file_get_contents($this->sitePath($this->firstId)));
        self::assertSame(['enabled' => true, 'name' => 'Working built-in'], $this->sites->consent($this->firstId));
        $deployment = new AggregateConfigLoader($this->projectDir, 'test');
        self::assertSame(TagManagerSettings::DEFAULTS, (new TagManagerSettings($deployment, $this->sites))->all($this->firstId));
        self::assertFalse($deployment->hasLoadError());
        self::assertNotEmpty((new CustomDataSettings($deployment))->toBrowserConfig());
        $this->sites->saveConsent($this->firstId, ['enabled' => false, 'name' => 'Built-in edited']);
        self::assertSame(['respect_gpc' => 'false'], Yaml::parseFile($this->sitePath($this->firstId))['standalone_consent']);
        self::assertSame('Portal', $this->settings->get($this->secondId)['name']);
    }

    public function testStandaloneDoesNotValidateOrReplaceMalformedUnrelatedBuiltInMappings(): void
    {
        $this->writeSite($this->firstId, ['consent_manager' => null, 'tag_manager' => ['enabled' => 'broken']]);
        self::assertSame('Storefront', $this->settings->get($this->firstId)['name']);
        $saved = $this->settings->save($this->firstId, ['name' => 'Standalone']);
        self::assertSame(['consent_manager' => null, 'tag_manager' => ['enabled' => 'broken'], 'standalone_consent' => $saved], Yaml::parseFile($this->sitePath($this->firstId)));
    }

    public function testUnknownWebsiteCannotCreateConfigurationDirectory(): void
    {
        foreach ([str_repeat('a', 24), '../aggregate'] as $id) {
            $this->assertRejected(fn () => $this->settings->save($id, []), 'registered website');
        }
        self::assertDirectoryDoesNotExist($this->projectDir.'/config/tag-manager');
    }

    private function assertRejected(callable $operation, string $message): void
    {
        try {
            $operation();
            self::fail('Expected the invalid configuration to be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }
    }

    private function writeSite(string $id, array $settings, string $suffix = ''): void
    {
        $this->write('config/tag-manager/sites/'.$id.$suffix.'.yaml', $settings);
    }

    private function write(string $relative, array $settings): void
    {
        $path = $this->projectDir.'/'.$relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        file_put_contents($path, Yaml::dump($settings, 12, 2));
    }

    private function sitePath(string $id, string $suffix = ''): string
    {
        return $this->projectDir.'/config/tag-manager/sites/'.$id.$suffix.'.yaml';
    }
}
