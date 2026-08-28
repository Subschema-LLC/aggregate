<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AggregateConfigLoader;
use App\Service\BrandingTheme;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class BrandingThemeTest extends TestCase
{
    private const ENVIRONMENT_KEYS = [
        'BRAND_PRIMARY_COLOR',
        'BRAND_ACCENT_COLOR',
        'BRAND_NAVBAR_COLOR',
        'BRAND_BACKGROUND_COLOR',
        'BRAND_SURFACE_COLOR',
        'BRAND_TEXT_COLOR',
        'BRAND_FONT_FAMILY',
        'BRAND_HEADING_FONT_FAMILY',
    ];

    private string $projectDir;

    /** @var array<string, array{env_exists: bool, env: mixed, server_exists: bool, server: mixed}> */
    private array $savedEnvironment = [];

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/aggregate-theme-test-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->projectDir.'/config', 0700, true));

        foreach (self::ENVIRONMENT_KEYS as $key) {
            $this->savedEnvironment[$key] = [
                'env_exists' => array_key_exists($key, $_ENV),
                'env' => $_ENV[$key] ?? null,
                'server_exists' => array_key_exists($key, $_SERVER),
                'server' => $_SERVER[$key] ?? null,
            ];
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnvironment as $key => $saved) {
            if ($saved['env_exists']) {
                $_ENV[$key] = $saved['env'];
            } else {
                unset($_ENV[$key]);
            }

            if ($saved['server_exists']) {
                $_SERVER[$key] = $saved['server'];
            } else {
                unset($_SERVER[$key]);
            }
        }

        @unlink($this->projectDir.'/config/aggregate.yaml');
        @rmdir($this->projectDir.'/config');
        @rmdir($this->projectDir);
    }

    public function testProvidesSafeDefaultsAndContrastColors(): void
    {
        self::assertSame([
            'primary_color' => '#00D1B2',
            'accent_color' => '#485FC7',
            'navbar_color' => '#14161A',
            'background_color' => '#F5F5F5',
            'surface_color' => '#FFFFFF',
            'text_color' => '#363636',
            'font_family' => 'system-ui, -apple-system, BlinkMacSystemFont, Segoe UI, sans-serif',
            'heading_font_family' => 'system-ui, -apple-system, BlinkMacSystemFont, Segoe UI, sans-serif',
            'font_family_css' => 'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
            'heading_font_family_css' => 'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
            'primary_contrast_color' => '#000000',
            'accent_contrast_color' => '#FFFFFF',
            'navbar_contrast_color' => '#FFFFFF',
            'primary_text_color' => '#363636',
            'accent_text_color' => '#485FC7',
            'focus_color' => '#485FC7',
            'contrast_fallback' => false,
            'primary_color_overridden' => false,
            'accent_color_overridden' => false,
            'navbar_color_overridden' => false,
            'background_color_overridden' => false,
            'surface_color_overridden' => false,
            'text_color_overridden' => false,
            'font_family_overridden' => false,
            'heading_font_family_overridden' => false,
            'all_overridden' => false,
        ], $this->createTheme()->toArray());
    }

    public function testResolvesAndNormalizesYamlValues(): void
    {
        $this->writeConfig([
            'brand_primary_color' => '#abc',
            'brand_accent_color' => '#000000',
            'brand_navbar_color' => '#fff',
            'brand_background_color' => '#012345',
            'brand_surface_color' => '#123456',
            'brand_text_color' => '#fff',
            'brand_font_family' => ' "Open   Sans" , serif, \'UI_Font-2\' ',
            'brand_heading_font_family' => 'system-ui, BlinkMacSystemFont, Segoe UI',
        ]);

        $theme = $this->createTheme()->toArray();

        self::assertSame('#AABBCC', $theme['primary_color']);
        self::assertSame('#000000', $theme['accent_color']);
        self::assertSame('#FFFFFF', $theme['navbar_color']);
        self::assertSame('#012345', $theme['background_color']);
        self::assertSame('#123456', $theme['surface_color']);
        self::assertSame('#FFFFFF', $theme['text_color']);
        self::assertSame('Open Sans, serif, UI_Font-2', $theme['font_family']);
        self::assertSame('"Open Sans", serif, "UI_Font-2"', $theme['font_family_css']);
        self::assertSame('system-ui, BlinkMacSystemFont, Segoe UI', $theme['heading_font_family']);
        self::assertSame('system-ui, BlinkMacSystemFont, "Segoe UI"', $theme['heading_font_family_css']);
        self::assertSame('#000000', $theme['primary_contrast_color']);
        self::assertSame('#FFFFFF', $theme['accent_contrast_color']);
        self::assertSame('#000000', $theme['navbar_contrast_color']);
        self::assertFalse($theme['contrast_fallback']);
        self::assertFalse($theme['all_overridden']);
    }

    public function testUnreadableTextPaletteFallsBackToSafeDefaults(): void
    {
        $this->writeConfig([
            'brand_background_color' => '#FFFFFF',
            'brand_surface_color' => '#EEEEEE',
            'brand_text_color' => '#F0F0F0',
        ]);

        $brandingTheme = $this->createTheme();
        self::assertSame([
            'background_color' => '#FFFFFF',
            'surface_color' => '#EEEEEE',
            'text_color' => '#F0F0F0',
        ], $brandingTheme->getConfiguredContrastColors());

        $theme = $brandingTheme->toArray();

        self::assertSame(BrandingTheme::DEFAULT_BACKGROUND_COLOR, $theme['background_color']);
        self::assertSame(BrandingTheme::DEFAULT_SURFACE_COLOR, $theme['surface_color']);
        self::assertSame(BrandingTheme::DEFAULT_TEXT_COLOR, $theme['text_color']);
        self::assertTrue($theme['contrast_fallback']);
        self::assertFalse(BrandingTheme::hasReadableTextContrast('#F0F0F0', '#FFFFFF', '#EEEEEE'));
        self::assertTrue(BrandingTheme::hasReadableTextContrast('#FFFFFF', '#111827', '#1F2937'));
    }

    public function testConfiguredContrastColorsNormalizeEnvironmentValuesIndividuallyBeforeFallback(): void
    {
        $this->writeConfig([
            'brand_background_color' => '#ABCDEF',
            'brand_surface_color' => '#123456',
            'brand_text_color' => '#FEDCBA',
        ]);
        $_ENV['BRAND_BACKGROUND_COLOR'] = '#123';
        $_ENV['BRAND_SURFACE_COLOR'] = '';
        $_ENV['BRAND_TEXT_COLOR'] = 'invalid';

        self::assertSame([
            'background_color' => '#112233',
            'surface_color' => BrandingTheme::DEFAULT_SURFACE_COLOR,
            'text_color' => BrandingTheme::DEFAULT_TEXT_COLOR,
        ], $this->createTheme()->getConfiguredContrastColors());
    }

    public function testEnvironmentValuesTakePrecedenceAndExplicitEmptyValuesUseDefaults(): void
    {
        $this->writeConfig([
            'brand_primary_color' => '#abcdef',
            'brand_font_family' => 'Yaml Font, serif',
        ]);
        $_ENV['BRAND_PRIMARY_COLOR'] = '#123';
        $_ENV['BRAND_FONT_FAMILY'] = '';

        $theme = $this->createTheme()->toArray();

        self::assertSame('#112233', $theme['primary_color']);
        self::assertSame(BrandingTheme::DEFAULT_FONT_FAMILY, $theme['font_family']);
        self::assertTrue($theme['primary_color_overridden']);
        self::assertTrue($theme['font_family_overridden']);
        self::assertFalse($theme['accent_color_overridden']);
        self::assertFalse($theme['all_overridden']);
    }

    public function testAllEnvironmentKeysAreReportedAsOverridesEvenWhenEmptyOrInvalid(): void
    {
        foreach (self::ENVIRONMENT_KEYS as $key) {
            $_SERVER[$key] = '';
        }
        $_SERVER['BRAND_ACCENT_COLOR'] = 'not a color';

        $theme = $this->createTheme()->toArray();

        self::assertSame(BrandingTheme::DEFAULT_ACCENT_COLOR, $theme['accent_color']);
        self::assertSame(BrandingTheme::DEFAULT_HEADING_FONT_FAMILY, $theme['heading_font_family']);
        foreach (self::ENVIRONMENT_KEYS as $key) {
            $arrayKey = strtolower(substr($key, strlen('BRAND_'))).'_overridden';
            self::assertTrue($theme[$arrayKey]);
        }
        self::assertTrue($theme['all_overridden']);
    }

    public function testHexColorNormalizerAcceptsOnlyThreeOrSixHexDigits(): void
    {
        self::assertSame('#AABBCC', BrandingTheme::normalizeHexColor('#abc'));
        self::assertSame('#00D1B2', BrandingTheme::normalizeHexColor('  #00d1b2  '));

        foreach ([null, 123, '', 'abc', '#12', '#1234', '#12345G', '#1234567', '#fff; color: red'] as $value) {
            self::assertNull(BrandingTheme::normalizeHexColor($value));
        }
    }

    public function testFontFamilyNormalizerProducesCanonicalInputAndSafeCss(): void
    {
        self::assertSame([
            'value' => 'Open Sans, serif, system-ui, -apple-system, BlinkMacSystemFont',
            'css' => '"Open Sans", serif, system-ui, -apple-system, BlinkMacSystemFont',
        ], BrandingTheme::normalizeFontFamily(
            ' "Open   Sans", \'serif\', system-ui, -apple-system, BlinkMacSystemFont ',
        ));

        self::assertSame([
            'value' => 'Font_1-Display, sans-serif',
            'css' => '"Font_1-Display", sans-serif',
        ], BrandingTheme::normalizeFontFamily('Font_1-Display, sans-serif'));
    }

    public function testFontFamilyNormalizerRejectsUnsafeOrOversizedStacks(): void
    {
        $invalidValues = [
            null,
            123,
            '',
            'Arial,',
            ',Arial',
            '"Unclosed Font',
            'Mismatched Font\'',
            '"Mismatched Font\'',
            'Arial; color: red',
            'Arial}body{display:none',
            'url(https://example.test/font)',
            'Font/Name',
            'Fönt',
            "Font\tName",
            implode(',', array_fill(0, 9, 'serif')),
            str_repeat('A', 65),
            str_repeat('A', 201),
        ];

        foreach ($invalidValues as $value) {
            self::assertNull(BrandingTheme::normalizeFontFamily($value));
        }
    }

    public function testInvalidYamlThemeValuesFallBackToDefaults(): void
    {
        $this->writeConfig([
            'brand_primary_color' => 'red',
            'brand_accent_color' => ['#fff'],
            'brand_navbar_color' => null,
            'brand_font_family' => 'Arial; background: red',
            'brand_heading_font_family' => 42,
        ]);

        $theme = $this->createTheme()->toArray();

        self::assertSame(BrandingTheme::DEFAULT_PRIMARY_COLOR, $theme['primary_color']);
        self::assertSame(BrandingTheme::DEFAULT_ACCENT_COLOR, $theme['accent_color']);
        self::assertSame(BrandingTheme::DEFAULT_NAVBAR_COLOR, $theme['navbar_color']);
        self::assertSame(BrandingTheme::DEFAULT_FONT_FAMILY, $theme['font_family']);
        self::assertSame(BrandingTheme::DEFAULT_HEADING_FONT_FAMILY, $theme['heading_font_family']);
    }

    private function createTheme(): BrandingTheme
    {
        return new BrandingTheme(new AggregateConfigLoader($this->projectDir, 'test'));
    }

    /** @param array<string, mixed> $config */
    private function writeConfig(array $config): void
    {
        file_put_contents(
            $this->projectDir.'/config/aggregate.yaml',
            Yaml::dump($config, 4, 2),
        );
    }
}
