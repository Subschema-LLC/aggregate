<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ConsentAppearance;
use App\Service\ConsentManagerForm;
use PHPUnit\Framework\TestCase;

final class ConsentManagerFormTest extends TestCase
{
    public function testValuesShowSavedOverridesOverDefaults(): void
    {
        $values = ConsentManagerForm::values(['enabled' => true, 'name' => 'Shop', 'text' => ['title' => 'Cookies at {name}'], 'consent_type' => 'implicit_us'], ['analytics', 'marketing']);

        self::assertSame('Cookies at {name}', $values['text']['title']);
        self::assertSame(ConsentAppearance::DEFAULT_TEXT[ConsentAppearance::BUILTIN]['accept'], $values['text']['accept']);
        self::assertSame(['analytics' => 'Enhanced analytics', 'marketing' => 'Marketing'], $values['categories']);
        self::assertSame('reject,accept,save', $values['show']);
        self::assertSame('implicit_us', $values['consent_type']);
        self::assertSame('', $values['close_button']);
        self::assertSame(['analytics'], $values['default_granted']);
        self::assertTrue($values['respect_gpc']);
        self::assertCount(ConsentAppearance::MAX_DETAILS, $values['details']);
    }

    public function testChangesStoreOnlyValuesThatDifferFromTheirDefaults(): void
    {
        $text = array_filter(ConsentAppearance::DEFAULT_TEXT[ConsentAppearance::BUILTIN], 'is_string');
        $form = [
            'enabled' => '1', 'name' => 'Shop', 'privacy_policy_url' => '',
            'text' => ['title' => 'Our choices'] + $text,
            'theme' => array_map('strtolower', ConsentAppearance::THEME_DEFAULTS[ConsentAppearance::BUILTIN]),
            'buttons' => ['show' => 'reject,accept,save', 'reopen' => 'bottom-left'],
            'consent_type' => 'explicit_eu', 'close_button' => 'hidden', 'default_granted' => ['', 'analytics'], 'respect_gpc' => '1',
        ];

        $changes = ConsentManagerForm::changes($form, ['enabled' => true, 'name' => 'Shop']);

        self::assertSame(['title' => 'Our choices'], $changes['text']);
        self::assertNull($changes['theme']);
        self::assertNull($changes['buttons']);
        self::assertNull($changes['consent_type']);
        self::assertSame('hidden', $changes['close_button']);
        self::assertNull($changes['default_granted']);
        self::assertNull($changes['respect_gpc']);
        self::assertNull($changes['regions']);
    }

    public function testUnexpectedFieldsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The consent manager form contained missing or unexpected settings. Nothing was saved.');
        ConsentManagerForm::changes(['enabled' => '1', 'name' => 'Shop', 'surprise' => 'x'], []);
    }

    public function testEveryButtonArrangementIncludesReject(): void
    {
        $arrangements = ConsentManagerForm::arrangements();
        self::assertCount(10, $arrangements);
        foreach (array_keys($arrangements) as $arrangement) {
            self::assertContains('reject', explode(',', $arrangement));
        }
    }
}
