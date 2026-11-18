<?php

declare(strict_types=1);

namespace App\Tests\Features\TagManager\SaveTagManager;

use App\Entities\TagManager\CustomCodeSyntax;
use App\Entities\TagManager\CustomDataProperties;
use App\Entities\TagManager\TagManager;
use App\Entities\TagManager\TagManagers;
use App\Features\TagManager\SaveTagManager\SaveTagManager;
use PHPUnit\Framework\TestCase;

final class SaveTagManagerTest extends TestCase
{
    /** @var array<string, array<string, mixed>> */
    private array $stored = [];
    private CustomDataProperties $properties;
    private CustomCodeSyntax $syntax;

    protected function setUp(): void
    {
        $this->stored = [];
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
            public function validateSyntax(
                string $code,
                string $path,
                ?string $cacheDirectory = null,
            ): void {
            }
        };
    }

    private function store(): TagManagers
    {
        return new class($this->stored) implements TagManagers {
            /** @param array<string, array<string, mixed>> $stored */
            public function __construct(private array &$stored)
            {
            }

            public function all(?string $siteId = null): array
            {
                $key = $siteId ?? '_global';

                return $this->stored[$key] ?? TagManager::DEFAULTS;
            }

            public function save(array $settings, ?string $siteId = null): void
            {
                $key = $siteId ?? '_global';
                $this->stored[$key] = $settings;
            }
        };
    }

    private function feature(): SaveTagManager
    {
        return new SaveTagManager($this->store(), $this->properties, $this->syntax);
    }

    public function testSavesValidSettingsAndReturnsWarnings(): void
    {
        $settings = [
            'enabled' => true,
            'variables' => ['userId' => 'user.id'],
            'tags' => [
                [
                    'id' => 'analytics_script',
                    'type' => 'script',
                    'src' => 'https://example.com/analytics.js',
                    'enabled' => true,
                    'consent' => 'analytics',
                    'trigger' => ['type' => 'dom_ready'],
                ],
            ],
        ];

        $warnings = $this->feature()->save($settings, 'site-123');
        self::assertSame([], $warnings);
        self::assertTrue($this->stored['site-123']['enabled']);
        self::assertSame(['userId' => 'user.id'], $this->stored['site-123']['variables']);
        self::assertCount(1, $this->stored['site-123']['tags']);
    }

    public function testRefusesNewCustomScriptsWhenDisabled(): void
    {
        $settings = [
            'enabled' => true,
            'variables' => [],
            'tags' => [
                [
                    'id' => 'custom_one',
                    'type' => 'custom',
                    'code' => 'console.log("hello");',
                    'enabled' => true,
                    'consent' => 'analytics',
                    'trigger' => ['type' => 'dom_ready'],
                ],
            ],
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Custom JavaScript tags are turned off for this installation');

        $this->feature()->save($settings, 'site-1', false);
    }

    public function testAllowsExistingCustomScriptsWhenDisabled(): void
    {
        $this->stored['site-1'] = [
            'enabled' => true,
            'variables' => [],
            'tags' => [
                [
                    'id' => 'custom_one',
                    'type' => 'custom',
                    'code' => 'console.log("hello");',
                    'enabled' => true,
                    'consent' => 'analytics',
                    'trigger' => ['type' => 'dom_ready'],
                ],
            ],
        ];

        $settings = $this->stored['site-1'];
        $warnings = $this->feature()->save($settings, 'site-1', false);
        self::assertSame([], $warnings);
        self::assertEquals($settings['tags'], $this->stored['site-1']['tags']);
    }
}
