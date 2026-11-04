<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\TagManagerPreview;
use App\Service\TagManagerScript;
use App\Service\TagManagerScriptUnavailable;
use App\Service\TagManagerSettings;
use PHPUnit\Framework\TestCase;

final class TagManagerPreviewTest extends TestCase
{
    public function testPlanServesOnlyTheTestedTagEnabledWithAProbeForEachVariableItReads(): void
    {
        $settings = TagManagerSettings::validate([
            'enabled' => false,
            'variables' => ['program' => 'program_name', 'campus' => 'campus.name', 'unused' => 'x'],
            'tags' => [
                ['id' => 'other', 'src' => 'https://scripts.example/other.js'],
                ['id' => 'request-info', 'type' => 'event', 'enabled' => false, 'consent' => 'analytics', 'trigger' => ['type' => 'data_layer', 'event' => 'form_submit'],
                    'event' => ['eventName' => 'request_info', 'customData' => ['program' => ['$var' => 'program'], 'campus' => ['$var' => 'campus', 'from' => 'event'], 'again' => ['$var' => 'program'], 'fixed' => 'yes']]],
            ],
        ]);

        $plan = (new TagManagerPreview())->plan($settings, 'request-info', 'Optin');

        self::assertSame(['enabled' => true, 'variables' => $settings['variables'], 'namespace' => 'Optin'], array_diff_key($plan['config'], ['tags' => true]));
        self::assertSame(['request-info', 'aggregate-preview-probe-1', 'aggregate-preview-probe-2'], array_column($plan['config']['tags'], 'id'), 'other tags are left out');
        self::assertArrayNotHasKey('enabled', $plan['config']['tags'][0], 'the browser config has no enabled flag; the tag is served');
        self::assertSame([
            'aggregate-preview-probe-1' => ['alias' => 'program', 'from' => 'recent', 'path' => 'program_name'],
            'aggregate-preview-probe-2' => ['alias' => 'campus', 'from' => 'event', 'path' => 'campus.name'],
        ], $plan['probes']);
        $probe = $plan['config']['tags'][2];
        self::assertSame(['type' => 'call', 'method' => TagManagerPreview::PROBE_METHOD, 'args' => ['aggregate-preview-probe-2', ['$var' => 'campus', 'from' => 'event']], 'consent' => 'none', 'trigger' => ['type' => 'data_layer', 'event' => 'form_submit']], array_diff_key($probe, ['id' => true]));
        self::assertSame([], $plan['custom']);
        self::assertSame(['id' => 'request-info', 'type' => 'event', 'consent' => 'analytics', 'trigger' => ['type' => 'data_layer', 'event' => 'form_submit'], 'method' => null], $plan['tag']);
        self::assertStringContainsString('tag manager is disabled', $plan['notes'][0]);
        self::assertStringContainsString('This tag is disabled', $plan['notes'][1]);
    }

    public function testAChainedTagWaitsForAStandInThatFinishesAtOnce(): void
    {
        $settings = TagManagerSettings::validate(['enabled' => true, 'variables' => [], 'tags' => [
            ['id' => 'tracker', 'src' => 'https://analytics.example/aggregate.js'],
            ['id' => 'pixel', 'type' => 'call', 'method' => 'Acme.track', 'args' => ['view'], 'consent' => 'marketing', 'after' => 'tracker'],
        ]]);

        $plan = (new TagManagerPreview())->plan($settings, 'pixel', 'Aggregate');

        self::assertSame(['id' => 'tracker', 'type' => 'script', 'src' => TagManagerPreview::STAND_IN_HOST.'tracker.js', 'consent' => 'none', 'trigger' => ['type' => 'dom_ready']], $plan['config']['tags'][0]);
        self::assertSame('tracker', $plan['config']['tags'][1]['after']);
        self::assertSame('Acme.track', $plan['tag']['method']);
        self::assertSame(['This tag runs after tracker. The test assumes tracker has run and finished.'], $plan['notes']);
    }

