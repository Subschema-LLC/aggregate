<?php

declare(strict_types=1);

namespace App\Tests\Features\TagManager\SaveTagManager;

use App\Entities\TagManager\CustomCodeSyntax;
use App\Entities\TagManager\CustomDataProperties;
use App\Features\TagManager\SaveTagManager\TagForm;
use App\Integrations\TagManager\CustomDataSettingsProperties;
use App\Integrations\TagManager\PeastCustomCodeSyntax;
use PHPUnit\Framework\TestCase;

final class TagFormTest extends TestCase
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
            }
        };
    }

    public function testExtractsSettingsFromFormSubmission(): void
    {
        $submitted = [
            'enabled' => '1',
            'variables' => [
                ['alias' => 'userId', 'path' => 'window.user_id'],
            ],
            'tags' => [
                [
                    'id' => 'my_tag',
                    'type' => 'call',
                    'method' => 'myLib.track',
                    'args_json' => '["click", {"$var": "userId"}]',
                    'enabled' => '1',
                    'consent' => 'analytics',
                    'trigger' => ['type' => 'dom_ready'],
                ],
            ],
        ];

        $settings = TagForm::settings($submitted, $this->properties, $this->syntax);
        self::assertTrue($settings['enabled']);
        self::assertSame(['userId' => 'window.user_id'], $settings['variables']);
        self::assertCount(1, $settings['tags']);
        self::assertSame('my_tag', $settings['tags'][0]['id']);
        self::assertSame(['click', ['$var' => 'userId']], $settings['tags'][0]['args']);
    }

    public function testRebuildsDraftRowsOnRejectedForm(): void
    {
        $form = [
            'enabled' => '0',
            'variables' => [
                ['alias' => 'invalid alias!', 'path' => 'window.user'],
            ],
            'tags' => [
                [
                    'id' => '123_invalid',
                    'type' => 'script',
                    'src' => 'http://insecure.com',
                    'enabled' => '1',
                    'consent' => 'analytics',
                ],
            ],
        ];

        [$draftSettings, $variableRows] = TagForm::draft($form, ['enabled' => true, 'tags' => []], []);
        self::assertFalse($draftSettings['enabled']);
        self::assertSame('invalid alias!', $variableRows[0]['alias']);
        self::assertSame('123_invalid', $draftSettings['tags'][0]['id']);
        self::assertSame('http://insecure.com', $draftSettings['tags'][0]['src']);
    }

    public function testIdentifiesBlankRow(): void
    {
        self::assertTrue(TagForm::isBlankRow([
            'id' => '',
            'src' => '',
            'method' => '',
            'code' => '',
            'args_json' => '[]',
            'event_json' => '',
        ]));

        self::assertFalse(TagForm::isBlankRow([
            'id' => 'filled',
            'src' => '',
            'method' => '',
            'code' => '',
            'args_json' => '[]',
            'event_json' => '',
        ]));
    }

    /** "Test this tag" reads the form with these checks, so it refuses what saving refuses. */
    public function testWithTheInstalledChecksTheFormRefusesWhatSavingRefuses(): void
    {
        $properties = new CustomDataSettingsProperties();
        $syntax = new PeastCustomCodeSyntax();
        $rows = [
            'syntax error' => ['type' => 'custom', 'code' => 'foo('],
            'eval' => ['type' => 'custom', 'code' => 'eval("1");'],
            'reserved property' => [
                'type' => 'event',
                'event_json' => '{"eventName":"signup","customData":{"__proto__":"x"}}',
            ],
            'malformed property' => [
                'type' => 'event',
                'event_json' => '{"eventName":"signup","customData":{"has space":"x"}}',
            ],
        ];
        foreach ($rows as $case => $row) {
            $form = ['enabled' => '1', 'variables' => [], 'tags' => [
                $row + ['id' => 'checked', 'enabled' => '1', 'consent' => 'analytics'],
            ]];
            try {
                TagForm::settings($form, $properties, $syntax);
                self::fail('The form accepted a tag with a '.$case.'.');
            } catch (\InvalidArgumentException $e) {
                self::assertStringStartsWith('Tag checked:', $e->getMessage(), $case);
            }
        }
    }
}
