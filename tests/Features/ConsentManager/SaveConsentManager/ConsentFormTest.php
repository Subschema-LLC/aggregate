<?php

declare(strict_types=1);

namespace App\Tests\Features\ConsentManager\SaveConsentManager;

use App\Features\ConsentManager\SaveConsentManager\ConsentForm;
use PHPUnit\Framework\TestCase;

final class ConsentFormTest extends TestCase
{
    public function testConvertsFormChangesAndDropsDefaults(): void
    {
        $form = [
            'enabled' => '1',
            'name' => 'Acme Site',
            'privacy_policy_url' => 'https://example.com/privacy',
            'precheck_categories' => ['analytics'],
            'text' => [],
            'theme' => [],
            'buttons' => [
                'show' => 'reject,accept,save',
                'reopen' => 'bottom-left',
            ],
            'consent_type' => 'explicit_eu',
            'close_button' => '',
            'default_granted' => ['analytics'],
            'respect_gpc' => '1',
            'layout' => 'card',
        ];

        $changes = ConsentForm::changes($form, []);

        self::assertTrue($changes['enabled']);
        self::assertSame('Acme Site', $changes['name']);
        self::assertSame('https://example.com/privacy', $changes['privacy_policy_url']);
        self::assertSame(['analytics'], $changes['precheck_categories']);
        self::assertNull($changes['text']);
        self::assertNull($changes['theme']);
        self::assertNull($changes['buttons']);
        self::assertNull($changes['consent_type'], 'default consent type is dropped');
        self::assertNull($changes['default_granted'], 'default granted is dropped');
        self::assertNull($changes['layout'], 'default layout is dropped');
    }

    public function testReconstructsValuesFromSavedAndCategories(): void
    {
        $saved = [
            'enabled' => true,
            'name' => 'Acme Site',
            'privacy_policy_url' => 'https://example.com/privacy',
            'categories' => ['marketing'],
        ];

        $values = ConsentForm::values($saved, ['analytics', 'marketing']);

        self::assertTrue($values['enabled']);
        self::assertSame('Acme Site', $values['name']);
        self::assertSame('https://example.com/privacy', $values['privacy_policy_url']);
        self::assertArrayHasKey('analytics', $values['categories']);
        self::assertArrayHasKey('marketing', $values['categories']);
        self::assertSame('card', $values['layout']);
    }

    public function testArrangementsOffersValidButtonCombinations(): void
    {
        $arrangements = ConsentForm::arrangements();

        self::assertArrayHasKey('reject,accept,save', $arrangements);
        self::assertArrayHasKey('reject,accept', $arrangements);
        self::assertArrayHasKey('reject,save', $arrangements);
    }

    public function testAGlobalPrivacyControlValueOtherThanOnOrOffSavesNothing(): void
    {
        foreach (['', 'x', 'true', 'on', '2'] as $value) {
            try {
                ConsentForm::changes(['enabled' => '1', 'name' => 'Shop', 'respect_gpc' => $value], []);
                self::fail('A respect_gpc value of '.json_encode($value).' was accepted.');
            } catch (\InvalidArgumentException $e) {
                self::assertSame(
                    'The Global Privacy Control setting must be on or off. Nothing was saved.',
                    $e->getMessage(),
                );
            }
        }
    }
}
