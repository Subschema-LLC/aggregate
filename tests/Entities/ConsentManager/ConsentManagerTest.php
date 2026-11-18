<?php

declare(strict_types=1);

namespace App\Tests\Entities\ConsentManager;

use App\Entities\ConsentManager\ConsentManager;
use PHPUnit\Framework\TestCase;

final class ConsentManagerTest extends TestCase
{
    public function testValidatesDefaultConsentSettings(): void
    {
        $validated = ConsentManager::validate([], 'My Website');

        self::assertTrue($validated['enabled']);
        self::assertSame('My Website', $validated['name']);
    }

    public function testRejectsUnsupportedKeys(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Consent manager settings support only');

        ConsentManager::validate(['unknown_key' => 123], 'Site');
    }

    public function testRejectsInvalidName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Consent controls need a YAML boolean enabled setting');

        ConsentManager::validate(['name' => '   '], 'Site');
    }

    public function testProjectsToBrowserConfig(): void
    {
        $validated = [
            'enabled' => true,
            'name' => 'Site',
            'layout' => 'bar',
            'text' => [
                'privacy_link' => 'Privacy Info',
            ],
            'theme' => [
                'background' => '#000000',
            ],
            'buttons' => [
                'reopen' => 'bottom-right',
            ],
            'external_tags' => [
                ['id' => 'gtm', 'name' => 'Google Tag Manager', 'category' => 'analytics'],
            ],
        ];

        $browser = ConsentManager::toBrowserConfig($validated);

        self::assertSame('bar', $browser['layout']);
        self::assertSame('Privacy Info', $browser['text']['privacyLink']);
        self::assertSame('#000000', $browser['theme']['background']);
        self::assertSame('bottom-right', $browser['buttons']['reopen']);
        self::assertCount(1, $browser['externalTags']);
    }
}
