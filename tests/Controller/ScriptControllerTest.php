<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\ScriptController;
use App\Service\AggregateConfigLoader;
use App\Service\CustomDataSettings;
use App\Service\InternalTrafficSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ScriptControllerTest extends TestCase
{
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            foreach (['public/aggregate.js', 'var/browser/manifest.json', 'var/browser/aggregate.template.min.js', 'var/browser/aggregate-without-page-depth.template.min.js', 'var/browser/aggregate-strict.template.min.js'] as $file) {
                if (is_file($directory.'/'.$file)) unlink($directory.'/'.$file);
            }
            foreach (['var/browser', 'var', 'public', ''] as $child) rmdir($directory.'/'.$child);
        }
        parent::tearDown();
    }

    public function testConfiguredMarkerIsPublicButShareTokenAndOtherConfigurationStayPrivate(): void
    {
        $settings = [
            'js_namespace' => 'CompanyAnalytics',
            'internal_traffic_storage' => 'local_storage',
            'internal_traffic_name' => 'companyStaff',
            'internal_traffic_value' => 'staff',
            'internal_traffic_cookie_domain' => '.example.com',
            'internal_traffic_share_token' => str_repeat('private-share-token-', 3),
            'mail_password' => 'private-mail-password',
        ];
        $response = $this->response($settings);
        $script = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/javascript', $response->headers->get('Content-Type'));
        self::assertSame('300', $response->headers->getCacheControlDirective('max-age'));
        self::assertTrue($response->headers->hasCacheControlDirective('public'));
        self::assertNotNull($response->getEtag());
        self::assertStringContainsString('var namespace = "CompanyAnalytics";', $script);
        self::assertSame([
            'storage' => 'local_storage',
            'name' => 'companyStaff',
            'value' => 'staff',
            'cookieDomain' => '.example.com',
        ], $this->browserConfig($script));
        self::assertStringNotContainsString($settings['internal_traffic_share_token'], $script);
        self::assertStringNotContainsString($settings['mail_password'], $script);
        self::assertStringNotContainsString('internal_traffic_share_token', $script);
    }

    public function testDefaultsUseTheDocumentedCookieNameAndStringValue(): void
    {
        self::assertSame([
            'storage' => 'cookie',
            'name' => 'orgInternalTraffic',
            'value' => 'true',
            'cookieDomain' => '',
        ], $this->browserConfig((string) $this->response([])->getContent()));
    }

    public function testJavaScriptSensitiveConfigurationIsEncodedWithoutReplacementExpansion(): void
    {
        $namespace = "Company\n'</script>\"\\$1";
        $markerValue = "staff'</script>\"&\\$1";
        $script = (string) $this->response([
            'js_namespace' => $namespace,
            'internal_traffic_value' => $markerValue,
        ])->getContent();

        self::assertSame($markerValue, $this->browserConfig($script)['value']);
        self::assertSame(1, preg_match('/var namespace = (.*);/', $script, $matches));
        self::assertSame($namespace, json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('</script>', $script);
        self::assertStringNotContainsString("Company\n", $script);
    }

    public function testPublicCollectionSettingsIncludeMappingsAndConsentFreeKeysOnly(): void
    {
        $script = (string) $this->response([
            'custom_data_properties' => [
                'campaign' => ['description' => 'private implementation instructions', 'consent_required' => false, 'column' => 'campaign_name'],
                'plan' => ['consent_required' => true],
                'org_internal_traffic' => ['type' => 'boolean', 'column' => 'organization_traffic'],
            ],
            'query_parameter_mappings' => ['utm_campaign' => 'campaign', 'campaign_name' => 'campaign'],
        ])->getContent();

        self::assertSame([
            'queryParameters' => ['utm_campaign' => 'campaign', 'campaign_name' => 'campaign'],
            'consentFreeProperties' => ['campaign'],
            'pageSequenceEnabled' => false,
            'pageSequenceMethod' => 'session_storage',
        ], $this->customDataBrowserConfig($script));
        self::assertStringNotContainsString('private implementation instructions', $script);
        self::assertStringNotContainsString('organization_traffic', $script);
        self::assertStringNotContainsString('custom_data_properties', $script);
    }

    public function testDefaultUtmMappingsRequireConsent(): void
    {
        self::assertSame([
            'queryParameters' => array_combine(CustomDataSettings::UTM_KEYS, CustomDataSettings::UTM_KEYS),
            'consentFreeProperties' => [],
            'pageSequenceEnabled' => false,
            'pageSequenceMethod' => 'session_storage',
        ], $this->customDataBrowserConfig((string) $this->response([])->getContent()));
    }

    public function testSourceAndOptionalMinifiedScriptsPublishOnlyCollectionTypes(): void
    {
        $settings = [
            'custom_data_properties' => [
                'quantity' => ['type' => 'integer', 'consent_required' => false, 'description' => 'private typed model notes', 'column' => 'quantity_text', 'numeric_column' => 'quantity_value'],
                'revenue' => ['type' => 'double', 'numeric_column' => 'revenue_value'],
                'org_internal_traffic' => ['type' => 'boolean', 'column' => 'staff_reporting'],
            ],
            'query_parameter_mappings' => [],
        ];
        $settings['page_sequence_enabled'] = true;
        $settings['page_sequence_method'] = 'url_parameter';
        $expected = ['queryParameters' => [], 'consentFreeProperties' => ['quantity'], 'pageSequenceEnabled' => true, 'pageSequenceMethod' => 'url_parameter', 'pageSequenceExcludedPaths' => [], 'propertyTypes' => ['quantity' => 'integer', 'revenue' => 'double']];
        $source = (string) $this->response($settings)->getContent();
        self::assertSame($expected, $this->customDataBrowserConfig($source));
        $minified = (string) $this->response($settings, Request::create('/aggregate.js?min=1'), $this->buildFixture())->getContent();
        self::assertSame(1, preg_match('/window\.fixture=(.*?);window\.build=/', $minified, $matches));
        self::assertSame($expected, json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR)[2]);
        foreach ([$source, $minified] as $script) {
            foreach (['private typed model notes', 'quantity_text', 'quantity_value', 'revenue_value', 'staff_reporting'] as $private) {
                self::assertStringNotContainsString($private, $script);
            }
        }
    }

    public function testInvalidCollectionPolicyPreventsServingTheTracker(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->response([
            'custom_data_properties' => ['plan' => ['consent_required' => 'false']],
            'query_parameter_mappings' => [],
        ]);
    }

    #[DataProvider('invalidPageSequencePolicies')]
    public function testInvalidPageSequencePolicyPreventsServingTheTracker(array $settings): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->response($settings);
    }

    public static function invalidPageSequencePolicies(): iterable
    {
        yield 'string boolean' => [['page_sequence_enabled' => 'false']];
        yield 'unsupported method while enabled' => [['page_sequence_enabled' => true, 'page_sequence_method' => 'cookie']];
        yield 'unsupported method while disabled' => [['page_sequence_enabled' => false, 'page_sequence_method' => 'cookie']];
        yield 'null method' => [['page_sequence_method' => null]];
    }

    public function testOptionalMinifiedTemplateReceivesCurrentPublicConfiguration(): void
    {
        $directory = $this->buildFixture();
        $settings = [
            'js_namespace' => "Company'</script>\n\\$1",
            'internal_traffic_name' => 'companyStaff',
            'internal_traffic_value' => "staff'</script>&\\$1",
            'internal_traffic_share_token' => str_repeat('secret-', 8),
            'custom_data_properties' => ['medium' => ['consent_required' => false]],
            'query_parameter_mappings' => ['utm_medium' => 'medium'],
        ];

        $response = $this->response($settings, Request::create('/aggregate.js?min=1'), $directory);
        $script = (string) $response->getContent();
        self::assertSame('minified', $response->headers->get('X-Aggregate-Script'));
        // Page depth is off, so the build without its code is sent.
        self::assertSame('without-page-depth', $response->headers->get('X-Aggregate-Build'));
        self::assertStringContainsString('window.build="without-page-depth";', $script);
        self::assertSame('300', $response->headers->getCacheControlDirective('max-age'));
        self::assertTrue($response->headers->hasCacheControlDirective('public'));
        self::assertNotNull($response->getEtag());
        self::assertStringContainsString('/*! preserved license */', $script);
        self::assertStringNotContainsString('__AGGREGATE_', $script);
        self::assertStringNotContainsString('</script>', $script);
        self::assertStringNotContainsString($settings['internal_traffic_share_token'], $script);
        self::assertSame(1, preg_match('/window\.fixture=(.*?);window\.build=/', $script, $matches));
        $public = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($settings['js_namespace'], $public[0]);
        self::assertSame($settings['internal_traffic_value'], $public[1]['value']);
        self::assertSame(['queryParameters' => ['utm_medium' => 'medium'], 'consentFreeProperties' => ['medium'], 'pageSequenceEnabled' => false, 'pageSequenceMethod' => 'session_storage'], $public[2]);

        $settings['internal_traffic_value'] = 'updated-without-rebuilding';
        self::assertStringContainsString('updated-without-rebuilding', (string) $this->response($settings, Request::create('/aggregate.js?min=1'), $directory)->getContent());
        self::assertSame('source', $this->response($settings, Request::create('/aggregate.js'), $directory)->headers->get('X-Aggregate-Script'));
    }

    /** @param array<string, mixed> $settings */
    #[DataProvider('trackerBuildSettings')]
    public function testSmallerBuildIsSentOnlyWhenTheSettingAllowsItAndTheLeftOutCodeCannotRun(array $settings, string $build): void
    {
        $directory = $this->buildFixture();
        $response = $this->response($settings, Request::create('/aggregate.js?min=1'), $directory);
        self::assertSame('minified', $response->headers->get('X-Aggregate-Script'));
        self::assertSame($build, $response->headers->get('X-Aggregate-Build'));
        self::assertStringContainsString('window.build="'.$build.'";', (string) $response->getContent());

        // The readable script keeps every feature whatever the settings.
        $readable = $this->response($settings, Request::create('/aggregate.js'), $directory);
        self::assertSame('full', $readable->headers->get('X-Aggregate-Build'));
        self::assertStringContainsString('var withPageDepth = true;', (string) $readable->getContent());
    }

    public static function trackerBuildSettings(): iterable
    {
        yield 'page depth off' => [[], 'without-page-depth'];
        yield 'page depth on' => [['page_sequence_enabled' => true], 'full'];
        yield 'strict profile' => [['collection_profile' => 'strict', 'page_sequence_enabled' => true], 'strict'];
        yield 'setting off, page depth off' => [['tracker_omit_unused_features' => false], 'full'];
        yield 'setting off, strict profile' => [['tracker_omit_unused_features' => false, 'collection_profile' => 'strict'], 'full'];
    }

    public function testSmallerBuildIsCompactedHereWhenTheTerserBuildHasNone(): void
    {
        $directory = $this->buildFixture();
        unlink($directory.'/var/browser/aggregate-without-page-depth.template.min.js');
        unlink($directory.'/var/browser/aggregate-strict.template.min.js');
        $this->writeManifest($directory);

        $withoutPageDepth = $this->response([], Request::create('/aggregate.js?min=1'), $directory);
        $full = $this->response(['page_sequence_enabled' => true], Request::create('/aggregate.js?min=1'), $directory);
        $strict = $this->response(['collection_profile' => 'strict'], Request::create('/aggregate.js?min=1'), $directory);
        self::assertSame(['compact', 'without-page-depth'], [$withoutPageDepth->headers->get('X-Aggregate-Script'), $withoutPageDepth->headers->get('X-Aggregate-Build')]);
        self::assertSame(['minified', 'full'], [$full->headers->get('X-Aggregate-Script'), $full->headers->get('X-Aggregate-Build')]);
        self::assertSame(['compact', 'strict'], [$strict->headers->get('X-Aggregate-Script'), $strict->headers->get('X-Aggregate-Build')]);

        $lean = (string) $withoutPageDepth->getContent();
        self::assertStringNotContainsString('withPageDepth', $lean);
        self::assertStringNotContainsString('base[target]', $lean, 'link decoration is left out');
        self::assertStringContainsString("'aggregate_page_sequence:'", $lean, 'removing an earlier counter is kept');
        self::assertStringContainsString('mastodon.social', $lean);
        $strictContent = (string) $strict->getContent();
        self::assertStringNotContainsString('mastodon.social', $strictContent, 'referrer channels are left out');
        self::assertStringNotContainsString('aggregate_visitor_id', $strictContent, 'identifiers are left out');
        self::assertLessThan(0.6 * strlen($lean), strlen($strictContent));
    }

    public function testTrackerDefaultsToTheStandardCollectionProfile(): void
    {
        $script = (string) $this->response([])->getContent();

        self::assertSame(['profile' => 'standard'], $this->collectionBrowserConfig($script));
    }

    public function testStrictProfileIsServedAndWithholdsCustomDataSettingsInBothScriptVariants(): void
    {
        $settings = [
            'collection_profile' => 'strict',
            'custom_data_properties' => ['medium' => ['consent_required' => false, 'type' => 'string']],
            'query_parameter_mappings' => ['utm_medium' => 'medium'],
            'page_sequence_enabled' => true,
            'page_sequence_method' => 'url_parameter',
        ];
        $withheld = ['queryParameters' => [], 'consentFreeProperties' => [], 'pageSequenceEnabled' => false, 'pageSequenceMethod' => 'url_parameter', 'propertyTypes' => ['medium' => 'string']];

        $source = (string) $this->response($settings)->getContent();
        self::assertSame(['profile' => 'strict'], $this->collectionBrowserConfig($source));
        self::assertSame($withheld, $this->customDataBrowserConfig($source));

        $response = $this->response($settings, Request::create('/aggregate.js?min=1'), $this->buildFixture());
        self::assertSame('minified', $response->headers->get('X-Aggregate-Script'));
        self::assertSame('strict', $response->headers->get('X-Aggregate-Build'));
        self::assertSame(1, preg_match('/window\.fixture=(.*?);window\.build=/', (string) $response->getContent(), $matches));
        $public = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($withheld, $public[2]);
        self::assertSame(['profile' => 'strict'], $public[3]);
    }

    public function testInvalidServedProfileValueResolvesToStrict(): void
    {
        $script = (string) $this->response(['collection_profile' => 'relaxed'])->getContent();

        self::assertSame(['profile' => 'strict'], $this->collectionBrowserConfig($script));
    }

    public function testMissingStaleOrIncompleteBuildIsReplacedByTheCurrentSourceCompactedOnTheServer(): void
    {
        foreach (['missing', 'stale-source', 'stale-template', 'malformed-manifest', 'missing-placeholder', 'unparseable'] as $problem) {
            $directory = $this->buildFixture();
            $templates = glob($directory.'/var/browser/aggregate*.template.min.js');
            if ($problem === 'missing') array_map('unlink', $templates);
            if ($problem === 'stale-source') file_put_contents($directory.'/public/aggregate.js', "\n// New source version\n", FILE_APPEND);
            if ($problem === 'stale-template') foreach ($templates as $template) file_put_contents($template, 'stale');
            if ($problem === 'malformed-manifest') file_put_contents($directory.'/var/browser/manifest.json', '{broken');
            if ($problem === 'missing-placeholder') {
                foreach ($templates as $template) file_put_contents($template, 'window.fixture=__AGGREGATE_NAMESPACE__;');
                $this->writeManifest($directory);
            }
            // Only source the server cannot parse is sent as it is.
            if ($problem === 'unparseable') file_put_contents($directory.'/public/aggregate.js', "\n})(;\n", FILE_APPEND);

            $response = $this->response(['js_namespace' => 'CurrentAnalytics'], Request::create('/aggregate.js?min=1'), $directory);
            $content = (string) $response->getContent();
            self::assertSame(200, $response->getStatusCode(), $problem);
            self::assertStringNotContainsString('__AGGREGATE_', $content, $problem);
            if ($problem === 'unparseable') {
                self::assertSame('source', $response->headers->get('X-Aggregate-Script'));
                self::assertStringContainsString('var namespace = "CurrentAnalytics";', $content);
                continue;
            }
            self::assertSame('compact', $response->headers->get('X-Aggregate-Script'), $problem);
            self::assertStringContainsString('var namespace="CurrentAnalytics";', $content, $problem);
            self::assertStringStartsWith("/*!\n * Aggregate Analytics browser tracker\n * SPDX-License-Identifier: BSD-3-Clause", $content, 'the license notice is kept');
            self::assertStringContainsString('Redistribution and use in source and binary forms', $content);
            self::assertStringNotContainsString('// ScriptController replaces these defaults', $content, 'other comments are removed');
            self::assertLessThan(0.7 * strlen((string) $this->response(['js_namespace' => 'CurrentAnalytics'], Request::create('/aggregate.js'), $directory)->getContent()), strlen($content));
        }
    }

    private function response(array $settings, ?Request $request = null, ?string $projectDir = null): \Symfony\Component\HttpFoundation\Response
    {
        $config = $this->createStub(AggregateConfigLoader::class);
        $config->method('all')->willReturn($settings);
        $config->method('getBoolWithEnvFallback')->willReturnCallback(
            static fn (string $key, bool $default = false): bool => $settings[$key] ?? $default,
        );
        $config->method('getWithEnvFallback')->willReturnCallback(
            static fn (string $key, mixed $default = null): mixed => $settings[$key] ?? $default,
        );
        $config->method('get')->willReturnCallback(
            static fn (string $key, mixed $default = null): mixed => $settings[$key] ?? $default,
        );

        return (new ScriptController($config, new InternalTrafficSettings($config), new CustomDataSettings($config), $projectDir ?? dirname(__DIR__, 2)))($request);
    }

    private function buildFixture(): string
    {
        $directory = sys_get_temp_dir().'/aggregate-script-test-'.bin2hex(random_bytes(8));
        mkdir($directory.'/public', 0777, true);
        mkdir($directory.'/var/browser', 0777, true);
        $this->temporaryDirectories[] = $directory;
        copy(dirname(__DIR__, 2).'/public/aggregate.js', $directory.'/public/aggregate.js');
        foreach (['aggregate.template.min.js' => 'full', 'aggregate-without-page-depth.template.min.js' => 'without-page-depth', 'aggregate-strict.template.min.js' => 'strict'] as $file => $build) {
            file_put_contents($directory.'/var/browser/'.$file, "/*! preserved license */\nwindow.fixture=[__AGGREGATE_NAMESPACE__,__AGGREGATE_INTERNAL_TRAFFIC__,__AGGREGATE_CUSTOM_DATA__,__AGGREGATE_COLLECTION__];window.build=\"".$build."\";\n");
        }
        $this->writeManifest($directory);

        return $directory;
    }

    private function writeManifest(string $directory): void
    {
        $builds = [];
        foreach (['without-page-depth', 'strict'] as $build) {
            $file = 'aggregate-'.$build.'.template.min.js';
            if (is_file($directory.'/var/browser/'.$file)) {
                $builds[$build] = ['file' => $file, 'templateSha256' => hash_file('sha256', $directory.'/var/browser/'.$file)];
            }
        }
        file_put_contents($directory.'/var/browser/manifest.json', json_encode([
            'format' => 1,
            'sourceSha256' => hash_file('sha256', $directory.'/public/aggregate.js'),
            'templateSha256' => hash_file('sha256', $directory.'/var/browser/aggregate.template.min.js'),
            'builds' => $builds,
        ], JSON_THROW_ON_ERROR));
    }

    private function browserConfig(string $script): array
    {
        self::assertSame(1, preg_match('/var internalTrafficDefaults = (.*);/', $script, $matches));

        return json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
    }

    private function customDataBrowserConfig(string $script): array
    {
        self::assertSame(1, preg_match('/var customDataDefaults = (.*);/', $script, $matches));

        return json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
    }

    private function collectionBrowserConfig(string $script): array
    {
        self::assertSame(1, preg_match('/var collectionDefaults = (.*);/', $script, $matches));

        return json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
    }
}
