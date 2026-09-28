<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AggregateConfigLoader;
use App\Service\CustomDataSettings;
use App\Service\EventExampleGenerator;
use App\Service\InternalTrafficSettings;
use App\Service\PrivacyPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class EventExampleGeneratorTest extends TestCase
{
    private string $projectDir;
    private array $environment;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/aggregate-event-examples-'.bin2hex(random_bytes(8));
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

    public function testDefaultModelProducesConsentAccurateExamplesWithAnEmptyAnonymousObject(): void
    {
        $generator = $this->generator([]);
        $bundle = $generator->generate();
        $anonymous = $bundle['examples']['anonymous']['payload'];
        $enhanced = $bundle['examples']['enhanced']['payload'];
        $policy = new PrivacyPolicy(new AggregateConfigLoader($this->projectDir, 'test'));

        self::assertTrue($bundle['synthetic']);
        self::assertFalse($policy->hasEnhancedConsent($anonymous['consentState']));
        self::assertTrue($policy->hasEnhancedConsent($enhanced['consentState']));
        self::assertSame([], (array) $anonymous['eventData']);
        self::assertSame(CustomDataSettings::UTM_KEYS, array_keys((array) $enhanced['eventData']));
        self::assertSame('email', $enhanced['eventData']->utm_medium);
        foreach (['visitorId', 'sessionId', 'screenWidth', 'occurredAt', 'timestamp', 'geoArea', 'userAgent'] as $key) {
            self::assertArrayNotHasKey($key, $anonymous);
        }
        self::assertSame('synthetic-visitor', $enhanced['visitorId']);
        self::assertFalse($anonymous['internalTraffic']);
        self::assertFalse($enhanced['internalTraffic']);
        $decoded = json_decode($generator->exportJson(), flags: JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $decoded->examples->anonymous->payload->eventData);
        self::assertSame([], get_object_vars($decoded->examples->anonymous->payload->eventData));
    }

    public function testExamplesRespectAliasesLiteralKeysAndExplicitDetailedUtmOverrides(): void
    {
        $bundle = $this->generator([
            'custom_data_properties' => [
                'utm_medium' => ['consent_required' => false],
                'utm_campaign' => ['consent_required' => false],
                'campaign.kind' => ['consent_required' => false],
                'plan' => ['consent_required' => true],
            ],
            'query_parameter_mappings' => ['channel' => 'utm_medium', 'utm_campaign' => 'campaign.kind', 'campaign_name' => 'utm_campaign'],
        ])->generate();

        self::assertSame([
            'utm_medium' => 'email', 'utm_campaign' => 'example-campaign', 'campaign.kind' => 'example',
        ], (array) $bundle['examples']['anonymous']['payload']['eventData']);
        self::assertSame('example', $bundle['examples']['enhanced']['payload']['eventData']->plan);
        self::assertSame(['utm_campaign'], $bundle['properties'][2]['query_parameters']);
        self::assertSame('anonymous_and_enhanced', $bundle['properties'][2]['collection']);
        self::assertSame('enhanced_only', $bundle['properties'][3]['collection']);
        self::assertStringContainsString('Detailed UTM properties and aliases remain permitted', implode(' ', $bundle['notes']));
    }

    #[DataProvider('markerNames')]
    public function testReservedMarkersAndLegacyReportingOnlyKeysNeverBecomeSampleProperties(string $marker): void
    {
        $bundle = $this->generator([
            'internal_traffic_name' => $marker,
            'custom_data_properties' => [
                $marker => ['column' => 'organization_traffic', 'consent_required' => false],
                '__Host-formerStaff' => ['column' => 'historical_staff', 'consent_required' => true],
                'plan' => ['consent_required' => false],
            ],
            'query_parameter_mappings' => [],
        ])->generate();

        foreach ($bundle['examples'] as $example) {
            self::assertSame(['plan' => 'example'], (array) $example['payload']['eventData']);
            self::assertFalse($example['payload']['internalTraffic']);
        }
        self::assertSame('not_submittable', $bundle['properties'][0]['collection']);
        self::assertSame('not_submittable', $bundle['properties'][1]['collection']);
    }

    public static function markerNames(): iterable
    {
        yield ['orgInternalTraffic'];
        yield ['utm_medium'];
        yield ['__Host-companyStaff'];
        yield [str_repeat('s', 128)];
    }

    public function testReadOnlyExportUsesTheActiveSavedEnvironmentWithoutLeakingOtherSettings(): void
    {
        $generator = $this->generator([
            'admin_token' => 'private-admin-token',
            'app_host' => 'https://private-host.example',
            'database_url' => 'mysql://private-database-secret',
            'internal_traffic_value' => 'private-marker-value',
            'internal_traffic_share_token' => str_repeat('secret', 10),
            'environments' => [
                'test' => ['custom_data_properties' => ['plan' => ['description' => 'private-description', 'column' => 'private_alias']], 'query_parameter_mappings' => []],
                'prod' => ['custom_data_properties' => ['production_only' => []]],
            ],
        ]);
        file_put_contents($this->projectDir.'/config/websites.yaml', 'token: private-website-token');
        $_ENV['CUSTOM_DATA_PROPERTIES'] = 'should-not-override-yaml';
        $_ENV['INTERNAL_TRAFFIC_VALUE'] = 'private-environment-marker';
        $before = file_get_contents($this->projectDir.'/config/aggregate.yaml');
        $json = $generator->exportJson();

        self::assertSame($before, file_get_contents($this->projectDir.'/config/aggregate.yaml'));
        self::assertSame('token: private-website-token', file_get_contents($this->projectDir.'/config/websites.yaml'));
        self::assertSame($json, $generator->exportJson());
        self::assertStringNotContainsString('private-', $json);
        self::assertStringNotContainsString('private_alias', $json);
        self::assertStringNotContainsString(str_repeat('secret', 10), $json);
        self::assertStringNotContainsString('production_only', $json);
        $bundle = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['plan' => 'example'], $bundle['examples']['enhanced']['payload']['eventData']);
    }

    public function testEnvironmentSpecificFileAndEnvironmentMarkerNameAreRespected(): void
    {
        $generator = $this->generator(['custom_data_properties' => ['main_only' => []]]);
        file_put_contents($this->projectDir.'/config/aggregate_test.yaml', Yaml::dump([
            'custom_data_properties' => ['staff' => ['consent_required' => false], 'plan' => ['consent_required' => false]],
            'query_parameter_mappings' => [],
        ]));
        $_ENV['INTERNAL_TRAFFIC_NAME'] = 'staff';

        self::assertSame(['plan' => 'example'], (array) $generator->generate()['examples']['anonymous']['payload']['eventData']);
    }

    public function testEmptyReplacementModelProducesObjectsInBothModes(): void
    {
        $json = $this->generator(['custom_data_properties' => [], 'query_parameter_mappings' => []])->exportJson();
        $bundle = json_decode($json, flags: JSON_THROW_ON_ERROR);

        foreach ($bundle->examples as $example) {
            self::assertInstanceOf(\stdClass::class, $example->payload->eventData);
            self::assertSame([], get_object_vars($example->payload->eventData));
        }
        self::assertSame([], $bundle->properties);
    }

    public function testMaximumModelRetainsEveryPermittedPropertyWithinRequestBounds(): void
    {
        $properties = [];
        for ($i = 0; $i < 50; ++$i) {
            $properties['property_'.$i] = ['consent_required' => false];
        }
        $bundle = $this->generator(['custom_data_properties' => $properties, 'query_parameter_mappings' => []])->generate();

        foreach ($bundle['examples'] as $example) {
            self::assertCount(50, (array) $example['payload']['eventData']);
            self::assertLessThan(65_536, strlen(json_encode($example['payload'], JSON_THROW_ON_ERROR)));
        }
    }

    #[DataProvider('invalidConfiguration')]
    public function testInvalidSavedConfigurationCannotProduceExamples(array $config): void
    {
        $generator = $this->generator($config);
        $this->expectException(\Exception::class);
        $generator->generate();
    }

    public static function invalidConfiguration(): iterable
    {
        yield 'string consent' => [['custom_data_properties' => ['plan' => ['consent_required' => 'false']]]];
        yield 'unsupported types' => [['custom_data_properties' => ['plan' => ['type' => 'array']]]];
        yield 'null model' => [['custom_data_properties' => null]];
        yield 'unmodeled query destination' => [['query_parameter_mappings' => ['plan' => 'missing']]];
        yield 'invalid marker' => [['internal_traffic_value' => ['private-marker']]];
        yield 'invalid kill switch' => [['anonymous_tracking_enabled' => 'not-a-boolean']];
        yield 'invalid exclusions' => [['anonymous_excluded_paths' => ['private-path-without-slash']]];
    }

    public function testBrokenYamlCannotProduceDefaultExamples(): void
    {
        $generator = $this->generator([]);
        file_put_contents($this->projectDir.'/config/aggregate.yaml', 'custom_data_properties: [private-invalid-yaml');
        $this->expectException(\RuntimeException::class);
        $generator->generate();
    }

    #[DataProvider('exportModes')]
    public function testModeSelectionPreservesConsentMetadata(string $mode, array $expected): void
    {
        $bundle = json_decode($this->generator([])->exportJson($mode), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame($expected, array_keys($bundle['examples']));
        self::assertTrue($bundle['synthetic']);
        self::assertCount(6, $bundle['properties']);
        self::assertNotEmpty($bundle['notes']);
    }

    public static function exportModes(): iterable
    {
        yield ['all', ['anonymous', 'enhanced']];
        yield ['anonymous', ['anonymous']];
        yield ['enhanced', ['enhanced']];
    }

    public function testInvalidModeIsRejectedBeforeReadingTheModel(): void
    {
        $settings = $this->createMock(CustomDataSettings::class);
        $settings->expects(self::never())->method('toArray');
        $this->expectException(\InvalidArgumentException::class);

        (new EventExampleGenerator($settings))->exportJson('invalid');
    }

    public function testSavedTypesProduceActualJsonNumbersAndBooleans(): void
    {
        $generator = $this->generator([
            'custom_data_properties' => [
                'label' => ['type' => 'string', 'consent_required' => false],
                'quantity' => ['type' => 'integer', 'consent_required' => false],
                'ratio' => ['type' => 'float'], 'amount' => ['type' => 'double'],
                'flag' => ['type' => 'boolean', 'consent_required' => false],
            ],
            'query_parameter_mappings' => [],
        ]);
        $bundle = json_decode($generator->exportJson(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(['label' => 'example', 'quantity' => 2, 'flag' => true], $bundle['examples']['anonymous']['payload']['eventData']);
        self::assertSame(12.5, $bundle['examples']['enhanced']['payload']['eventData']['ratio']);
        self::assertSame(12.5, $bundle['examples']['enhanced']['payload']['eventData']['amount']);
        self::assertSame('integer', $bundle['properties'][1]['type']);
    }

    public function testEcommerceRecommendationIsFlatTypedConsentRequiredAndNeverSaved(): void
    {
        $generator = $this->generator(['custom_data_properties' => ['existing_property' => ['consent_required' => false]]]);
        $before = file_get_contents($this->projectDir.'/config/aggregate.yaml');
        $bundle = $generator->generate('ecommerce');

        self::assertSame('ecommerce_recommendation', $bundle['source']);
        self::assertSame('purchase', $bundle['examples']['enhanced']['payload']['eventName']);
        self::assertSame([], (array) $bundle['examples']['anonymous']['payload']['eventData']);
        self::assertSame([
            'currency' => 'USD', 'total_minor' => 4999, 'tax_minor' => 400, 'shipping_minor' => 500,
            'item_count' => 2, 'discount_rate' => 0.1, 'product_category' => 'accessories', 'checkout_step' => 'complete',
        ], (array) $bundle['examples']['enhanced']['payload']['eventData']);
        self::assertSame('integer', $bundle['recommended_model']['custom_data_properties']['total_minor']['type']);
        self::assertSame('total_minor_number', $bundle['recommended_model']['custom_data_properties']['total_minor']['numeric_column']);
        foreach ($bundle['recommended_model']['custom_data_properties'] as $property) {
            self::assertTrue($property['consent_required']);
        }
        self::assertSame($before, file_get_contents($this->projectDir.'/config/aggregate.yaml'));
        self::assertSame(['existing_property' => 'example'], (array) $generator->generate()['examples']['anonymous']['payload']['eventData']);
        self::assertArrayNotHasKey('goalEvent', $bundle['examples']['enhanced']['payload']);
        self::assertStringContainsString('unsaved proposed model', $bundle['notes'][0]);
        self::assertStringContainsString('Group calculations by currency', implode(' ', $bundle['notes']));
        self::assertStringContainsString('retries can record duplicate purchases', implode(' ', $bundle['notes']));
    }

    public function testDocumentedEcommercePurchaseJsonMatchesTheGeneratedRequestIncludingValueTypes(): void
    {
        $generated = json_decode($this->generator([])->exportJson('enhanced', 'ecommerce'), true, flags: JSON_THROW_ON_ERROR);
        $documented = json_decode(file_get_contents(dirname(__DIR__, 2).'/docs/examples/ecommerce-purchase.json'), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame($generated['examples']['enhanced']['payload'], $documented);
        self::assertSame('purchase', $documented['eventName']);
        self::assertSame('granted', $documented['consentState']);
        self::assertSame(4999, $documented['eventData']['total_minor']);
        self::assertSame(0.1, $documented['eventData']['discount_rate']);
        self::assertFalse($documented['internalTraffic']);

        $guide = file_get_contents(dirname(__DIR__, 2).'/docs/EVENT-EXAMPLES.md');
        self::assertSame(1, preg_match('/```json\n(.*?)\n```/s', $guide, $matches));
        self::assertSame($documented, json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR));
    }

    public function testEnabledPageSequenceAppearsAsANumberInEveryExampleWithoutAModelDefinition(): void
    {
        $generator = $this->generator([
            'page_sequence_enabled' => true,
            'custom_data_properties' => ['utm_medium' => ['consent_required' => false]],
        ]);
        foreach (['model', 'ecommerce'] as $example) {
            $bundle = json_decode($generator->exportJson('all', $example), true, flags: JSON_THROW_ON_ERROR);
            foreach ($bundle['examples'] as $item) {
                self::assertSame(2, $item['payload']['eventData']['page_sequence']);
            }
            $properties = array_column($bundle['properties'], null, 'key');
            self::assertSame('integer', $properties['page_sequence']['type']);
            self::assertSame('anonymous_and_enhanced', $properties['page_sequence']['collection']);
            self::assertSame([], $properties['page_sequence']['query_parameters']);
            self::assertStringContainsString('asynchronous events reuse', implode(' ', $bundle['notes']));
        }
        $payload = (array) $generator->generate()['examples']['anonymous']['payload']['eventData'];
        self::assertSame(['utm_medium' => 'email', 'page_sequence' => 2], $payload);
    }

    public function testDisabledPageSequenceReportingDefinitionDoesNotEnableCollectionInExamples(): void
    {
        $bundle = $this->generator([
            'custom_data_properties' => ['page_sequence' => ['type' => 'integer', 'consent_required' => false, 'numeric_column' => 'page_depth']],
            'query_parameter_mappings' => [],
        ])->generate();
        foreach ($bundle['examples'] as $item) {
            self::assertSame([], (array) $item['payload']['eventData']);
        }
        self::assertSame('not_submittable', $bundle['properties'][0]['collection']);
    }

    public function testEnabledPageSequenceReservesASlotInAnExampleWithFiftyModeledProperties(): void
    {
        $properties = [];
        for ($index = 1; $index <= 50; ++$index) {
            $properties['property_'.$index] = ['consent_required' => false];
        }
        $bundle = $this->generator(['page_sequence_enabled' => true, 'custom_data_properties' => $properties])->generate();
        foreach ($bundle['examples'] as $example) {
            $data = (array) $example['payload']['eventData'];
            self::assertCount(50, $data);
            self::assertSame(2, $data['page_sequence']);
        }
    }

    private function generator(array $values): EventExampleGenerator
    {
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump($values, 6, 2));

        return new EventExampleGenerator(new CustomDataSettings(new AggregateConfigLoader($this->projectDir, 'test')));
    }
}