    public function testReferencesComeFromScriptUrlsAndNestedArgumentsButNotCustomCode(): void
    {
        self::assertSame([['plan', 'recent'], ['region', 'recent']], TagManagerPreview::references(['type' => 'script', 'src' => 'https://s.example/{{plan}}.js?r={{region}}&p={{plan}}']));
        self::assertSame([['sku', 'event'], ['plan', 'recent']], TagManagerPreview::references(['type' => 'call', 'args' => ['buy', ['items' => [['sku' => ['$var' => 'sku', 'from' => 'event']]], 'plan' => ['$var' => 'plan']]]]));
        self::assertSame([['plan', 'recent'], ['plan', 'event']], TagManagerPreview::references(['type' => 'event', 'event' => ['eventName' => ['$var' => 'plan'], 'customData' => ['a' => ['$var' => 'plan', 'from' => 'event'], 'b' => ['$var' => 'plan']]]]), 'each alias and source once');
        self::assertSame([], TagManagerPreview::references(['type' => 'custom', 'code' => "tag.emit('x', {plan: tag.get('plan')});"]));
    }

    public function testProbesStopAtTheRuntimeTagLimitAndSaySo(): void
    {
        $variables = [];
        $data = [];
        for ($i = 0; $i < 25; ++$i) {
            $variables['v'.$i] = 'value'.$i;
            $data['p'.$i] = ['$var' => 'v'.$i];
        }
        $settings = TagManagerSettings::validate(['enabled' => true, 'variables' => $variables, 'tags' => [
            ['id' => 'many', 'type' => 'event', 'event' => ['eventName' => 'many', 'customData' => $data]],
        ]]);

        $plan = (new TagManagerPreview())->plan($settings, 'many', 'Aggregate');

        self::assertCount(TagManagerSettings::MAX_TAGS, $plan['config']['tags']);
        self::assertCount(TagManagerSettings::MAX_TAGS - 1, $plan['probes']);
        self::assertSame('The test checks the first 19 variables this tag uses.', end($plan['notes']));
    }

    public function testCustomJavaScriptIsReplacedByAStandInAndItsCodeIsNeverCompiledIn(): void
    {
        $settings = TagManagerSettings::validate(['enabled' => true, 'variables' => [], 'tags' => [
            ['id' => 'custom-one', 'type' => 'custom', 'code' => "document.title = 'secret-marker';"],
        ]]);
        $preview = new TagManagerPreview(dirname(__DIR__, 2));
        $plan = $preview->plan($settings, 'custom-one', 'Aggregate', false);

        $script = $preview->script($plan['config'], $plan['custom']);

        self::assertStringNotContainsString('secret-marker', $script);
        self::assertStringContainsString('"custom-one": function (tag) { \'use strict\'; if (typeof self.AggregateTagPreviewCustom === \'function\') self.AggregateTagPreviewCustom(tag.id); }', $script);
        self::assertStringNotContainsString(TagManagerScript::SOURCE_DEFAULTS, $script);
        self::assertStringContainsString('var tagManagerConfig = {"enabled":true', $script);
        self::assertStringContainsString('turned off for this installation', implode(' ', $plan['notes']));
    }

    public function testScriptEscapesSettingsForAScriptContext(): void
    {
        $script = (new TagManagerPreview(dirname(__DIR__, 2)))->script(['enabled' => true, 'variables' => [], 'tags' => [['id' => 'a', 'type' => 'event', 'event' => ['eventName' => '</script><b>'], 'consent' => 'none']]], []);

        self::assertStringNotContainsString('</script>', $script);
        self::assertStringContainsString(trim((string) json_encode('</script>', JSON_HEX_TAG), '"'), $script, 'encoded as \\u003C\\/script\\u003E');
    }

    public function testUnknownTagsAndAMissingSourceAreRefused(): void
    {
        try {
            (new TagManagerPreview())->plan(TagManagerSettings::DEFAULTS, 'missing', 'Aggregate');
            self::fail('An unknown tag is refused.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Choose an action and fill in this tag before testing it.', $e->getMessage());
        }

        $this->expectException(TagManagerScriptUnavailable::class);
        (new TagManagerPreview(sys_get_temp_dir().'/aggregate-no-such-project'))->script(['enabled' => true, 'tags' => []], []);
    }
}
