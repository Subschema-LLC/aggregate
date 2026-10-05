<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ConsentAppearance;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConsentAppearanceTest extends TestCase
{
    public function testOnlySuppliedGroupsAreReturnedNormalizedAndMappedToCamelCase(): void
    {
        self::assertSame([], ConsentAppearance::validate(['name' => 'Shop'], ConsentAppearance::BUILTIN, 'consent_manager'));

        $validated = ConsentAppearance::validate([
            'text' => [
                'title' => '  {name} privacy  ', 'details' => 'Only paragraph', 'privacy_link' => 'Notice',
                'categories' => ['analytics' => 'Statistics', 'ad-tools' => 'Advertising'],
            ],
            'theme' => ['accent' => '#1a4f8b', 'button_background' => '#fff'],
            'buttons' => ['show' => ['accept', 'reject'], 'reopen' => 'hidden'],
        ], ConsentAppearance::BUILTIN, 'consent_manager');

        self::assertSame([
            'text' => ['title' => '{name} privacy', 'details' => ['Only paragraph'], 'privacy_link' => 'Notice', 'categories' => ['analytics' => 'Statistics', 'ad-tools' => 'Advertising']],
            'theme' => ['accent' => '#1A4F8B', 'button_background' => '#FFFFFF'],
            'buttons' => ['show' => ['accept', 'reject'], 'reopen' => 'hidden'],
        ], $validated);
        self::assertSame([
            'text' => ['title' => '{name} privacy', 'details' => ['Only paragraph'], 'privacyLink' => 'Notice', 'categories' => ['analytics' => 'Statistics', 'ad-tools' => 'Advertising']],
            'theme' => ['accent' => '#1A4F8B', 'buttonBackground' => '#FFFFFF'],
            'buttons' => ['show' => ['accept', 'reject'], 'reopen' => 'hidden'],
        ], ConsentAppearance::browser($validated));
        self::assertSame(['text' => ['details' => []]], ConsentAppearance::validate(['text' => ['details' => []]], ConsentAppearance::BUILTIN, 'x'));
        self::assertSame(['text' => [], 'theme' => [], 'buttons' => []], ConsentAppearance::validate(['text' => [], 'theme' => [], 'buttons' => []], ConsentAppearance::STANDALONE, 'x'));
    }

    public function testEachBannerHasItsOwnTextKeysAndButtons(): void
    {
        $standalone = ConsentAppearance::validate([
            'text' => ['manage' => 'Customize', 'request_opt_out' => 'Do not sell', 'storage_notice' => 'Kept {days} days.'],
            'buttons' => ['show' => ['reject', 'manage']],
        ], ConsentAppearance::STANDALONE, 'standalone_consent');
        self::assertSame('Customize', $standalone['text']['manage']);
        self::assertSame(['reject', 'manage'], $standalone['buttons']['show']);
        self::assertSame(['requestOptOut' => 'Do not sell'], array_intersect_key(ConsentAppearance::browser($standalone)['text'], ['requestOptOut' => true]));

        $this->expectExceptionMessage('consent_manager.text.manage is not a supported text key');
        ConsentAppearance::validate(['text' => ['manage' => 'Customize']], ConsentAppearance::BUILTIN, 'consent_manager');
    }

    #[DataProvider('invalidSettings')]
    public function testInvalidSettingsAreRejectedWithTheirPath(string $banner, array $settings, string $message): void
    {
        try {
            ConsentAppearance::validate($settings, $banner, 'consent_manager');
            self::fail('Expected a validation error.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
    }

    public static function invalidSettings(): iterable
    {
        $builtin = ConsentAppearance::BUILTIN;
        yield 'text list' => [$builtin, ['text' => ['a', 'b']], 'consent_manager.text must be a mapping'];
        yield 'unknown text' => [$builtin, ['text' => ['footer' => 'x']], 'consent_manager.text.footer is not a supported text key'];
        yield 'empty text' => [$builtin, ['text' => ['accept' => '  ']], 'consent_manager.text.accept must be nonempty plain text'];
        yield 'line break' => [$builtin, ['text' => ['description' => "One\nTwo"]], 'without line breaks'];
        yield 'markup is plain text but number is not' => [$builtin, ['text' => ['accept' => 5]], 'consent_manager.text.accept must be nonempty'];
        yield 'long label' => [$builtin, ['text' => ['accept' => str_repeat('a', 121)]], 'at most 120 UTF-8 bytes'];
        yield 'long paragraph' => [$builtin, ['text' => ['description' => str_repeat('é', 501)]], 'at most 1000 UTF-8 bytes'];
        yield 'too many details' => [$builtin, ['text' => ['details' => ['a', 'b', 'c', 'd', 'e']]], 'at most 4 paragraphs'];
        yield 'details map' => [$builtin, ['text' => ['details' => ['x' => 'a']]], 'details must be a list'];
        yield 'bad category' => [$builtin, ['text' => ['categories' => ['Analytics' => 'x']]], 'categories keys must be category names'];
        yield 'reserved category' => [$builtin, ['text' => ['categories' => ['none' => 'x']]], 'categories keys must be category names'];
        yield 'unknown color' => [$builtin, ['theme' => ['shadow' => '#000000']], 'consent_manager.theme.shadow is not a supported color'];
        yield 'named color' => [$builtin, ['theme' => ['accent' => 'red']], 'must be a hex color'];
        yield 'css injection' => [$builtin, ['theme' => ['accent' => '#000; background: url(x)']], 'must be a hex color'];
        yield 'low text contrast' => [$builtin, ['theme' => ['text' => '#999999']], 'text #999999 on background #FFFFFF has a contrast ratio of 2.85:1'];
        yield 'low accent contrast' => [$builtin, ['theme' => ['background' => '#202124', 'text' => '#FFFFFF']], 'accent #2459B8 on background #202124'];
        yield 'low button contrast' => [$builtin, ['theme' => ['button_background' => '#2459B8']], 'button_text #202124 on button_background #2459B8'];
        yield 'invisible button edge' => [$builtin, ['theme' => ['button_border' => '#FFFFFF']], 'buttons need a button_background or button_border with at least 3:1'];
        yield 'buttons list' => [$builtin, ['buttons' => ['reject', 'accept']], 'must be a mapping with only show and reopen'];
        yield 'unknown button' => [$builtin, ['buttons' => ['show' => ['reject', 'manage']]], 'must list each of reject, accept, save at most once'];
        yield 'duplicate button' => [$builtin, ['buttons' => ['show' => ['reject', 'reject', 'accept']]], 'at most once'];
        yield 'no reject' => [$builtin, ['buttons' => ['show' => ['accept', 'save']]], 'must include reject'];
        yield 'no way to allow' => [$builtin, ['buttons' => ['show' => ['reject']]], 'must include accept or save'];
        yield 'standalone no way to allow' => [ConsentAppearance::STANDALONE, ['buttons' => ['show' => ['reject']]], 'must include accept or manage'];
        yield 'reopen' => [$builtin, ['buttons' => ['reopen' => 'top']], 'reopen must be one of bottom-left, bottom-right, hidden'];
    }

    public function testDefaultThemesPassTheirOwnContrastChecksAndMatchTheStylesheets(): void
    {
        foreach ([ConsentAppearance::BUILTIN => 'public/consent.css', ConsentAppearance::STANDALONE => 'micro-consent-dropins/css/consent-ui.css'] as $banner => $stylesheet) {
            $defaults = ConsentAppearance::THEME_DEFAULTS[$banner];
            self::assertSame(['theme' => $defaults], ConsentAppearance::validate(['theme' => $defaults], $banner, 'x'));
            $css = strtoupper((string) file_get_contents(dirname(__DIR__, 2).'/'.$stylesheet));
            foreach ($defaults as $key => $color) {
                $variable = ($banner === ConsentAppearance::BUILTIN ? '--AC-' : '--MC-').strtoupper(str_replace('_', '-', $key));
                self::assertMatchesRegularExpression('/'.preg_quote($variable, '/').'\s*:\s*'.preg_quote($color, '/').'\b/', $css, $stylesheet.' default for '.$key);
            }
        }
    }

    public function testDefaultWordingMatchesBothBannerScriptsAndCoversEveryTextKey(): void
    {
        foreach ([ConsentAppearance::BUILTIN => 'public/consent.js', ConsentAppearance::STANDALONE => 'micro-consent-dropins/js/consent-ui.js'] as $banner => $script) {
            $source = (string) file_get_contents(dirname(__DIR__, 2).'/'.$script);
            self::assertSame(1, preg_match('/var defaultText = (\{.*?\n  \});/s', $source, $match), $script);
            $expected = ConsentAppearance::browser(['text' => ConsentAppearance::DEFAULT_TEXT[$banner]])['text'];
            self::assertSame($expected, json_decode($match[1], true, flags: JSON_THROW_ON_ERROR), $script);
            self::assertSame(array_keys(ConsentAppearance::TEXT[$banner]), array_keys(ConsentAppearance::DEFAULT_TEXT[$banner]));
            // Every default is itself valid configuration.
            self::assertSame(['text' => ConsentAppearance::DEFAULT_TEXT[$banner]], ConsentAppearance::validate(['text' => ConsentAppearance::DEFAULT_TEXT[$banner]], $banner, 'x'));
        }
    }

    public function testPrivacyPolicyUrlAcceptsEmptyOrHttps(): void
    {
        self::assertSame('', ConsentAppearance::privacyPolicyUrl('  ', 'p'));
        self::assertSame('https://www.example.com/privacy#cookies', ConsentAppearance::privacyPolicyUrl(' https://www.example.com/privacy#cookies ', 'p'));
        foreach (['http://www.example.com/privacy', 'https://user:pass@example.com/', 'javascript:alert(1)', '/privacy', 'https://exa mple.com', 5] as $invalid) {
            try {
                ConsentAppearance::privacyPolicyUrl($invalid, 'consent_manager.privacy_policy_url');
                self::fail('Accepted '.var_export($invalid, true));
            } catch (\InvalidArgumentException $e) {
                self::assertStringStartsWith('consent_manager.privacy_policy_url must be empty or an absolute HTTPS URL', $e->getMessage());
            }
        }
    }
}
