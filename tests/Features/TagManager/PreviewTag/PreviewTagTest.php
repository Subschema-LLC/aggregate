<?php

declare(strict_types=1);

namespace App\Tests\Features\TagManager\PreviewTag;

use App\Features\TagManager\PreviewTag\PreviewTag;
use App\Features\TagManager\PreviewTag\TagReferences;
use PHPUnit\Framework\TestCase;

final class PreviewTagTest extends TestCase
{
    private PreviewTag $feature;

    protected function setUp(): void
    {
        $this->feature = new PreviewTag();
    }

    public function testPlansPreviewWithProbesForScriptVariables(): void
    {
        $settings = [
            'enabled' => true,
            'variables' => ['user' => 'window.user_id'],
            'tags' => [
                [
                    'id' => 'my_tag',
                    'type' => 'script',
                    'src' => 'https://example.com/lib.js?u={{user}}',
                    'enabled' => true,
                    'consent' => 'analytics',
                    'trigger' => ['type' => 'dom_ready'],
                ],
            ],
        ];

        $plan = $this->feature->plan($settings, 'my_tag', 'CustomApp');
        self::assertTrue($plan['config']['enabled']);
        self::assertSame('CustomApp', $plan['config']['namespace']);
        self::assertCount(2, $plan['config']['tags']); // tag + 1 probe
        self::assertArrayHasKey('aggregate-preview-probe-1', $plan['probes']);
        self::assertSame('user', $plan['probes']['aggregate-preview-probe-1']['alias']);
        self::assertSame('window.user_id', $plan['probes']['aggregate-preview-probe-1']['path']);
    }

    public function testPlansPreviewWithChainedStandInTag(): void
    {
        $settings = [
            'enabled' => true,
            'variables' => [],
            'tags' => [
                [
                    'id' => 'base',
                    'type' => 'script',
                    'src' => 'https://example.com/base.js',
                    'enabled' => true,
                    'consent' => 'analytics',
                    'trigger' => ['type' => 'dom_ready'],
                ],
                [
                    'id' => 'chained',
                    'type' => 'call',
                    'method' => 'myLib.run',
                    'args' => [],
                    'enabled' => true,
                    'consent' => 'analytics',
                    'trigger' => ['type' => 'dom_ready'],
                    'after' => 'base',
                ],
            ],
        ];

        $plan = $this->feature->plan($settings, 'chained', 'CustomApp');
        self::assertCount(2, $plan['config']['tags']); // stand-in + chained tag
        self::assertSame('base', $plan['config']['tags'][0]['id']);
        self::assertSame(PreviewTag::STAND_IN_HOST.'base.js', $plan['config']['tags'][0]['src']);
        self::assertStringContainsString('This tag runs after base.', $plan['notes'][0]);
    }

    public function testThrowsWhenTagNotFound(): void
    {
        $settings = ['enabled' => true, 'variables' => [], 'tags' => []];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Choose an action and fill in this tag before testing it.');

        $this->feature->plan($settings, 'nonexistent', 'CustomApp');
    }

    public function testReferencesFindsAllVariablePairs(): void
    {
        $tag = [
            'type' => 'call',
            'args' => [
                'val',
                ['$var' => 'recent_var'],
                ['$var' => 'event_var', 'from' => 'event'],
            ],
        ];

        $refs = TagReferences::references($tag);
        self::assertSame([
            ['recent_var', 'recent'],
            ['event_var', 'event'],
        ], $refs);
    }
}
