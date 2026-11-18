<?php

declare(strict_types=1);

namespace App\Tests\Entities\TagManager;

use App\Entities\TagManager\CustomCodeSyntax;
use App\Entities\TagManager\CustomDataProperties;
use App\Entities\TagManager\TagManager;
use PHPUnit\Framework\TestCase;

final class TagManagerTest extends TestCase
{
    private CustomDataProperties $properties;
    private CustomCodeSyntax $syntax;

    protected function setUp(): void
    {
        $this->properties = new class implements CustomDataProperties {
            public function isValidKey(string $key): bool
            {
                return preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,63}$/D', $key) === 1;
            }

            public function isBuiltin(string $key): bool
            {
                return in_array($key, ['page_sequence', 'entry_channel'], true);
            }
        };

        $this->syntax = new class implements CustomCodeSyntax {
            public function validateSyntax(mixed $code, string $path): void
            {
                if (is_string($code) && str_contains($code, 'throw new Error')) {
                    throw new \InvalidArgumentException($path.' contains error.');
                }
            }
        };
    }

    public function testValidatesEmptySettings(): void
    {
        $result = TagManager::validate(TagManager::DEFAULTS, $this->properties, $this->syntax);
        self::assertFalse($result['enabled']);
        self::assertSame([], $result['variables']);
        self::assertSame([], $result['tags']);
    }

    public function testRejectsUnknownRootKeys(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('tag_manager must contain only enabled, variables and tags.');

        TagManager::validate(['enabled' => true, 'extra' => 123], $this->properties, $this->syntax);
    }

    public function testRejectsNonBooleanEnabled(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('tag_manager.enabled must be a YAML boolean (true or false).');

        TagManager::validate(['enabled' => 'yes', 'variables' => [], 'tags' => []], $this->properties, $this->syntax);
    }

    public function testComputesConsentCategoriesAndServedTags(): void
    {
        $settings = [
            'enabled' => true,
            'variables' => [],
            'tags' => [
                [
                    'id' => 'first',
                    'type' => 'script',
                    'src' => 'https://example.com/one.js',
                    'enabled' => true,
                    'consent' => 'marketing',
                    'trigger' => ['type' => 'dom_ready'],
                ],
                [
                    'id' => 'second',
                    'type' => 'script',
                    'src' => 'https://example.com/two.js',
                    'enabled' => true,
                    'consent' => 'analytics',
                    'trigger' => ['type' => 'dom_ready'],
                ],
                [
                    'id' => 'disabled',
                    'type' => 'script',
                    'src' => 'https://example.com/three.js',
                    'enabled' => false,
                    'consent' => 'advertising',
                    'trigger' => ['type' => 'dom_ready'],
                ],
            ],
        ];

        $served = TagManager::servedTags($settings, true);
        self::assertCount(2, $served);
        self::assertSame(['first', 'second'], array_column($served, 'id'));

        $categories = TagManager::consentCategories($settings, true);
        self::assertSame(['analytics', 'marketing'], $categories);
    }

    public function testToBrowserConfigIncludesNamespaceWhenCustomOrEventTagsExist(): void
    {
        $settings = [
            'enabled' => true,
            'variables' => ['plan' => 'user.plan'],
            'tags' => [
                [
                    'id' => 'ev',
                    'type' => 'event',
                    'event' => ['eventName' => 'test_ev'],
                    'enabled' => true,
                    'consent' => 'analytics',
                    'trigger' => ['type' => 'dom_ready'],
                ],
            ],
        ];

        $browserConfig = TagManager::toBrowserConfig($settings, true, 'CustomTracker');
        self::assertTrue($browserConfig['enabled']);
        self::assertSame(['plan' => 'user.plan'], $browserConfig['variables']);
        self::assertSame('CustomTracker', $browserConfig['namespace']);
        self::assertArrayNotHasKey('enabled', $browserConfig['tags'][0]);
    }

    public function testEmitsWarningsForCallTagsWithProblems(): void
    {
        $settings = [
            'enabled' => true,
            'variables' => [],
            'tags' => [
                [
                    'id' => 'warn_tag',
                    'type' => 'call',
                    'method' => 'Aggregate.emit',
                    'args' => ['login', ['customData' => ['method' => 'pwd']]],
                    'enabled' => true,
                    'consent' => 'analytics',
                    'trigger' => ['type' => 'dom_ready'],
                ],
            ],
        ];

        $warnings = TagManager::emitWarnings($settings, ['Aggregate']);
        self::assertCount(1, $warnings);
        self::assertStringContainsString('Remove the "customData" wrapper', $warnings[0]);
    }
}
