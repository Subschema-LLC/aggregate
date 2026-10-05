<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Controller\ConsentScriptController;
use App\Service\AggregateConfigLoader;
use App\Service\AppBranding;
use App\Service\DropInScripts;
use App\Service\SiteScriptConfig;
use App\Service\StandaloneConsentSettings;
use App\Service\TagManagerSettings;
use App\Service\WebsiteConfigManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Yaml\Yaml;

final class DropInScriptsTest extends TestCase
{
    private string $directory;
    private array $environment;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        foreach (['APP_HOST', 'JS_NAMESPACE', 'BRAND_NAME', 'ANONYMOUS_TRACKING_ENABLED', 'ANONYMOUS_EXCLUDED_PATHS'] as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
        }
        $this->directory = sys_get_temp_dir().'/aggregate-drop-ins-'.bin2hex(random_bytes(8));
        (new Filesystem())->mkdir([$this->directory.'/config', $this->directory.'/public', $this->directory.'/var/browser']);
        copy(dirname(__DIR__, 2).'/public/consent.js', $this->directory.'/public/consent.js');
        copy(dirname(__DIR__, 2).'/public/consent.css', $this->directory.'/public/consent.css');
        file_put_contents($this->directory.'/config/websites.yaml', Yaml::dump(['websites' => [
            ['name' => 'Example', 'domain' => 'example.test', 'token' => 'public-website-token'],
        ]]));
    }

    protected function tearDown(): void
    {
        [$_ENV, $_SERVER] = $this->environment;
        (new Filesystem())->remove($this->directory);
    }

    public function testSnippetUsesConsentWithEitherDirectTrackerOrTagManager(): void
    {
        $scripts = $this->scripts(['admin_token' => 'private-secret', 'internal_traffic_share_token' => 'private-sharing-secret']);
        $plain = $scripts->snippet('public-website-token');
        $withTags = $scripts->snippet('public-website-token', true);

        self::assertStringContainsString('window["ExampleAnalytics"]', $plain);
        self::assertStringContainsString('"consent":false', $plain);
        self::assertStringContainsString('"websiteToken":"public-website-token"', $plain);
        self::assertStringContainsString('"endpoint":"https://analytics.example.test/api/receive"', $plain);
        $siteId = SiteScriptConfig::idForToken('public-website-token');
        self::assertStringContainsString('https://analytics.example.test/cmp-lite/sites/'.$siteId.'/consent.js?min=1', $plain);
        self::assertStringNotContainsString('/lib.js', $plain);
        self::assertStringContainsString('https://analytics.example.test/tms-lite/sites/'.$siteId.'/lib.js?min=1', $withTags);
        self::assertLessThan(strpos($plain, '/aggregate.js'), strpos($plain, '/consent.js'));
        self::assertLessThan(strpos($withTags, '/lib.js'), strpos($withTags, '/consent.js'));
        self::assertSame(2, substr_count($withTags, 'defer referrerpolicy="no-referrer"'));
        self::assertStringNotContainsString('/aggregate.js', $withTags);
        self::assertStringNotContainsString('window[', $withTags);
        self::assertStringNotContainsString('public-website-token', $withTags);
        self::assertSame($withTags, $scripts->snippet('public-website-token', true, 'query'));
        self::assertStringNotContainsString('private-', $withTags);
        $this->expectException(\InvalidArgumentException::class);
        $scripts->snippet('unregistered-token');
    }

    public function testQuerySnippetEncodesPublicParametersAndNeedsNoInlineConfiguration(): void
    {
        $token = 'public &+"</script> token';
        file_put_contents($this->directory.'/config/websites.yaml', Yaml::dump(['websites' => [
            ['name' => 'Example', 'domain' => 'example.test', 'token' => $token],
        ]]));
        $scripts = $this->scripts(['app_host' => 'https://analytics.example.test/subdirectory/', 'admin_token' => 'private-secret']);
        $snippet = $scripts->snippet($token, false, 'query');
        self::assertStringNotContainsString('window[', $snippet);
        self::assertStringNotContainsString('<script>', $snippet);
        self::assertStringNotContainsString('private-secret', $snippet);
        self::assertSame(2, preg_match_all('/<script src="([^"]+)"/', $snippet, $matches));
        self::assertStringContainsString('/subdirectory/cmp-lite/sites/', $matches[1][0]);
        $url = html_entity_decode($matches[1][1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        self::assertSame($scripts->trackerUrl($token), $url);
        self::assertStringContainsString('&amp;', $matches[1][1]);
        self::assertSame('/subdirectory/aggregate.js', parse_url($url, PHP_URL_PATH));
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame([
            'min' => '1', 'endpoint' => 'https://analytics.example.test/subdirectory/api/receive',
            'token' => $token, 'consent' => '0',
        ], $query);
    }

    public function testUnsupportedSnippetFormatIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->scripts()->snippet('public-website-token', false, 'invalid');
    }

    public function testTrackerUrlRequiresARegisteredWebsite(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->scripts()->trackerUrl('unregistered-token');
    }

    public function testConfiguredConsentScriptAndLoaderExposeOnlyPublicSettings(): void
    {
        $scripts = $this->scripts([
            'brand_name' => 'Company </script> name',
            'js_namespace' => "Company'</script>",
            'admin_token' => 'private-secret', 'mail_password' => 'private-mail',
            'internal_traffic_share_token' => 'private-share',
        ]);
        $script = $scripts->consentScript()['content'];
        self::assertStringContainsString('Company', $script);
        self::assertStringNotContainsString('</script>', $script);
        self::assertStringNotContainsString('private-', $script);
        self::assertSame(1, preg_match('/var consentConfig = (.*);/', $script, $matches));
        self::assertSame(['namespace' => "Company'</script>", 'name' => 'Company </script> name', 'categories' => ['analytics']], json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR));
        self::assertSame(1, preg_match('/var consentStyles = (.*);/', $script, $styles));
        self::assertSame(file_get_contents($this->directory.'/public/consent.css'), json_decode($styles[1], true, flags: JSON_THROW_ON_ERROR));
        self::assertStringContainsString('https://analytics.example.test/lib.js?min=1', $scripts->tagLoader());
        self::assertStringContainsString('script.nonce = current.nonce', $scripts->tagLoader());
        self::assertStringNotContainsString('public-website-token', $scripts->tagLoader());
        self::assertStringNotContainsString("Company'</script>", $scripts->snippet('public-website-token'));
    }

    public function testEnvironmentOverridesAndHeadlessPublicResponseRemainConfigured(): void
    {
        $scripts = $this->scripts(['dashboard_enabled' => false]);
        $_ENV['JS_NAMESPACE'] = 'EnvironmentAnalytics';
        $_ENV['APP_HOST'] = 'https://environment.example.test';
        $response = (new ConsentScriptController($scripts))(Request::create('/consent-manager.js?min=1'));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('compact', $response->headers->get('X-Aggregate-Script'), 'without a Node build the server compacts the script');
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('300', $response->headers->getCacheControlDirective('max-age'));
        self::assertNotNull($response->getEtag());
        self::assertStringContainsString('EnvironmentAnalytics', $response->getContent());
        self::assertStringContainsString('https://environment.example.test/lib.js?min=1', $scripts->tagLoader());
    }

    public function testConsentCategoriesComeFromEnabledTagsWithoutExposingTheirUrls(): void
    {
        $scripts = $this->scripts(['tag_manager' => ['enabled' => true, 'tags' => [
            ['id' => 'marketing', 'src' => 'https://private-provider.example.test/script.js', 'consent' => 'marketing'],
            ['id' => 'helper', 'src' => 'https://private-provider.example.test/helper.js', 'consent' => 'none'],
            ['id' => 'disabled', 'src' => 'https://private-provider.example.test/disabled.js', 'consent' => 'functional', 'enabled' => false],
        ]]]);
        $content = $scripts->consentScript()['content'];
        self::assertStringContainsString('"categories":["analytics","marketing"]', $content);
        self::assertStringNotContainsString('private-provider', $content);
    }

    public function testMalformedTagSettingsCannotProduceAPartialConsentPrompt(): void
    {
        $scripts = $this->scripts(['tag_manager' => ['enabled' => 'true', 'tags' => []]]);
        $response = (new ConsentScriptController($scripts))(Request::create('/consent-manager.js'));
        self::assertSame(503, $response->getStatusCode());
        self::assertStringNotContainsString('var consentConfig', $response->getContent());
    }

    public function testCurrentMinifiedTemplateReceivesCurrentSettingsAndInvalidBuildFallsBack(): void
    {
        $scripts = $this->scripts();
        $source = file_get_contents($this->directory.'/public/consent.js');
        $styles = file_get_contents($this->directory.'/public/consent.css');
        $template = '/*! SPDX-License-Identifier: AGPL-3.0-only */ var testConfig=__AGGREGATE_CONSENT_CONFIG__,testStyles=__AGGREGATE_CONSENT_STYLES__;';
        file_put_contents($this->directory.'/var/browser/consent.template.min.js', $template);
        file_put_contents($this->directory.'/var/browser/consent-manifest.json', json_encode([
            'format' => 1, 'sourceSha256' => hash('sha256', $source), 'templateSha256' => hash('sha256', $template),
            'stylesheetSha256' => hash('sha256', $styles),
        ]));
        $built = $scripts->consentScript(true);
        self::assertTrue($built['minified']);
        self::assertSame('minified', $built['variant']);
        self::assertStringContainsString('"namespace":"ExampleAnalytics"', $built['content']);
        self::assertStringNotContainsString('__AGGREGATE_CONSENT_CONFIG__', $built['content']);
        self::assertStringNotContainsString('__AGGREGATE_CONSENT_STYLES__', $built['content']);
        self::assertStringContainsString('ac-consent-category', $built['content']);
        file_put_contents($this->directory.'/var/browser/consent.template.min.js', $template.'corrupt');
        self::assertFalse($scripts->consentScript(true)['minified']);
        file_put_contents($this->directory.'/public/consent.js', $source."\n// source updated\n");
        file_put_contents($this->directory.'/var/browser/consent.template.min.js', $template);
        self::assertFalse($scripts->consentScript(true)['minified']);
        file_put_contents($this->directory.'/public/consent.js', $source);
        file_put_contents($this->directory.'/public/consent.css', $styles."\n.ac-consent { border-width: 3px; }\n");
        $fallback = $scripts->consentScript(true);
        self::assertFalse($fallback['minified']);
        self::assertSame('compact', $fallback['variant']);
        self::assertStringContainsString('border-width: 3px', $fallback['content']);
        self::assertStringStartsWith('/*! SPDX-License-Identifier: AGPL-3.0-only', $fallback['content']);
        self::assertStringContainsString('"namespace":"ExampleAnalytics"', $fallback['content']);
        self::assertSame('source', $scripts->consentScript(false)['variant'], 'only ?min=1 asks for a smaller script');
        file_put_contents($this->directory.'/public/consent.css', $styles);
        $manifest = json_decode(file_get_contents($this->directory.'/var/browser/consent-manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        unset($manifest['stylesheetSha256']);
        file_put_contents($this->directory.'/var/browser/consent-manifest.json', json_encode($manifest));
        self::assertFalse($scripts->consentScript(true)['minified'], 'Older builds without a stylesheet hash fall back to current source.');
    }

    public function testStylesheetTextIsSafelyEmbeddedInReadableDownload(): void
    {
        $scripts = $this->scripts();
        $styles = '/* </script><script>alert("escaped")</script> */ .ac-consent { color: #202124; }';
        file_put_contents($this->directory.'/public/consent.css', $styles);
        $content = $scripts->consentScript()['content'];
        self::assertStringNotContainsString('</script>', $content);
        self::assertSame(1, preg_match('/var consentStyles = (.*);/', $content, $matches));
        self::assertSame($styles, json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR));
    }

    public function testMissingOrEmptyStylesheetCannotProduceAPartialConfiguredScript(): void
    {
        $scripts = $this->scripts();
        foreach ([null, '', " \n\t"] as $styles) {
            if ($styles === null) {
                unlink($this->directory.'/public/consent.css');
            } else {
                file_put_contents($this->directory.'/public/consent.css', $styles);
            }
            foreach (['/consent-manager.js', '/consent-manager.js?min=1'] as $url) {
                $response = (new ConsentScriptController($scripts))(Request::create($url));
                self::assertSame(503, $response->getStatusCode());
                self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
                self::assertStringNotContainsString('ExampleAnalytics', $response->getContent());
            }
        }
    }

    #[DataProvider('invalidSources')]
    public function testUnknownSourceShapeFailsClosed(string $source): void
    {
        $scripts = $this->scripts();
        file_put_contents($this->directory.'/public/consent.js', $source);
        $response = (new ConsentScriptController($scripts))(Request::create('/consent-manager.js'));
        self::assertSame(503, $response->getStatusCode());
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertStringNotContainsString('ExampleAnalytics', $response->getContent());
    }

    public static function invalidSources(): iterable
    {
        yield ['var consentConfig = {};'];
        yield [str_repeat("var consentConfig = {namespace: 'Aggregate', name: 'Analytics'};", 2)];
        yield ["var consentConfig = {namespace: 'Aggregate', name: 'Analytics'};"];
        yield ["var consentConfig = {namespace: 'Aggregate', name: 'Analytics'};".str_repeat('var consentStyles = null;', 2)];
    }

    public function testBrokenConfigurationCannotEmitDefaults(): void
    {
        $scripts = $this->scripts();
        file_put_contents($this->directory.'/config/aggregate.yaml', 'brand_name: [broken');
        $response = (new ConsentScriptController($scripts))(Request::create('/consent-manager.js'));
        self::assertSame(503, $response->getStatusCode());
        $this->expectException(\RuntimeException::class);
        $scripts->snippet('public-website-token');
    }

    #[DataProvider('invalidHosts')]
    public function testUnsafeHostCannotEnterDownloads(string $host): void
    {
        $scripts = $this->scripts(['app_host' => $host]);
        $this->expectException(\RuntimeException::class);
        $scripts->tagLoader();
    }

    public static function invalidHosts(): iterable
    {
        yield ['javascript:alert(1)'];
        yield ['https://user:password@example.test'];
        yield ['https://example.test?private=secret'];
        yield ['https://example.test/#fragment'];
        yield ["https://example.test/\n"];
    }

    public function testStandaloneArtifactsAreIndependentAndVerifyEveryBuildInput(): void
    {
        $this->scripts(['admin_token' => 'private-secret']);
        $filesystem = new Filesystem();
        $filesystem->mirror(dirname(__DIR__, 2).'/micro-consent-dropins', $this->directory.'/micro-consent-dropins');
        $loader = new AggregateConfigLoader($this->directory, 'test');
        $websites = new WebsiteConfigManager($this->directory);
        $sites = new SiteScriptConfig($websites, $this->directory, 'test');
        $settings = new StandaloneConsentSettings($sites);
        $siteId = SiteScriptConfig::idForToken('public-website-token');
        $settings->save($siteId, ['name' => 'Choices </script>']);
        $scripts = new DropInScripts($loader, $websites, new AppBranding($loader, $this->directory), $this->directory, sites: $sites, standalone: $settings);
        $snippet = $scripts->snippet('public-website-token', true, 'window', 'standalone');
        self::assertStringContainsString('/standalone-cmp/sites/'.$siteId.'/consent.js', $snippet);
        self::assertStringNotContainsString('/cmp-lite/', $snippet);
        self::assertStringNotContainsString('/consent.js', $scripts->snippet('public-website-token', false, 'window', 'external'));
        $plain = $scripts->standaloneConsentScript(false, $siteId);
        self::assertFalse($plain['minified']);
        self::assertStringNotContainsString('</script>', $plain['content']);
        self::assertStringNotContainsString('private-secret', $plain['content']);
        self::assertStringContainsString('"aggregateNamespace":"ExampleAnalytics"', $plain['content']);
        self::assertStringNotContainsString('var microConsentStyles = null;', $plain['content']);
        $inputs = [
            'sourceSha256' => '/micro-consent-dropins/js/consent-ui.js',
            'adapterSha256' => '/micro-consent-dropins/js/aggregate-consent.js',
            'stylesheetSha256' => '/micro-consent-dropins/css/consent-ui.css',
            'templateSha256' => '/var/browser/standalone-consent.template.min.js',
        ];
        file_put_contents($this->directory.$inputs['templateSha256'], 'window.MicroConsentConfig=__MICRO_CONSENT_CONFIG__; window.styles=__MICRO_CONSENT_STYLES__;');
        $manifest = ['format' => 1];
        foreach ($inputs as $field => $path) {
            $manifest[$field] = hash_file('sha256', $this->directory.$path);
        }
        file_put_contents($this->directory.'/var/browser/standalone-consent-manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        $compact = $scripts->standaloneConsentScript(true, $siteId);
        self::assertTrue($compact['minified']);
        self::assertStringContainsString('ExampleAnalytics', $compact['content']);
        self::assertStringNotContainsString('__MICRO_CONSENT_', $compact['content']);
        foreach ($inputs as $path) {
            $original = file_get_contents($this->directory.$path);
            file_put_contents($this->directory.$path, $original."\n/* updated */");
            $stale = $scripts->standaloneConsentScript(true, $siteId);
            self::assertFalse($stale['minified'], $path);
            // Without a current build, each file is compacted here with its license notice.
            self::assertSame('compact', $stale['variant'], $path);
            self::assertStringStartsWith('window.MicroConsentConfig = {', $stale['content']);
            self::assertSame(2, preg_match_all('~(?:^|\n)/\*! SPDX-License-Identifier: AGPL-3.0-only~', $stale['content']), $path.': the runtime and the bridge keep their notices');
            self::assertStringNotContainsString('__MICRO_CONSENT_', $stale['content']);
            self::assertStringNotContainsString('// Optional bridge', $stale['content']);
            self::assertLessThan(strlen($plain['content']), strlen($stale['content']));
            file_put_contents($this->directory.$path, $original);
        }
        // Wording, colors and buttons reach both banners as camelCase browser settings.
        $settings->save($siteId, ['name' => 'Choices', 'text' => ['request_opt_out' => 'Do not sell'], 'theme' => ['accent' => '#1a4f8b'], 'buttons' => ['show' => ['reject', 'manage']]]);
        $configured = $scripts->standaloneConsentScript(false, $siteId)['content'];
        self::assertStringContainsString('"text":{"requestOptOut":"Do not sell"},"theme":{"accent":"#1A4F8B"},"buttons":{"show":["reject","manage"]}', $configured);
        $sites->saveConsent($siteId, ['enabled' => true, 'name' => 'Shop', 'privacy_policy_url' => 'https://www.example.test/privacy',
            'text' => ['privacy_link' => 'Notice'], 'theme' => ['button_background' => '#fff'], 'buttons' => ['reopen' => 'hidden']]);
        $builtIn = $scripts->consentScript(false, $siteId)['content'];
        self::assertStringContainsString('"privacyPolicyUrl":"https://www.example.test/privacy","text":{"privacyLink":"Notice"},"theme":{"buttonBackground":"#FFFFFF"},"buttons":{"reopen":"hidden"}', $builtIn);
        $sites->saveConsent($siteId, ['privacy_policy_url' => '']);
        self::assertStringNotContainsString('"privacyPolicyUrl":', $scripts->consentScript(false, $siteId)['content']);
        $settings->save($siteId, ['name' => 'Choices </script>']);
        // Invalid built-in controls cannot block the independent option.
        $sites->configuration($siteId)->updateMany(static fn (): array => ['consent_manager' => ['enabled' => 'invalid']]);
        self::assertSame($snippet, $scripts->snippet('public-website-token', true, 'window', 'standalone'));
        self::assertSame($plain, $scripts->standaloneConsentScript(false, $siteId));
        $this->expectException(\InvalidArgumentException::class);
        $scripts->snippet('public-website-token', false, 'window', 'unsupported');
    }

    private function scripts(array $config = []): DropInScripts
    {
        file_put_contents($this->directory.'/config/aggregate.yaml', Yaml::dump($config + [
            'app_host' => 'https://analytics.example.test', 'js_namespace' => 'ExampleAnalytics',
        ]));
        $loader = new AggregateConfigLoader($this->directory, 'test');

        return new DropInScripts($loader, new WebsiteConfigManager($this->directory), new AppBranding($loader, $this->directory), $this->directory, new TagManagerSettings($loader));
    }
}
