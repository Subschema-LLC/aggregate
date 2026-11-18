<?php

declare(strict_types=1);

namespace App\Tests\Features\TagManager\TagForm;

use App\Features\TagManager\TagForm\TagForm;
use PHPUnit\Framework\TestCase;

final class TagFormTest extends TestCase
{
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

        $settings = TagForm::settings($submitted);
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
}
