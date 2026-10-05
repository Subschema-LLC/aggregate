<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AggregateConfigLoader;
use App\Service\TagManagerSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class TagManagerSettingsTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/aggregate-tags-'.bin2hex(random_bytes(8));
        mkdir($this->projectDir.'/config', 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->projectDir.'/config/*') as $path) {
            unlink($path);
        }
        rmdir($this->projectDir.'/config');
        rmdir($this->projectDir);
    }

    public function testDefaultIsDisabledAndOnlyEnabledPublicTagsReachTheBrowser(): void
    {
        self::assertSame(TagManagerSettings::DEFAULTS, $this->settings([])->all());
        $settings = $this->settings([
            'mail_password' => 'private-password',
            'tag_manager' => ['enabled' => true, 'tags' => [self::tag(), self::tag('disabled', false)]],
        ]);
        self::assertSame(['enabled' => true, 'variables' => [], 'tags' => [[
            'id' => 'analytics', 'src' => 'https://scripts.example/analytics.js', 'consent' => 'analytics',
            'trigger' => ['type' => 'dom_ready'], 'type' => 'script',
        ]]], $settings->toBrowserConfig());
        self::assertSame(['enabled' => false, 'variables' => [], 'tags' => []], $this->settings([
            'tag_manager' => ['enabled' => false, 'tags' => [self::tag()]],
        ])->toBrowserConfig());
    }

    public function testYamlUrlWithHtmlAmpersandsIsServedWithQuerySeparatorsAndStaysUniqueOnce(): void
    {
        $tracker = 'https://analytics.example/aggregate.js?min=1&endpoint=https%3A%2F%2Fanalytics.example%2Fapi%2Freceive&token=public-token&consent=0';
        $settings = $this->settings(['tag_manager' => ['enabled' => true, 'tags' => [
            ['id' => 'tracker', 'src' => str_replace('&', '&amp;', $tracker), 'consent' => 'none'],
        ]]]);
        self::assertSame($tracker, $settings->toBrowserConfig()['tags'][0]['src']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unique HTTPS URL');
        TagManagerSettings::validate(['tags' => [
            ['id' => 'encoded', 'src' => str_replace('&', '&amp;', $tracker)],
            ['id' => 'plain', 'src' => $tracker],
        ]]);
    }

    public function testOmittedPerTagEnabledDefaultsToTrueWithoutEnablingManager(): void
    {
        $tag = self::tag();
        unset($tag['enabled'], $tag['consent'], $tag['trigger'], $tag['type']);
        self::assertSame(['enabled' => false, 'variables' => [], 'tags' => [self::tag()]], TagManagerSettings::validate(['tags' => [$tag]]));
    }

    public function testExplicitNoConsentAndNamedCategoriesRemainDistinctAndReachTheCmp(): void
    {
        $settings = $this->settings(['tag_manager' => ['enabled' => true, 'tags' => [
            array_replace(self::tag('essential'), ['consent' => 'none']),
            array_replace(self::tag('marketing'), ['consent' => 'marketing']),
            array_replace(self::tag('functional'), ['consent' => 'functional']),
            array_replace(self::tag('another'), ['consent' => 'marketing']),
            array_replace(self::tag('disabled', false), ['consent' => 'advertising']),
        ]]]);
        self::assertSame(['none', 'marketing', 'functional', 'marketing'], array_column($settings->toBrowserConfig()['tags'], 'consent'));
        self::assertSame(['analytics', 'functional', 'marketing'], $settings->consentCategories());
        $settings->save(['enabled' => false, 'tags' => [array_replace(self::tag(), ['consent' => 'marketing'])]]);
        self::assertSame(['analytics'], $settings->consentCategories());
    }

    public function testSavingPreservesOtherEnvironmentAndUnrelatedSettings(): void
    {
        $original = [
            'mail_password' => 'private-password',
            'environments' => [
                'test' => ['app_host' => 'https://analytics.example', 'updates_branch' => 'uat'],
                'prod' => ['tag_manager' => ['enabled' => false, 'tags' => [self::tag('production')]]],
            ],
        ];
        $settings = $this->settings($original);
        $updated = ['enabled' => true, 'variables' => [], 'tags' => [self::tag()]];
        $settings->save($updated);
        $original['environments']['test']['tag_manager'] = $updated;
        self::assertSame($original, Yaml::parseFile($this->projectDir.'/config/aggregate.yaml'));
        self::assertSame($updated, $settings->all());
    }

    public function testEnvironmentSpecificYamlReceivesChangesAndEnvironmentVariableDoesNotOverrideMapping(): void
    {
        $settings = $this->settings(['tag_manager' => ['enabled' => true, 'tags' => [self::tag('main')]]]);
        file_put_contents($this->projectDir.'/config/aggregate_test.yaml', "tag_manager:\n  enabled: false\n");
        $before = file_get_contents($this->projectDir.'/config/aggregate.yaml');
        $environment = [$_ENV, $_SERVER];
        try {
            $_ENV['TAG_MANAGER'] = 'enabled: true';
            $_SERVER['TAG_MANAGER'] = 'enabled: true';
            self::assertFalse($settings->all()['enabled']);
            $settings->save(['enabled' => true, 'tags' => [self::tag('environment')]]);
            self::assertSame('environment', Yaml::parseFile($this->projectDir.'/config/aggregate_test.yaml')['tag_manager']['tags'][0]['id']);
            self::assertSame($before, file_get_contents($this->projectDir.'/config/aggregate.yaml'));
        } finally {
            [$_ENV, $_SERVER] = $environment;
        }
    }

    public function testVariableTemplatesAndCallsKeepExplicitActionsTriggersAndLiteralTypes(): void
    {
        $variables = ['plan' => 'window.siteData.plan', 'category' => 'dataLayer[0].ecommerce.items[0].category'];
        $input = ['enabled' => true, 'variables' => $variables, 'tags' => [
            ['id' => 'helper', 'src' => 'https://scripts.example/{{category}}/helper.js?plan={{plan}}', 'consent' => 'none', 'trigger' => ['type' => 'window_load']],
            ['id' => 'purchase', 'type' => 'call', 'method' => 'Aggregate.emit',
                'args' => ['purchase', ['plan' => ['$var' => 'plan'], 'total_minor' => 1299, 'discount' => 0.1, 'is_trial' => false, 'missing' => null]],
                'consent' => 'analytics', 'trigger' => ['type' => 'data_layer', 'event' => 'order_complete']],
            ['id' => 'disabled', 'type' => 'call', 'method' => 'Acme.disabled', 'enabled' => false, 'consent' => 'marketing'],
        ]];
        $settings = $this->settings(['admin_token' => 'private-admin-secret', 'mail_password' => 'private-password', 'tag_manager' => $input]);
        $browser = $settings->toBrowserConfig();

        self::assertSame(['enabled', 'variables', 'tags'], array_keys($browser));
        self::assertSame($variables, $browser['variables']);
        self::assertCount(2, $browser['tags']);
        self::assertSame('script', $browser['tags'][0]['type']);
        self::assertSame($input['tags'][0]['src'], $browser['tags'][0]['src']);
        self::assertSame(['type' => 'window_load'], $browser['tags'][0]['trigger']);
        self::assertSame('call', $browser['tags'][1]['type']);
        self::assertSame('Aggregate.emit', $browser['tags'][1]['method']);
        self::assertSame($input['tags'][1]['args'], $browser['tags'][1]['args']);
        self::assertSame(['type' => 'data_layer', 'event' => 'order_complete'], $browser['tags'][1]['trigger']);
        self::assertArrayNotHasKey('src', $browser['tags'][1]);
        self::assertArrayNotHasKey('method', $browser['tags'][0]);
        foreach ($browser['tags'] as $tag) self::assertArrayNotHasKey('enabled', $tag);
        self::assertSame(['analytics'], $settings->consentCategories());
        self::assertStringNotContainsString('private-', json_encode($browser, JSON_THROW_ON_ERROR));

        $settings->save($input);
        $saved = Yaml::parseFile($this->projectDir.'/config/aggregate.yaml');
        self::assertSame('private-admin-secret', $saved['admin_token']);
        self::assertSame('private-password', $saved['mail_password']);
        self::assertSame($variables, $saved['tag_manager']['variables']);
        self::assertSame([], $saved['tag_manager']['tags'][2]['args']);
        self::assertSame(['type' => 'dom_ready'], $saved['tag_manager']['tags'][2]['trigger']);
        self::assertSame($browser, $settings->toBrowserConfig());
    }

    #[DataProvider('validTriggers')]
    public function testEachTriggerIsPreservedForThePublicAction(array $trigger): void
    {
        $input = array_replace(self::tag(), ['trigger' => $trigger]);
        $settings = $this->settings(['tag_manager' => ['enabled' => true, 'tags' => [$input]]]);
        self::assertSame($trigger, $settings->all()['tags'][0]['trigger']);
        self::assertSame($trigger, $settings->toBrowserConfig()['tags'][0]['trigger']);
    }

    public static function validTriggers(): iterable
    {
        yield [['type' => 'dom_ready']];
        yield [['type' => 'window_load']];
        yield [['type' => 'document_event', 'event' => 'checkout:complete']];
        yield [['type' => 'window_event', 'event' => 'account.updated']];
        yield [['type' => 'data_layer', 'event' => 'order_complete']];
    }

    #[DataProvider('invalidSettings')]
    public function testInvalidYamlSettingsAreRejected(mixed $value): void
    {
        $settings = $this->settings(['tag_manager' => $value]);
        $this->expectException(\InvalidArgumentException::class);
        $settings->all();
    }

    public static function invalidSettings(): iterable
    {
        yield 'null mapping' => [null];
        yield 'string mapping' => ['enabled'];
        yield 'string enabled' => [['enabled' => 'false']];
        yield 'integer enabled' => [['enabled' => 1]];
        yield 'null enabled' => [['enabled' => null]];
        yield 'unknown setting' => [['consent_required' => false]];
        yield 'tags string' => [['tags' => 'script']];
        yield 'tags map' => [['tags' => ['named' => self::tag()]]];
        yield 'too many' => [['tags' => array_map(static fn (int $index): array => self::tag('tag'.$index), range(0, 20))]];
        yield 'tag scalar' => [['tags' => ['script']]];
        yield 'missing id' => [['tags' => [['src' => 'https://scripts.example/script.js']]]];
        yield 'duplicate id' => [['tags' => [self::tag(), self::tag()]]];
        yield 'duplicate src' => [['tags' => [self::tag(), array_replace(self::tag(), ['id' => 'another'])]]];
        yield 'unsafe id' => [['tags' => [array_replace(self::tag(), ['id' => 'tag with spaces'])]]];
        yield 'tag string enabled' => [['tags' => [array_replace(self::tag(), ['enabled' => 'false'])]]];
        yield 'tag null enabled' => [['tags' => [array_replace(self::tag(), ['enabled' => null])]]];
        yield 'consent bypass' => [['tags' => [self::tag() + ['consent_required' => false]]]];
        yield 'inline code' => [['tags' => [self::tag() + ['code' => 'alert(1)']]]];
        foreach ([null, false, [], '', 'Marketing', 'none required', '_private', str_repeat('a', 33)] as $index => $consent) {
            yield 'invalid consent '.$index => [['tags' => [array_replace(self::tag(), ['consent' => $consent])]]];
        }
        foreach ([null, [], '', 'http://scripts.example/script.js', '//scripts.example/script.js', '/script.js', 'javascript:alert(1)', 'data:text/javascript,alert(1)', 'https://user:pass@scripts.example/a.js', 'https://user@scripts.example/a.js', 'https://scripts.example/a.js#fragment', 'https://scripts.example/a.js#', "https://scripts.example/a.js\n", 'https://scripts.example/a\\b.js', 'https://scripts.example/a b.js', 'https://scripts.example/'.str_repeat('a', 2048)] as $index => $src) {
            yield 'unsafe source '.$index => [['tags' => [array_replace(self::tag(), ['src' => $src])]]];
        }
        foreach ([null, 'dom_ready', ['type' => 'click'], ['type' => 'dom_ready', 'event' => 'view'],
            ['type' => 'document_event'], ['type' => 'window_event', 'event' => 'bad event'],
            ['type' => 'data_layer', 'event' => null], ['type' => 'document_event', 'event' => 'view', 'selector' => '#private']] as $index => $trigger) {
            yield 'invalid trigger '.$index => [['tags' => [array_replace(self::tag(), ['trigger' => $trigger])]]];
        }
        foreach ([null, [], 'inline'] as $index => $type) {
            yield 'invalid type '.$index => [['tags' => [array_replace(self::tag(), ['type' => $type])]]];
        }
        foreach (['window.fetch', 'Acme.constructor', 'Acme.emit()', 'Acme["emit"]', 'emit'] as $method) {
            yield 'invalid method '.$method => [['tags' => [['id' => 'call', 'type' => 'call', 'method' => $method]]]];
        }
        yield 'script with method' => [['tags' => [self::tag() + ['method' => 'Acme.emit']]]];
        yield 'script with args' => [['tags' => [self::tag() + ['args' => []]]]];
        yield 'call with src' => [['tags' => [['id' => 'call', 'type' => 'call', 'method' => 'Acme.emit', 'src' => 'https://scripts.example/a.js']]]];
        yield 'call missing method' => [['tags' => [['id' => 'call', 'type' => 'call']]]];
        yield 'non-list args' => [['tags' => [['id' => 'call', 'type' => 'call', 'method' => 'Acme.emit', 'args' => ['event' => 'view']]]]];
        yield 'unknown argument ref' => [['tags' => [['id' => 'call', 'type' => 'call', 'method' => 'Acme.emit', 'args' => [['$var' => 'missing']]]]]];
        yield 'extra argument ref field' => [['variables' => ['plan' => 'site.plan'], 'tags' => [['id' => 'call', 'type' => 'call', 'method' => 'Acme.emit', 'args' => [['$var' => 'plan', 'fallback' => true]]]]]];
        yield 'prototype argument map' => [['tags' => [['id' => 'call', 'type' => 'call', 'method' => 'Acme.emit', 'args' => [['constructor' => 'private']]]]]];
        foreach ([null, 'window.plan', ['plan' => 'site.plan()'], ['plan' => 'site.__proto__.plan'], ['plan' => 'dataLayer[-1].event'], ['constructor' => 'site.plan']] as $index => $variables) {
            yield 'invalid variables '.$index => [['variables' => $variables, 'tags' => [self::tag()]]];
        }
        foreach (['https://{{plan}}.example/a.js', 'https://scripts.example/{{missing}}.js', 'https://scripts.example/{{ plan }}.js', 'https://scripts.example/{{plan}.js'] as $index => $src) {
            yield 'invalid template '.$index => [['variables' => ['plan' => 'site.plan'], 'tags' => [array_replace(self::tag(), ['src' => $src])]]];
        }
    }

    public function testInvalidSaveAndMalformedExistingPolicyCannotOverwriteConfiguration(): void
    {
        foreach ([['enabled' => 'false'], ['tags' => [self::tag() + ['consent_required' => false]]]] as $invalid) {
            $settings = $this->settings(['tag_manager' => ['enabled' => false]]);
            $before = file_get_contents($this->projectDir.'/config/aggregate.yaml');
            try {
                $settings->save($invalid);
                self::fail('Invalid policy was saved.');
            } catch (\InvalidArgumentException) {
                self::assertSame($before, file_get_contents($this->projectDir.'/config/aggregate.yaml'));
            }
        }
        $settings = $this->settings(['tag_manager' => ['enabled' => 'false']]);
        $before = file_get_contents($this->projectDir.'/config/aggregate.yaml');
        try {
            $settings->save(TagManagerSettings::DEFAULTS);
            self::fail('Malformed existing policy was replaced.');
        } catch (\InvalidArgumentException) {
            self::assertSame($before, file_get_contents($this->projectDir.'/config/aggregate.yaml'));
        }
    }

    public function testMalformedApplicationYamlCannotPublishTags(): void
    {
        file_put_contents($this->projectDir.'/config/aggregate.yaml', 'private_key: [not-valid');
        $this->expectException(\RuntimeException::class);
        (new TagManagerSettings(new AggregateConfigLoader($this->projectDir, 'test')))->toBrowserConfig();
    }

    private function settings(array $values): TagManagerSettings
    {
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump($values, 8, 2));

        return new TagManagerSettings(new AggregateConfigLoader($this->projectDir, 'test'));
    }

    private static function tag(string $id = 'analytics', bool $enabled = true): array
    {
        return ['id' => $id, 'src' => 'https://scripts.example/'.$id.'.js', 'enabled' => $enabled, 'consent' => 'analytics', 'trigger' => ['type' => 'dom_ready'], 'type' => 'script'];
    }
}
