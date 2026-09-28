<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AggregateConfigLoader;
use App\Service\CustomDataSettings;
use App\Service\InternalTrafficSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class CustomDataSettingsTest extends TestCase
{
    private string $projectDir;
    private array $environment;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/aggregate-custom-data-'.bin2hex(random_bytes(8));
        mkdir($this->projectDir.'/config', 0700, true);
        $this->environment = [$_ENV, $_SERVER];
        foreach ([...array_keys(InternalTrafficSettings::DEFAULTS), 'anonymous_tracking_enabled', 'anonymous_excluded_paths'] as $key) {
            unset($_ENV[strtoupper($key)], $_SERVER[strtoupper($key)]);
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

    public function testAllStandardUtmPropertiesRequireConsentByDefault(): void
    {
        $settings = $this->settings([]);
        $utm = ['utm_source' => 'newsletter', 'utm_medium' => 'email', 'utm_campaign' => 'autumn', 'utm_term' => 'analytics', 'utm_content' => 'banner', 'utm_id' => 'launch'];

        self::assertNull($settings->filterEventData($utm, false));
        self::assertSame($utm, $settings->filterEventData($utm, true));
        self::assertSame([], $settings->toBrowserConfig()['consentFreeProperties']);
        self::assertSame(array_combine(array_keys($utm), array_keys($utm)), $settings->toBrowserConfig()['queryParameters']);
        self::assertSame(array_combine(array_keys($utm), array_keys($utm)), $settings->reportingColumns());
    }

    public function testPageSequenceNeedsExplicitDeploymentOptInInBothModes(): void
    {
        $_ENV['PAGE_SEQUENCE_ENABLED'] = 'true';
        $settings = $this->settings([
            'custom_data_properties' => ['page_sequence' => ['type' => 'integer', 'consent_required' => false, 'numeric_column' => 'page_depth']],
            'query_parameter_mappings' => [],
        ]);

        self::assertFalse($settings->toArray()['page_sequence_enabled']);
        self::assertFalse($settings->toBrowserConfig()['pageSequenceEnabled']);
        self::assertSame([], $settings->toBrowserConfig()['consentFreeProperties']);
        self::assertSame(['page_depth' => ['property' => 'page_sequence', 'type' => 'integer']], $settings->numericReportingColumns());
        foreach ([false, true] as $consent) {
            self::assertNull($settings->filterEventData(['page_sequence' => 2], $consent));
        }

        $settings->save([...$settings->toArray(), 'page_sequence_enabled' => true]);
        self::assertTrue($settings->toBrowserConfig()['pageSequenceEnabled']);
        self::assertSame([], $settings->toBrowserConfig()['consentFreeProperties']);
        self::assertArrayNotHasKey('propertyTypes', $settings->toBrowserConfig());
        foreach ([false, true] as $consent) {
            self::assertSame(['page_sequence' => 2], $settings->filterEventData(['page_sequence' => 2], $consent));
        }
    }

    #[DataProvider('pageSequenceValues')]
    public function testEnabledPageSequenceIsBoundedWithoutRequiringAModeledProperty(mixed $value, ?int $expected): void
    {
        $settings = $this->settings(['page_sequence_enabled' => true, 'custom_data_properties' => [], 'query_parameter_mappings' => []]);
        foreach ([false, true] as $consent) {
            self::assertSame($expected === null ? null : ['page_sequence' => $expected], $settings->filterEventData(['page_sequence' => $value], $consent));
        }
    }

    public static function pageSequenceValues(): iterable
    {
        yield 'first page' => [1, 1];
        yield 'second page decimal representation' => [2.0, 2];
        yield 'overflow bucket' => [CustomDataSettings::PAGE_SEQUENCE_MAXIMUM, CustomDataSettings::PAGE_SEQUENCE_MAXIMUM];
        foreach ([null, true, false, '2', 0, -1, 2.5, CustomDataSettings::PAGE_SEQUENCE_MAXIMUM + 1, 9_007_199_254_740_991, INF, NAN, [], ['private']] as $index => $invalid) {
            yield 'invalid value '.$index => [$invalid, null];
        }
    }

    public function testBrowserCounterUsesEffectiveCollectionControlsAndExclusions(): void
    {
        $settings = $this->settings([
            'page_sequence_enabled' => true,
            'anonymous_tracking_enabled' => true,
            'anonymous_excluded_paths' => ['/saved/**'],
        ]);
        $_ENV['ANONYMOUS_EXCLUDED_PATHS'] = '/account/**,/patients/*';
        self::assertTrue($settings->toBrowserConfig()['pageSequenceEnabled']);
        self::assertSame(['/account/**', '/patients/*'], $settings->toBrowserConfig()['pageSequenceExcludedPaths']);

        $_ENV['ANONYMOUS_TRACKING_ENABLED'] = 'false';
        self::assertFalse($settings->toBrowserConfig()['pageSequenceEnabled']);
        self::assertArrayNotHasKey('pageSequenceExcludedPaths', $settings->toBrowserConfig());
        self::assertTrue($settings->toArray()['page_sequence_enabled']);
    }

    public function testConcurrentMarkerChangeCannotCreateAPageSequenceCollisionWhenSaving(): void
    {
        $settings = $this->settings([]);
        $model = $settings->toArray();
        $model['page_sequence_enabled'] = true;
        file_put_contents($this->projectDir.'/config/aggregate.yaml', "internal_traffic_name: page_sequence\n");
        $before = file_get_contents($this->projectDir.'/config/aggregate.yaml');
        try {
            $settings->save($model);
            self::fail('A concurrently configured marker collision was accepted.');
        } catch (\InvalidArgumentException) {
            self::assertSame($before, file_get_contents($this->projectDir.'/config/aggregate.yaml'));
        }
    }

    #[DataProvider('invalidPageSequenceConfiguration')]
    public function testPageSequenceConfigurationFailsClosed(array $values): void
    {
        $settings = $this->settings($values);
        $this->expectException(\InvalidArgumentException::class);
        $settings->filterEventData(null, true);
    }

    public static function invalidPageSequenceConfiguration(): iterable
    {
        foreach ([null, 'true', 'false', 1, 0, []] as $index => $invalid) {
            yield 'strict boolean '.$index => [['page_sequence_enabled' => $invalid]];
        }
        yield 'marker collision' => [['page_sequence_enabled' => true, 'internal_traffic_name' => 'page_sequence']];
        yield 'property without integer declaration' => [['page_sequence_enabled' => true, 'custom_data_properties' => ['page_sequence' => ['consent_required' => false]]]];
        yield 'property with conflicting consent policy' => [['page_sequence_enabled' => true, 'custom_data_properties' => ['page_sequence' => ['type' => 'integer', 'consent_required' => true]]]];
        yield 'query mapping' => [[
            'page_sequence_enabled' => true,
            'custom_data_properties' => ['page_sequence' => ['type' => 'integer', 'consent_required' => false]],
            'query_parameter_mappings' => ['depth' => 'page_sequence'],
        ]];
    }

    #[DataProvider('legacyPageSequenceDefinitions')]
    public function testLegacyPageSequenceDefinitionsRemainReportingOnlyUntilEnabled(array $definition): void
    {
        $settings = $this->settings([
            'custom_data_properties' => ['page_sequence' => [...$definition, 'column' => 'historical_depth']],
            'query_parameter_mappings' => [],
        ]);
        $model = $settings->toArray();
        $settings->save($model);
        self::assertSame(['historical_depth' => 'page_sequence'], $settings->reportingColumns());
        self::assertFalse($settings->toBrowserConfig()['pageSequenceEnabled']);
        self::assertSame([], $settings->toBrowserConfig()['consentFreeProperties']);
        self::assertArrayNotHasKey('propertyTypes', $settings->toBrowserConfig());
        foreach ([false, true] as $consent) {
            self::assertNull($settings->filterEventData(['page_sequence' => 2], $consent));
            self::assertNull($settings->filterEventData(['page_sequence' => 'historical-value'], $consent));
        }
        $before = file_get_contents($this->projectDir.'/config/aggregate.yaml');
        try {
            $settings->save([...$model, 'page_sequence_enabled' => true]);
            self::fail('An incompatible historical definition enabled the counter.');
        } catch (\InvalidArgumentException) {
            self::assertSame($before, file_get_contents($this->projectDir.'/config/aggregate.yaml'));
        }
    }

    public static function legacyPageSequenceDefinitions(): iterable
    {
        yield 'unspecified scalar' => [[]];
        yield 'string' => [['type' => 'string', 'consent_required' => false]];
        yield 'boolean' => [['type' => 'boolean', 'consent_required' => false]];
        yield 'scalar' => [['type' => 'scalar', 'consent_required' => false]];
        yield 'integer requiring consent' => [['type' => 'integer', 'consent_required' => true]];
    }

    public function testRenamingALegacyPageSequenceMarkerPreservesItsHistoricalReportingDefinition(): void
    {
        $this->settings([
            'internal_traffic_name' => 'page_sequence',
            'page_sequence_enabled' => false,
            'custom_data_properties' => ['page_sequence' => ['type' => 'boolean', 'consent_required' => true, 'column' => 'historical_staff']],
            'query_parameter_mappings' => [],
        ]);
        $config = new AggregateConfigLoader($this->projectDir, 'test');
        $settings = new CustomDataSettings($config);
        self::assertSame(['historical_staff' => 'page_sequence'], $settings->reportingColumns());

        (new InternalTrafficSettings($config))->saveMarker([...InternalTrafficSettings::DEFAULTS, 'internal_traffic_name' => 'companyStaff']);

        self::assertSame(['historical_staff' => 'page_sequence'], $settings->reportingColumns());
        self::assertSame('boolean', $settings->properties()['page_sequence']['type']);
        self::assertFalse($settings->toBrowserConfig()['pageSequenceEnabled']);
        self::assertSame([], $settings->toBrowserConfig()['consentFreeProperties']);
        self::assertNull($settings->filterEventData(['page_sequence' => true], true));
    }

    public function testExplicitMediumWhitelistAppliesIndependentlyToBrowserAndServer(): void
    {
        $settings = $this->settings([
            'custom_data_properties' => [
                'utm_medium' => ['consent_required' => false, 'column' => 'marketing_channel'],
                'utm_source' => [],
                'utm_campaign' => ['consent_required' => true],
            ],
            'query_parameter_mappings' => ['utm_medium' => 'utm_medium', 'channel' => 'utm_medium', 'utm_source' => 'utm_source'],
        ]);
        $submitted = ['utm_medium' => "email\0", 'utm_source' => 'newsletter', 'utm_campaign' => 'autumn', 'email' => 'private@example.com'];

        self::assertSame(['utm_medium' => 'email'], $settings->filterEventData($submitted, false));
        self::assertSame(array_replace($submitted, ['utm_medium' => 'email']), $settings->filterEventData($submitted, true));
        self::assertSame(['utm_medium'], $settings->toBrowserConfig()['consentFreeProperties']);
        self::assertSame(['utm_medium' => 'utm_medium', 'channel' => 'utm_medium', 'utm_source' => 'utm_source'], $settings->toBrowserConfig()['queryParameters']);
        self::assertSame(['marketing_channel' => 'utm_medium'], $settings->reportingColumns());
    }

    public function testWhitelistedValuesAreFlatScalarsWithBoundedStringsAndExactPropertyNames(): void
    {
        $keys = ['count', 'amount', 'enabled', 'optional', 'campaign.kind', 'message', 'nested'];
        $settings = $this->settings([
            'custom_data_properties' => array_fill_keys($keys, ['consent_required' => false]),
            'query_parameter_mappings' => [],
        ]);

        self::assertSame([
            'count' => 0,
            'amount' => 12.5,
            'enabled' => false,
            'optional' => null,
            'campaign.kind' => 'launch',
            'message' => str_repeat('x', 500),
        ], $settings->filterEventData([
            'count' => 0,
            'amount' => 12.5,
            'enabled' => false,
            'optional' => null,
            'campaign.kind' => 'launch',
            'Campaign.kind' => 'case-mismatch',
            'message' => str_repeat('x', 550),
            'nested' => ['private' => 'identifier'],
            'unknown' => 'private',
            0 => 'numeric-key',
        ], false));
        self::assertNull($settings->filterEventData('not-an-object', false));
        self::assertNull($settings->filterEventData(['nested' => ['private']], false));
    }

    public function testAbsentMappingsOnlyDefaultDefinedUtmPropertiesAndExplicitEmptyDisablesThem(): void
    {
        $settings = $this->settings(['custom_data_properties' => ['plan' => [], 'utm_medium' => []]]);
        self::assertSame(['utm_medium' => 'utm_medium'], $settings->toBrowserConfig()['queryParameters']);

        $settings = $this->settings(['custom_data_properties' => ['plan' => []]]);
        self::assertSame([], $settings->toBrowserConfig()['queryParameters']);

        $settings = $this->settings(['custom_data_properties' => []]);
        self::assertSame([], $settings->properties());
        self::assertSame([], $settings->toBrowserConfig()['queryParameters']);

        $settings = $this->settings(['query_parameter_mappings' => []]);
        self::assertNotEmpty($settings->properties());
        self::assertSame([], $settings->toBrowserConfig()['queryParameters']);
    }

    public function testOptionalTypesKeepLegacyModelsAndTextAliasesUnchanged(): void
    {
        $settings = $this->settings([
            'custom_data_properties' => [
                'legacy' => ['column' => 'legacy_text'],
                'quantity' => ['type' => 'integer', 'column' => 'quantity_text', 'numeric_column' => 'quantity_value', 'consent_required' => false],
                'revenue' => ['type' => 'double', 'numeric_column' => 'revenue_value'],
                'orgInternalTraffic' => ['type' => 'boolean', 'column' => 'staff'],
                '__Host-formerStaff' => ['type' => 'boolean', 'column' => 'former_staff'],
            ],
            'query_parameter_mappings' => ['qty' => 'quantity'],
        ]);

        self::assertSame(['description' => '', 'consent_required' => true, 'column' => 'legacy_text'], $settings->properties()['legacy']);
        self::assertSame(['legacy_text' => 'legacy', 'quantity_text' => 'quantity', 'staff' => 'orgInternalTraffic', 'former_staff' => '__Host-formerStaff'], $settings->reportingColumns());
        self::assertSame([
            'quantity_value' => ['property' => 'quantity', 'type' => 'integer'],
            'revenue_value' => ['property' => 'revenue', 'type' => 'double'],
        ], $settings->numericReportingColumns());
        self::assertSame([
            'queryParameters' => ['qty' => 'quantity'],
            'consentFreeProperties' => ['quantity'],
            'pageSequenceEnabled' => false,
            'propertyTypes' => ['quantity' => 'integer', 'revenue' => 'double'],
        ], $settings->toBrowserConfig());
        self::assertSame($settings->toArray(), Yaml::parse($settings->exportYaml()));
        self::assertArrayNotHasKey('propertyTypes', $this->settings([])->toBrowserConfig());
    }

    #[DataProvider('typedValues')]
    public function testExplicitTypesAcceptOnlyMatchingJsonScalars(string $type, mixed $input, mixed $expected, bool $accepted): void
    {
        $settings = $this->settings([
            'custom_data_properties' => ['typed' => ['type' => $type, 'consent_required' => false]],
            'query_parameter_mappings' => [],
        ]);
        foreach ([false, true] as $consented) {
            self::assertSame($accepted ? ['typed' => $expected] : null, $settings->filterEventData(['typed' => $input], $consented));
        }
    }

    public static function typedValues(): iterable
    {
        foreach (CustomDataSettings::TYPES as $type) {
            yield $type.' accepts null' => [$type, null, null, true];
            yield $type.' rejects arrays' => [$type, [1], null, false];
            yield $type.' rejects nonfinite' => [$type, INF, null, false];
        }
        yield 'untyped scalar keeps numeric text' => ['scalar', '12.5', '12.5', true];
        yield 'string stays bounded' => ['string', str_repeat('é', 251)."\0", str_repeat('é', 250), true];
        yield 'string rejects number' => ['string', 12, null, false];
        yield 'string rejects boolean' => ['string', true, null, false];
        yield 'boolean false' => ['boolean', false, false, true];
        yield 'boolean true' => ['boolean', true, true, true];
        yield 'boolean rejects truthy strings' => ['boolean', 'false', null, false];
        yield 'boolean rejects integer flags' => ['boolean', 1, null, false];
        yield 'integer zero' => ['integer', 0, 0, true];
        yield 'integer negative' => ['integer', -42, -42, true];
        yield 'integer integral float' => ['integer', 42.0, 42, true];
        yield 'integer maximum safe' => ['integer', CustomDataSettings::MAXIMUM_SAFE_INTEGER, CustomDataSettings::MAXIMUM_SAFE_INTEGER, true];
        yield 'integer minimum safe' => ['integer', -CustomDataSettings::MAXIMUM_SAFE_INTEGER, -CustomDataSettings::MAXIMUM_SAFE_INTEGER, true];
        yield 'integer rejects unsafe positive' => ['integer', 9_007_199_254_740_992, null, false];
        yield 'integer rejects unsafe negative' => ['integer', -9_007_199_254_740_992, null, false];
        yield 'integer rejects fraction' => ['integer', 12.5, null, false];
        yield 'integer rejects query text' => ['integer', '12', null, false];
        yield 'integer rejects boolean' => ['integer', true, null, false];
        foreach (['float', 'double'] as $type) {
            yield $type.' fraction' => [$type, -12.5, -12.5, true];
            yield $type.' integer' => [$type, 12, 12, true];
            yield $type.' exponent' => [$type, 1.25e12, 1.25e12, true];
            yield $type.' rejects numeric text' => [$type, '12.5', null, false];
            yield $type.' rejects boolean' => [$type, false, null, false];
            yield $type.' rejects nan' => [$type, NAN, null, false];
        }
    }

    public function testPropertyTypesNeverGrantConsentOrAuthorizeTheOrganizationMarker(): void
    {
        $settings = $this->settings([
            'custom_data_properties' => [
                'quantity' => ['type' => 'integer', 'consent_required' => false],
                'revenue' => ['type' => 'double'],
                'orgInternalTraffic' => ['type' => 'boolean', 'consent_required' => false],
            ],
            'query_parameter_mappings' => [],
        ]);
        $submitted = ['quantity' => 2, 'revenue' => 12.5, 'orgInternalTraffic' => true, 'unknown' => 12];

        self::assertSame(['quantity' => 2], $settings->filterEventData($submitted, false));
        self::assertSame(['quantity' => 2, 'revenue' => 12.5, 'unknown' => 12], $settings->filterEventData($submitted, true));
        self::assertSame(['quantity' => 'integer', 'revenue' => 'double', 'orgInternalTraffic' => 'boolean'], $settings->propertyTypes());
    }

    public function testPreviewModelUsesSharedConsentTypeAndActiveMarkerRulesWithoutSaving(): void
    {
        $settings = $this->settings([
            'internal_traffic_name' => 'companyStaff',
            'custom_data_properties' => ['saved_only' => ['consent_required' => false]],
            'query_parameter_mappings' => [],
        ]);
        $before = file_get_contents($this->projectDir.'/config/aggregate.yaml');
        $model = [
            'custom_data_properties' => [
                'quantity' => ['type' => 'integer', 'consent_required' => false],
                'revenue' => ['type' => 'double'],
                'companyStaff' => ['type' => 'boolean', 'consent_required' => false],
            ],
            'query_parameter_mappings' => [],
        ];
        $data = ['quantity' => 2.0, 'revenue' => 12.5, 'companyStaff' => true, 'saved_only' => 'private'];

        self::assertSame(['quantity' => 2], $settings->filterEventDataForModel($data, false, $model));
        self::assertSame(['quantity' => 2, 'revenue' => 12.5, 'saved_only' => 'private'], $settings->filterEventDataForModel($data, true, $model));
        self::assertNull($settings->filterEventDataForModel(['quantity' => '2'], true, $model));
        self::assertSame($before, file_get_contents($this->projectDir.'/config/aggregate.yaml'));
        self::assertSame(['saved_only'], array_keys($settings->properties()));

        $model['custom_data_properties']['quantity']['type'] = 'number';
        $this->expectException(\InvalidArgumentException::class);
        $settings->filterEventDataForModel(null, false, $model);
    }

    #[DataProvider('markerNames')]
    public function testConfiguredMarkerCanHaveAReportingColumnButCannotBeSubmittedAsCustomData(string $markerName): void
    {
        $settings = $this->settings([
            'internal_traffic_name' => $markerName,
            'custom_data_properties' => [$markerName => ['consent_required' => true, 'column' => 'organization_traffic'], 'plan' => ['consent_required' => false]],
            'query_parameter_mappings' => [],
        ]);

        self::assertTrue($settings->properties()[$markerName]['consent_required']);
        self::assertSame(['organization_traffic' => $markerName], $settings->reportingColumns());
        self::assertSame(['plan'], $settings->toBrowserConfig()['consentFreeProperties']);
        foreach ([false, true] as $enhancedConsent) {
            self::assertSame(['plan' => 'pro'], $settings->filterEventData([$markerName => true, 'plan' => 'pro'], $enhancedConsent));
        }

        $this->expectException(\InvalidArgumentException::class);
        $settings->save([
            'custom_data_properties' => [$markerName => ['column' => 'organization_traffic']],
            'query_parameter_mappings' => ['staff' => $markerName],
        ]);
    }

    public static function markerNames(): iterable
    {
        yield 'default' => ['orgInternalTraffic'];
        yield 'renamed' => ['companyStaff'];
        yield 'cookie prefix outside normal property format' => ['__Host-companyStaff'];
        yield 'long legacy cookie name' => [str_repeat('s', 128)];
    }

    public function testReservedJavascriptPropertyNamesNeverReachStoredCustomData(): void
    {
        $settings = $this->settings([]);

        self::assertSame(['plan' => 'pro'], $settings->filterEventData([
            'constructor' => 'private', 'prototype' => 'private', '__proto__' => 'private', 'plan' => 'pro',
        ], true));
    }

    public function testLegacyMarkerNamedAfterAUtmParameterDoesNotBreakDefaultConfiguration(): void
    {
        $settings = $this->settings(['internal_traffic_name' => 'utm_medium']);

        self::assertArrayNotHasKey('utm_medium', $settings->toBrowserConfig()['queryParameters']);
        self::assertSame('utm_source', $settings->toBrowserConfig()['queryParameters']['utm_source']);
        self::assertNull($settings->filterEventData(['utm_medium' => 'spoofed-marker'], true));
    }

    public function testNonstandardHistoricalMarkerKeepsItsReportingColumnAfterRenameWithoutEnablingCollection(): void
    {
        $this->settings(['internal_traffic_name' => '__Host-companyStaff']);
        $config = new AggregateConfigLoader($this->projectDir, 'test');
        $settings = new CustomDataSettings($config);
        $settings->save([
            'custom_data_properties' => ['__Host-companyStaff' => ['column' => 'historical_staff']],
            'query_parameter_mappings' => [],
        ]);

        (new InternalTrafficSettings($config))->saveMarker(array_replace(
            InternalTrafficSettings::DEFAULTS,
            ['internal_traffic_name' => 'staff'],
        ));

        self::assertSame(['historical_staff' => '__Host-companyStaff'], $settings->reportingColumns());
        self::assertTrue($settings->properties()['__Host-companyStaff']['consent_required']);
        self::assertSame([], $settings->toBrowserConfig()['consentFreeProperties']);
        self::assertSame([], $settings->toBrowserConfig()['queryParameters']);
        foreach ([false, true] as $enhancedConsent) {
            self::assertNull($settings->filterEventData([
                '__Host-companyStaff' => 'untrusted-historical-marker',
                'staff' => 'untrusted-current-marker',
            ], $enhancedConsent));
        }

        $before = file_get_contents($this->projectDir.'/config/aggregate.yaml');
        try {
            $settings->save([
                'custom_data_properties' => $settings->properties(),
                'query_parameter_mappings' => ['old_staff' => '__Host-companyStaff'],
            ]);
            self::fail('A historical reporting-only key was accepted for URL capture.');
        } catch (\InvalidArgumentException) {
            self::assertSame($before, file_get_contents($this->projectDir.'/config/aggregate.yaml'));
        }
    }

    public function testOrdinaryFormerMarkerStillRequiresConsentAfterRename(): void
    {
        $this->settings(['internal_traffic_name' => 'companyStaff']);
        $config = new AggregateConfigLoader($this->projectDir, 'test');
        $settings = new CustomDataSettings($config);
        $settings->save([
            'custom_data_properties' => ['companyStaff' => ['column' => 'historical_staff']],
            'query_parameter_mappings' => [],
        ]);
        self::assertTrue($settings->properties()['companyStaff']['consent_required']);
        self::assertNull($settings->filterEventData(['companyStaff' => true], true));

        (new InternalTrafficSettings($config))->saveMarker(array_replace(
            InternalTrafficSettings::DEFAULTS,
            ['internal_traffic_name' => 'staff'],
        ));

        self::assertTrue($settings->properties()['companyStaff']['consent_required']);
        self::assertSame(['historical_staff' => 'companyStaff'], $settings->reportingColumns());
        self::assertSame([], $settings->toBrowserConfig()['consentFreeProperties']);
        self::assertNull($settings->filterEventData(['companyStaff' => 'private', 'staff' => true], false));
        self::assertSame(['companyStaff' => 'private'], $settings->filterEventData(['companyStaff' => 'private', 'staff' => true], true));
        self::assertTrue(Yaml::parseFile($this->projectDir.'/config/aggregate.yaml')['custom_data_properties']['companyStaff']['consent_required']);
    }

    public function testNestedEnvironmentSavePreservesUnrelatedConfigurationAndExportContainsOnlyTheContract(): void
    {
        $_ENV['INTERNAL_TRAFFIC_NAME'] = 'managedStaff';
        $otherEnvironment = ['app_host' => 'https://prod.example', 'custom_data_properties' => ['old' => []]];
        $settings = $this->settings([
            'internal_traffic_name' => 'globalStaff',
            'admin_token' => 'private-admin-token',
            'environments' => [
                'test' => ['app_host' => 'https://test.example', 'internal_traffic_share_token' => str_repeat('s', 64)],
                'prod' => $otherEnvironment,
            ],
        ]);
        $model = [
            'custom_data_properties' => [
                'utm_medium' => ['description' => 'Marketing channel', 'consent_required' => false, 'column' => 'marketing_channel'],
                'managedStaff' => ['description' => 'Organization traffic', 'consent_required' => false, 'column' => 'staff_traffic'],
            ],
            'query_parameter_mappings' => ['utm_medium' => 'utm_medium', 'channel' => 'utm_medium'],
            'page_sequence_enabled' => true,
        ];

        $settings->save($model);

        $written = Yaml::parseFile($this->projectDir.'/config/aggregate.yaml');
        self::assertSame('globalStaff', $written['internal_traffic_name']);
        self::assertSame('private-admin-token', $written['admin_token']);
        self::assertSame($otherEnvironment, $written['environments']['prod']);
        self::assertSame('https://test.example', $written['environments']['test']['app_host']);
        self::assertSame(str_repeat('s', 64), $written['environments']['test']['internal_traffic_share_token']);
        self::assertSame($model, (new CustomDataSettings(new AggregateConfigLoader($this->projectDir, 'test')))->toArray());
        $export = $settings->exportYaml();
        self::assertSame($model, Yaml::parse($export));
        self::assertStringNotContainsString('private-admin-token', $export);
        self::assertStringNotContainsString(str_repeat('s', 64), $export);
        self::assertStringNotContainsString('app_host', $export);
    }

    public function testEnvironmentSpecificFileReceivesTheWholeModelWithoutChangingTheMainFile(): void
    {
        $main = ['app_host' => 'https://main.example', 'custom_data_properties' => ['main' => []]];
        $settings = $this->settings($main);
        file_put_contents($this->projectDir.'/config/aggregate_test.yaml', "app_host: https://test.example\n");

        $settings->save(['custom_data_properties' => ['plan' => []], 'query_parameter_mappings' => []]);

        self::assertSame($main, Yaml::parseFile($this->projectDir.'/config/aggregate.yaml'));
        $written = Yaml::parseFile($this->projectDir.'/config/aggregate_test.yaml');
        self::assertSame('https://test.example', $written['app_host']);
        self::assertSame(['plan' => ['description' => '', 'consent_required' => true, 'column' => '']], $written['custom_data_properties']);
        self::assertSame([], $written['query_parameter_mappings']);
    }

    #[DataProvider('invalidModels')]
    public function testInvalidModelsAreRejectedWithoutChangingYaml(array $model): void
    {
        $settings = $this->settings(['app_host' => 'https://preserve.example']);
        $before = file_get_contents($this->projectDir.'/config/aggregate.yaml');

        try {
            $settings->save($model);
            self::fail('Invalid custom data model was accepted.');
        } catch (\InvalidArgumentException) {
            self::assertSame($before, file_get_contents($this->projectDir.'/config/aggregate.yaml'));
        }
    }

    public static function invalidModels(): iterable
    {
        $base = ['custom_data_properties' => ['plan' => []], 'query_parameter_mappings' => []];
        yield 'missing model key' => [['custom_data_properties' => []]];
        yield 'unsupported model key' => [$base + ['admin_token' => 'cannot-save']];
        foreach (['custom_data_properties', 'query_parameter_mappings'] as $field) {
            foreach ([null, false, 'plan', 123] as $index => $invalid) {
                yield $field.' invalid type '.$index => [array_replace($base, [$field => $invalid])];
            }
        }
        foreach (['', '0', 'has space', "key'", 'constructor', 'prototype', '__proto__', str_repeat('a', 65)] as $index => $key) {
            yield 'invalid property key '.$index => [array_replace($base, ['custom_data_properties' => [$key => []]])];
        }
        foreach ([null, false, 'plan', ['unknown' => true], ['consent_required' => 'false'], ['consent_required' => 0], ['consent_required' => null], ['description' => null], ['description' => []], ['description' => str_repeat('x', 1001)], ['column' => null]] as $index => $definition) {
            yield 'invalid definition '.$index => [array_replace($base, ['custom_data_properties' => ['plan' => $definition]])];
        }
        foreach ([null, false, 1, [], '', 'number', 'Integer', 'decimal'] as $index => $type) {
            yield 'invalid property type '.$index => [array_replace($base, ['custom_data_properties' => ['plan' => ['type' => $type]]])];
        }
        foreach ([null, 1, [], 'created_at', 'UpperCase', 'unsafe-column', str_repeat('a', 64)] as $index => $column) {
            yield 'invalid numeric column '.$index => [array_replace($base, ['custom_data_properties' => ['plan' => ['type' => 'double', 'numeric_column' => $column]]])];
        }
        foreach (['scalar', 'string', 'boolean'] as $type) {
            yield 'numeric alias requires numeric type '.$type => [array_replace($base, ['custom_data_properties' => ['plan' => ['type' => $type, 'numeric_column' => 'numeric_value']]])];
        }
        yield 'numeric alias requires explicit type' => [array_replace($base, ['custom_data_properties' => ['plan' => ['numeric_column' => 'numeric_value']]])];
        yield 'text and numeric alias collide' => [array_replace($base, ['custom_data_properties' => ['plan' => ['type' => 'double', 'column' => 'plan_value', 'numeric_column' => 'plan_value']]])];
        yield 'numeric alias collides across properties' => [array_replace($base, ['custom_data_properties' => ['plan' => ['type' => 'integer', 'numeric_column' => 'metric'], 'revenue' => ['column' => 'metric']]])];
        yield 'numeric aliases must be unique' => [array_replace($base, ['custom_data_properties' => ['plan' => ['type' => 'integer', 'numeric_column' => 'metric'], 'revenue' => ['type' => 'float', 'numeric_column' => 'metric']]])];
        yield 'marker cannot acquire string type' => [array_replace($base, ['custom_data_properties' => ['orgInternalTraffic' => ['type' => 'string']]])];
        yield 'marker cannot acquire numeric alias' => [array_replace($base, ['custom_data_properties' => ['orgInternalTraffic' => ['type' => 'integer', 'numeric_column' => 'metric']]])];
        yield 'legacy marker cannot acquire numeric alias' => [array_replace($base, ['custom_data_properties' => ['__Host-formerStaff' => ['type' => 'integer', 'column' => 'old_staff', 'numeric_column' => 'metric']]])];
        foreach (['id', 'event_hour', 'event_count', 'visitor_id', 'custom_data', 'Plan', 'a.b', 'select;drop', str_repeat('a', 64)] as $column) {
            yield 'invalid SQL column '.$column => [array_replace($base, ['custom_data_properties' => ['plan' => ['column' => $column]]])];
        }
        yield 'column collision' => [array_replace($base, ['custom_data_properties' => ['plan' => ['column' => 'tier'], 'level' => ['column' => 'tier']]])];
        foreach (['-01', '00', 'constructor', 'prototype', '__proto__'] as $key) {
            yield 'invalid historical marker '.$key => [array_replace($base, ['custom_data_properties' => [$key => ['column' => 'historical_staff']]])];
        }
        yield 'historical marker without reporting column' => [array_replace($base, ['custom_data_properties' => ['__Host-formerStaff' => []]])];
        yield 'historical marker cannot enable anonymous collection' => [array_replace($base, ['custom_data_properties' => ['__Host-formerStaff' => ['column' => 'historical_staff', 'consent_required' => false]]])];
        foreach ([['source' => 'missing'], ['source' => null], ['source' => ['plan']], ['constructor' => 'plan'], ['query parameter' => 'plan']] as $index => $mappings) {
            yield 'invalid query mapping '.$index => [array_replace($base, ['query_parameter_mappings' => $mappings])];
        }
        $properties = [];
        for ($i = 0; $i < 51; ++$i) {
            $properties['property_'.$i] = [];
        }
        yield 'too many properties' => [array_replace($base, ['custom_data_properties' => $properties])];
        $mappings = [];
        for ($i = 0; $i < 101; ++$i) {
            $mappings['parameter_'.$i] = 'plan';
        }
        yield 'too many mappings' => [array_replace($base, ['query_parameter_mappings' => $mappings])];
    }

    #[DataProvider('malformedStoredModels')]
    public function testMalformedStoredModelFailsClosedEvenWhenNoPropertiesWereSubmitted(array $values): void
    {
        $settings = $this->settings($values);

        $this->expectException(\InvalidArgumentException::class);
        $settings->filterEventData(null, false);
    }

    public static function malformedStoredModels(): iterable
    {
        yield 'null properties' => [['custom_data_properties' => null]];
        yield 'null mappings' => [['query_parameter_mappings' => null]];
        yield 'string properties' => [['custom_data_properties' => 'plan']];
        yield 'null consent rule' => [['custom_data_properties' => ['plan' => ['consent_required' => null]]]];
        yield 'string consent rule' => [['custom_data_properties' => ['plan' => ['consent_required' => 'false']]]];
        yield 'invalid saved type' => [['custom_data_properties' => ['plan' => ['type' => 'number']]]];
        yield 'missing type for numeric alias' => [['custom_data_properties' => ['plan' => ['numeric_column' => 'metric']]]];
    }

    public function testBrokenYamlFailsClosed(): void
    {
        file_put_contents($this->projectDir.'/config/aggregate.yaml', 'custom_data_properties: [broken');
        $settings = new CustomDataSettings(new AggregateConfigLoader($this->projectDir, 'test'));

        $this->expectException(\RuntimeException::class);
        $settings->filterEventData(['plan' => 'private'], true);
    }

    private function settings(array $values): CustomDataSettings
    {
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump($values, 6, 2));

        return new CustomDataSettings(new AggregateConfigLoader($this->projectDir, 'test'));
    }
}
