<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Controller\ConsentScriptController;
use App\Service\AggregateConfigLoader;
use App\Service\AppBranding;
use App\Service\DropInScripts;
use App\Service\SiteScriptConfig;
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
        file_put_contents($this->directory.'/config/websites.yaml', Yaml::dump(['websites' => [
            ['name' => 'Example', 'domain' => 'example.test', 'token' => 'public-website-token'],
        ]]));
    }

    protected function tearDown(): void
    {
        [$_ENV, $_SERVER] = $this->environment;
        (new Filesystem())->remove($this->directory);
    }

    public function testSnippetSelectsOnlyRegisteredTokenAndLoadsConsentBeforeTrackerAndOptionalTags(): void
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
        self::assertLessThan(strpos($withTags, '/aggregate.js'), strpos($withTags, '/consent.js'));
        self::assertLessThan(strpos($withTags, '/lib.js'), strpos($withTags, '/aggregate.js'));
        self::assertSame(3, substr_count($withTags, 'defer referrerpolicy="no-referrer"'));
        self::assertStringNotContainsString('private-', $withTags);
        $this->expectException(\InvalidArgumentException::class);
        $scripts->snippet('unregistered-token');
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
        self::assertSame('source', $response->headers->get('X-Aggregate-Script'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertTrue($response->headers->hasCacheControlDirective('must-revalidate'));
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
        $template = '/*! SPDX-License-Identifier: AGPL-3.0-only */ var testConfig=__AGGREGATE_CONSENT_CONFIG__;';
        file_put_contents($this->directory.'/var/browser/consent.template.min.js', $template);
        file_put_contents($this->directory.'/var/browser/consent-manifest.json', json_encode([
            'format' => 1, 'sourceSha256' => hash('sha256', $source), 'templateSha256' => hash('sha256', $template),
        ]));
        $built = $scripts->consentScript(true);
        self::assertTrue($built['minified']);
        self::assertStringContainsString('"namespace":"ExampleAnalytics"', $built['content']);
        self::assertStringNotContainsString('__AGGREGATE_CONSENT_CONFIG__', $built['content']);
        file_put_contents($this->directory.'/var/browser/consent.template.min.js', $template.'corrupt');
        self::assertFalse($scripts->consentScript(true)['minified']);
        file_put_contents($this->directory.'/public/consent.js', $source."\n// source updated\n");
        file_put_contents($this->directory.'/var/browser/consent.template.min.js', $template);
        self::assertFalse($scripts->consentScript(true)['minified']);
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

    private function scripts(array $config = []): DropInScripts
    {
        file_put_contents($this->directory.'/config/aggregate.yaml', Yaml::dump($config + [
            'app_host' => 'https://analytics.example.test', 'js_namespace' => 'ExampleAnalytics',
        ]));
        $loader = new AggregateConfigLoader($this->directory, 'test');

        return new DropInScripts($loader, new WebsiteConfigManager($this->directory), new AppBranding($loader, $this->directory), $this->directory, new TagManagerSettings($loader));
    }
}
