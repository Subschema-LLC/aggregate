<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\ScriptController;
use App\Service\AggregateConfigLoader;
use App\Service\CustomDataSettings;
use App\Service\InternalTrafficSettings;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ScriptControllerTest extends TestCase
{
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            foreach (['public/aggregate.js', 'var/browser/manifest.json', 'var/browser/aggregate.template.min.js'] as $file) {
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
        self::assertStringContainsString('must-revalidate', (string) $response->headers->get('Cache-Control'));
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
                'orgInternalTraffic' => ['column' => 'organization_traffic'],
            ],
            'query_parameter_mappings' => ['utm_campaign' => 'campaign', 'campaign_name' => 'campaign'],
        ])->getContent();

        self::assertSame([
            'queryParameters' => ['utm_campaign' => 'campaign', 'campaign_name' => 'campaign'],
            'consentFreeProperties' => ['campaign'],
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
        ], $this->customDataBrowserConfig((string) $this->response([])->getContent()));
    }

    public function testInvalidCollectionPolicyPreventsServingTheTracker(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->response([
            'custom_data_properties' => ['plan' => ['consent_required' => 'false']],
            'query_parameter_mappings' => [],
        ]);
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
        self::assertStringContainsString('must-revalidate', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('/*! preserved license */', $script);
        self::assertStringNotContainsString('__AGGREGATE_', $script);
        self::assertStringNotContainsString('</script>', $script);
        self::assertStringNotContainsString($settings['internal_traffic_share_token'], $script);
        self::assertSame(1, preg_match('/window\.fixture=(.*);/', $script, $matches));
        $public = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($settings['js_namespace'], $public[0]);
        self::assertSame($settings['internal_traffic_value'], $public[1]['value']);
        self::assertSame(['queryParameters' => ['utm_medium' => 'medium'], 'consentFreeProperties' => ['medium']], $public[2]);

        $settings['internal_traffic_value'] = 'updated-without-rebuilding';
        self::assertStringContainsString('updated-without-rebuilding', (string) $this->response($settings, Request::create('/aggregate.js?min=1'), $directory)->getContent());
        self::assertSame('source', $this->response($settings, Request::create('/aggregate.js'), $directory)->headers->get('X-Aggregate-Script'));
    }

    public function testMissingStaleOrIncompleteBuildFallsBackToCurrentConfiguredSource(): void
    {
        foreach (['missing', 'stale-source', 'stale-template', 'malformed-manifest', 'missing-placeholder'] as $problem) {
            $directory = $this->buildFixture();
            if ($problem === 'missing') unlink($directory.'/var/browser/aggregate.template.min.js');
            if ($problem === 'stale-source') file_put_contents($directory.'/public/aggregate.js', "\n// New source version\n", FILE_APPEND);
            if ($problem === 'stale-template') file_put_contents($directory.'/var/browser/aggregate.template.min.js', 'stale');
            if ($problem === 'malformed-manifest') file_put_contents($directory.'/var/browser/manifest.json', '{broken');
            if ($problem === 'missing-placeholder') {
                file_put_contents($directory.'/var/browser/aggregate.template.min.js', 'window.fixture=__AGGREGATE_NAMESPACE__;');
                $this->writeManifest($directory);
            }

            $response = $this->response(['js_namespace' => 'CurrentAnalytics'], Request::create('/aggregate.js?min=1'), $directory);
            self::assertSame(200, $response->getStatusCode(), $problem);
            self::assertSame('source', $response->headers->get('X-Aggregate-Script'), $problem);
            self::assertStringContainsString('var namespace = "CurrentAnalytics";', (string) $response->getContent(), $problem);
            self::assertStringNotContainsString('__AGGREGATE_', (string) $response->getContent(), $problem);
        }
    }

    private function response(array $settings, ?Request $request = null, ?string $projectDir = null): \Symfony\Component\HttpFoundation\Response
    {
        $config = $this->createStub(AggregateConfigLoader::class);
        $config->method('all')->willReturn($settings);
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
        file_put_contents($directory.'/var/browser/aggregate.template.min.js', "/*! preserved license */\nwindow.fixture=[__AGGREGATE_NAMESPACE__,__AGGREGATE_INTERNAL_TRAFFIC__,__AGGREGATE_CUSTOM_DATA__];\n");
        $this->writeManifest($directory);

        return $directory;
    }

    private function writeManifest(string $directory): void
    {
        file_put_contents($directory.'/var/browser/manifest.json', json_encode([
            'format' => 1,
            'sourceSha256' => hash_file('sha256', $directory.'/public/aggregate.js'),
            'templateSha256' => hash_file('sha256', $directory.'/var/browser/aggregate.template.min.js'),
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
}
