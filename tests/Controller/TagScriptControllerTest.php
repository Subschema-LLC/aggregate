<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\TagScriptController;
use App\Service\AggregateConfigLoader;
use App\Service\TagManagerSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Yaml\Yaml;

final class TagScriptControllerTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/aggregate-tag-script-'.bin2hex(random_bytes(8));
        foreach (['config', 'public', 'var/browser'] as $directory) {
            mkdir($this->projectDir.'/'.$directory, 0700, true);
        }
        copy(dirname(__DIR__, 2).'/public/tag-manager.js', $this->projectDir.'/public/tag-manager.js');
    }

    protected function tearDown(): void
    {
        foreach (['config', 'public', 'var/browser', 'var'] as $directory) {
            foreach (glob($this->projectDir.'/'.$directory.'/*') as $path) {
                unlink($path);
            }
            rmdir($this->projectDir.'/'.$directory);
        }
        rmdir($this->projectDir);
    }

    public function testHeadlessSourceContainsCurrentEnabledTagsButNoPrivateConfiguration(): void
    {
        $script = $this->controller([
            'dashboard_enabled' => false,
            'admin_token' => 'private-admin-token',
            'tag_manager' => ['enabled' => true, 'tags' => [
                ['id' => 'analytics', 'src' => 'https://scripts.example/analytics.js?site=public'],
                ['id' => 'disabled', 'src' => 'https://scripts.example/disabled.js', 'enabled' => false],
            ]],
        ])();

        self::assertSame(200, $script->getStatusCode());
        self::assertSame('application/javascript', $script->headers->get('Content-Type'));
        self::assertSame('source', $script->headers->get('X-Aggregate-Script'));
        self::assertTrue($script->headers->hasCacheControlDirective('must-revalidate'));
        self::assertSame('nosniff', $script->headers->get('X-Content-Type-Options'));
        $content = (string) $script->getContent();
        self::assertStringContainsString('https:\/\/scripts.example\/analytics.js?site=public', $content);
        self::assertStringContainsString('SPDX-License-Identifier:', $content);
        foreach (['private-admin-token', 'disabled.js', TagScriptController::PLACEHOLDER] as $private) {
            self::assertStringNotContainsString($private, $content);
        }
    }

    public function testDisabledManagerContainsNoProviderUrlsAndDefaultsStayDisabled(): void
    {
        $response = $this->controller(['tag_manager' => ['enabled' => false, 'variables' => ['plan' => 'privateSite.plan'], 'tags' => [
            ['id' => 'analytics', 'src' => 'https://scripts.example/analytics.js'],
            ['id' => 'call', 'type' => 'call', 'method' => 'PrivateLibrary.emit', 'args' => ['private-configured-argument']],
        ]]])();
        self::assertSame(['enabled' => false, 'variables' => [], 'tags' => []], $this->publicConfig($response));
        foreach (['scripts.example', 'privateSite', 'PrivateLibrary', 'private-configured-argument'] as $value) {
            self::assertStringNotContainsString($value, (string) $response->getContent());
        }
        self::assertSame(['enabled' => false, 'variables' => [], 'tags' => []], $this->publicConfig($this->controller([])()));
    }

    public function testMinifiedTemplateGetsCurrentConfigurationAndEscapesSensitiveJavaScriptCharacters(): void
    {
        $this->buildFixture();
        $src = 'https://scripts.example/analytics.js?site=</script>&public=$1';
        $settings = ['tag_manager' => ['enabled' => true, 'tags' => [['id' => 'analytics', 'src' => $src]]]];
        $response = $this->controller($settings)(Request::create('/lib.js?min=1'));

        self::assertSame('minified', $response->headers->get('X-Aggregate-Script'));
        $content = (string) $response->getContent();
        self::assertStringContainsString('/*! preserved license */', $content);
        self::assertStringNotContainsString('</script>', $content);
        self::assertStringNotContainsString(TagScriptController::PLACEHOLDER, $content);
        self::assertSame(1, preg_match('/window.fixture=(.*);/', $content, $matches));
        self::assertSame(['enabled' => true, 'variables' => [], 'tags' => [[
            'id' => 'analytics', 'src' => $src, 'consent' => 'analytics', 'trigger' => ['type' => 'dom_ready'], 'type' => 'script',
        ]]], json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR));

        $settings['tag_manager']['tags'][0]['src'] = 'https://scripts.example/updated.js';
        self::assertStringContainsString('updated.js', (string) $this->controller($settings)(Request::create('/lib.js?min=1'))->getContent());
    }

    public function testConfiguredVariablesAndCallArgumentsUseTheSamePublicProjectionInBothVariants(): void
    {
        $this->buildFixture();
        $variables = ['plan' => 'window.siteData.plan', 'category' => 'dataLayer[0].ecommerce.items[0].category'];
        $arguments = ['purchase', ['plan' => ['$var' => 'plan'], 'total_minor' => 1299, 'is_trial' => false,
            'discount' => 0.25, 'missing' => null, 'literal' => '</script><script>doNotExecute()</script>&$1']];
        $input = [
            'dashboard_enabled' => false,
            'mail_password' => 'private-mail-secret',
            'internal_traffic_share_token' => 'private-sharing-secret',
            'unrelated' => ['variables' => 'private-unconfigured-path'],
            'tag_manager' => ['enabled' => true, 'variables' => $variables, 'tags' => [
                ['id' => 'external', 'src' => 'https://scripts.example/{{category}}.js?plan={{plan}}', 'consent' => 'none', 'trigger' => ['type' => 'window_load']],
                ['id' => 'purchase', 'type' => 'call', 'method' => 'Aggregate.emit', 'args' => $arguments, 'trigger' => ['type' => 'data_layer', 'event' => 'order_complete']],
                ['id' => 'disabled', 'type' => 'call', 'method' => 'PrivateDisabled.method', 'args' => ['private-disabled-argument'], 'enabled' => false],
            ]],
        ];
        $source = $this->controller($input)();
        $minified = $this->controller($input)(Request::create('/lib.js?min=1'));

        self::assertSame('source', $source->headers->get('X-Aggregate-Script'));
        self::assertSame('minified', $minified->headers->get('X-Aggregate-Script'));
        self::assertSame($this->publicConfig($source), $this->publicConfig($minified));
        foreach ([$source, $minified] as $response) {
            self::assertSame(200, $response->getStatusCode());
            $config = $this->publicConfig($response);
            self::assertSame(['enabled', 'variables', 'tags'], array_keys($config));
            self::assertSame($variables, $config['variables']);
            self::assertCount(2, $config['tags']);
            self::assertSame('https://scripts.example/{{category}}.js?plan={{plan}}', $config['tags'][0]['src']);
            self::assertSame('none', $config['tags'][0]['consent']);
            self::assertSame('Aggregate.emit', $config['tags'][1]['method']);
            self::assertSame($arguments, $config['tags'][1]['args']);
            self::assertSame(['type' => 'data_layer', 'event' => 'order_complete'], $config['tags'][1]['trigger']);
            self::assertArrayNotHasKey('src', $config['tags'][1]);
            foreach ($config['tags'] as $tag) self::assertArrayNotHasKey('enabled', $tag);
            foreach (['private-', 'PrivateDisabled', '</script>', TagScriptController::PLACEHOLDER] as $private) {
                self::assertStringNotContainsString($private, (string) $response->getContent());
            }
        }
        $input['tag_manager']['variables']['plan'] = 'window.currentPlan';
        $input['tag_manager']['tags'][1]['args'][1]['total_minor'] = 2500;
        $current = $this->publicConfig($this->controller($input)(Request::create('/lib.js?min=1')));
        self::assertSame('window.currentPlan', $current['variables']['plan']);
        self::assertSame(2500, $current['tags'][1]['args'][1]['total_minor']);
    }

    #[DataProvider('invalidBuilds')]
    public function testUnavailableOrStaleBuildUsesConfiguredSource(string $problem): void
    {
        $this->buildFixture();
        $directory = $this->projectDir.'/var/browser';
        if ($problem === 'missing') unlink($directory.'/tag-manager.template.min.js');
        if ($problem === 'missing-manifest') unlink($directory.'/tag-manager-manifest.json');
        if ($problem === 'stale-source') file_put_contents($this->projectDir.'/public/tag-manager.js', "\n// Updated source\n", FILE_APPEND);
        if ($problem === 'stale-template') file_put_contents($directory.'/tag-manager.template.min.js', 'stale');
        if ($problem === 'malformed-manifest') file_put_contents($directory.'/tag-manager-manifest.json', '{broken');
        if ($problem === 'wrong-format') {
            $manifest = json_decode(file_get_contents($directory.'/tag-manager-manifest.json'), true, flags: JSON_THROW_ON_ERROR);
            $manifest['format'] = 99;
            file_put_contents($directory.'/tag-manager-manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        }
        if ($problem === 'missing-placeholder') {
            file_put_contents($directory.'/tag-manager.template.min.js', 'window.fixture={enabled:true};');
            $this->writeManifest();
        }
        $response = $this->controller(['tag_manager' => ['enabled' => true,
            'variables' => ['plan' => 'site.currentPlan'],
            'tags' => [
                ['id' => 'current', 'src' => 'https://scripts.example/current.js'],
                ['id' => 'call', 'type' => 'call', 'method' => 'Acme.track', 'args' => [['$var' => 'plan']], 'trigger' => ['type' => 'window_event', 'event' => 'plan_changed']],
            ],
        ]])(Request::create('/lib.js?min=1'));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('source', $response->headers->get('X-Aggregate-Script'));
        self::assertStringContainsString('current.js', (string) $response->getContent());
        self::assertStringNotContainsString(TagScriptController::PLACEHOLDER, (string) $response->getContent());
        self::assertSame(['plan' => 'site.currentPlan'], $this->publicConfig($response)['variables']);
        self::assertSame('Acme.track', $this->publicConfig($response)['tags'][1]['method']);
        self::assertSame([['$var' => 'plan']], $this->publicConfig($response)['tags'][1]['args']);
    }

    public static function invalidBuilds(): iterable
    {
        foreach (['missing', 'missing-manifest', 'stale-source', 'stale-template', 'malformed-manifest', 'wrong-format', 'missing-placeholder'] as $problem) {
            yield $problem => [$problem];
        }
    }

    public function testMalformedConfigurationFailsClosedWithoutDisclosingParserOrSettings(): void
    {
        foreach (["admin_token: [private-value", "tag_manager:\n  enabled: 'false'", "tag_manager:\n  enabled: true\n  tags:\n    - id: broken\n      src: 'javascript:alert(1)'", "tag_manager: null"] as $yaml) {
            file_put_contents($this->projectDir.'/config/aggregate.yaml', $yaml);
            $response = (new TagScriptController(new TagManagerSettings(new AggregateConfigLoader($this->projectDir, 'test')), $this->projectDir))(Request::create('/lib.js?min=1'));
            self::assertSame(503, $response->getStatusCode());
            self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
            self::assertSame('/* Tag manager configuration is unavailable. No tags loaded. */', $response->getContent());
        }
    }

    public function testMissingOrIncompatibleSourceDoesNotServeAnOutdatedMinifiedBuild(): void
    {
        $this->buildFixture();
        unlink($this->projectDir.'/public/tag-manager.js');
        self::assertSame(503, $this->controller([])(Request::create('/lib.js?min=1'))->getStatusCode());
        file_put_contents($this->projectDir.'/public/tag-manager.js', 'window.tags="incompatible";');
        self::assertSame(503, $this->controller([])(Request::create('/lib.js?min=1'))->getStatusCode());
        file_put_contents($this->projectDir.'/public/tag-manager.js', str_repeat(TagScriptController::SOURCE_DEFAULTS, 2));
        self::assertSame(503, $this->controller([])(Request::create('/lib.js?min=1'))->getStatusCode());
    }

    #[DataProvider('invalidPolicies')]
    public function testInvalidActionsAndVariablesFailClosedEvenWithACurrentBuild(array $policy): void
    {
        $this->buildFixture();
        foreach ([Request::create('/lib.js'), Request::create('/lib.js?min=1')] as $request) {
            $response = $this->controller(['private_admin_setting' => 'private-secret-parser-sentinel', 'tag_manager' => $policy])($request);
            self::assertSame(503, $response->getStatusCode());
            self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
            self::assertFalse($response->headers->has('X-Aggregate-Script'));
            self::assertSame('/* Tag manager configuration is unavailable. No tags loaded. */', $response->getContent());
        }
    }

    public static function invalidPolicies(): iterable
    {
        $safe = ['id' => 'safe', 'src' => 'https://scripts.example/safe.js'];
        $call = ['id' => 'unsafe', 'type' => 'call', 'method' => 'Acme.track'];
        yield 'unsafe method after safe tag' => [['enabled' => true, 'tags' => [$safe, array_replace($call, ['method' => 'Function.call'])]]];
        yield 'expression method' => [['enabled' => true, 'tags' => [$safe, array_replace($call, ['method' => 'Acme.track()'])]]];
        yield 'malformed category' => [['enabled' => true, 'tags' => [$safe, $call + ['consent' => 'all categories']]]];
        yield 'malformed trigger' => [['enabled' => true, 'tags' => [$safe, $call + ['trigger' => ['type' => 'data_layer', 'event' => 'private invalid event']]]]];
        yield 'prototype path' => [['enabled' => true, 'variables' => ['plan' => 'site.__proto__.private'], 'tags' => [$safe]]];
        yield 'executable variable path' => [['enabled' => true, 'variables' => ['plan' => 'site.private()'], 'tags' => [$safe]]];
        yield 'unknown variable ref' => [['enabled' => true, 'tags' => [$safe, $call + ['args' => [['$var' => 'private_missing_alias']]]]]];
        yield 'unknown URL placeholder' => [['enabled' => true, 'tags' => [array_replace($safe, ['src' => 'https://scripts.example/{{private_alias}}.js'])]]];
        yield 'unsafe nested argument key' => [['enabled' => true, 'tags' => [$safe, $call + ['args' => [['private' => ['__proto__' => true]]]]]]];
        yield 'invalid disabled action' => [['enabled' => false, 'tags' => [$safe, array_replace($call, ['enabled' => false, 'method' => 'window.eval'])]]];
    }

    private function publicConfig(Response $response): array
    {
        self::assertSame(1, preg_match('/(?:var tagManagerConfig = |window.fixture=)(.*);/', (string) $response->getContent(), $matches));

        return json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
    }

    private function controller(array $settings): TagScriptController
    {
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump($settings, 6, 2));

        return new TagScriptController(new TagManagerSettings(new AggregateConfigLoader($this->projectDir, 'test')), $this->projectDir);
    }

    private function buildFixture(): void
    {
        file_put_contents($this->projectDir.'/var/browser/tag-manager.template.min.js', "/*! preserved license */\nwindow.fixture=__AGGREGATE_TAG_MANAGER__;\n");
        $this->writeManifest();
    }

    private function writeManifest(): void
    {
        file_put_contents($this->projectDir.'/var/browser/tag-manager-manifest.json', json_encode([
            'format' => 1,
            'sourceSha256' => hash_file('sha256', $this->projectDir.'/public/tag-manager.js'),
            'templateSha256' => hash_file('sha256', $this->projectDir.'/var/browser/tag-manager.template.min.js'),
        ], JSON_THROW_ON_ERROR));
    }
}
