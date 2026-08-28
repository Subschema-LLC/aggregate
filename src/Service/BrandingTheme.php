<?php

declare(strict_types=1);

namespace App\Service;

final class BrandingTheme
{
    public const DEFAULT_PRIMARY_COLOR = '#00D1B2';
    public const DEFAULT_ACCENT_COLOR = '#485FC7';
    public const DEFAULT_NAVBAR_COLOR = '#14161A';
    public const DEFAULT_BACKGROUND_COLOR = '#F5F5F5';
    public const DEFAULT_SURFACE_COLOR = '#FFFFFF';
    public const DEFAULT_TEXT_COLOR = '#363636';
    public const DEFAULT_FONT_FAMILY = 'system-ui, -apple-system, BlinkMacSystemFont, Segoe UI, sans-serif';
    public const DEFAULT_HEADING_FONT_FAMILY = self::DEFAULT_FONT_FAMILY;

    /** @var list<string> */
    private const CONFIG_KEYS = [
        'brand_primary_color',
        'brand_accent_color',
        'brand_navbar_color',
        'brand_background_color',
        'brand_surface_color',
        'brand_text_color',
        'brand_font_family',
        'brand_heading_font_family',
    ];

    /** @var list<string> */
    private const UNQUOTED_FONT_FAMILIES = [
        'serif',
        'sans-serif',
        'monospace',
        'cursive',
        'fantasy',
        'system-ui',
        'ui-serif',
        'ui-sans-serif',
        'ui-monospace',
        'ui-rounded',
        'emoji',
        'math',
        'fangsong',
        '-apple-system',
        'blinkmacsystemfont',
    ];

    public function __construct(private readonly AggregateConfigLoader $config) {}

    public static function normalizeHexColor(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if (preg_match('/^#([0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$/D', $value, $matches) !== 1) {
            return null;
        }

        $hex = strtoupper($matches[1]);
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return '#'.$hex;
    }

    /**
     * Normalize a human-editable font stack and produce a CSS-safe equivalent.
     *
     * @return array{value: string, css: string}|null
     */
    public static function normalizeFontFamily(mixed $value): ?array
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '' || strlen($value) > 200) {
            return null;
        }

        $tokens = explode(',', $value);
        if (count($tokens) > 8) {
            return null;
        }

        $families = [];
        $cssFamilies = [];
        foreach ($tokens as $token) {
            $token = trim($token);
            if ($token === '') {
                return null;
            }

            $firstCharacter = $token[0];
            $lastCharacter = $token[strlen($token) - 1];
            if ($firstCharacter === '"' || $firstCharacter === "'") {
                if (strlen($token) < 2 || $lastCharacter !== $firstCharacter) {
                    return null;
                }

                $token = substr($token, 1, -1);
            } elseif ($lastCharacter === '"' || $lastCharacter === "'") {
                return null;
            }

            $family = trim($token);
            if ($family === '' || strlen($family) > 64
                || preg_match('/^[A-Za-z0-9 _-]+$/D', $family) !== 1) {
                return null;
            }

            $family = preg_replace('/ +/', ' ', $family);
            if (!is_string($family)) {
                return null;
            }

            $families[] = $family;
            $cssFamilies[] = in_array(strtolower($family), self::UNQUOTED_FONT_FAMILIES, true)
                ? $family
                : '"'.$family.'"';
        }

        return [
            'value' => implode(', ', $families),
            'css' => implode(', ', $cssFamilies),
        ];
    }

    /**
     * @return array{
     *     primary_color: string,
     *     accent_color: string,
     *     navbar_color: string,
     *     background_color: string,
     *     surface_color: string,
     *     text_color: string,
     *     font_family: string,
     *     heading_font_family: string,
     *     font_family_css: string,
     *     heading_font_family_css: string,
     *     primary_contrast_color: string,
     *     accent_contrast_color: string,
     *     navbar_contrast_color: string,
     *     primary_text_color: string,
     *     accent_text_color: string,
     *     focus_color: string,
     *     contrast_fallback: bool,
     *     primary_color_overridden: bool,
     *     accent_color_overridden: bool,
     *     navbar_color_overridden: bool,
     *     background_color_overridden: bool,
     *     surface_color_overridden: bool,
     *     text_color_overridden: bool,
     *     font_family_overridden: bool,
     *     heading_font_family_overridden: bool,
     *     all_overridden: bool
     * }
     */
    public function toArray(): array
    {
        $primaryColor = $this->resolveColor('brand_primary_color', self::DEFAULT_PRIMARY_COLOR);
        $accentColor = $this->resolveColor('brand_accent_color', self::DEFAULT_ACCENT_COLOR);
        $navbarColor = $this->resolveColor('brand_navbar_color', self::DEFAULT_NAVBAR_COLOR);
        $configuredContrastColors = $this->getConfiguredContrastColors();
        $backgroundColor = $configuredContrastColors['background_color'];
        $surfaceColor = $configuredContrastColors['surface_color'];
        $textColor = $configuredContrastColors['text_color'];
        $contrastFallback = !self::hasReadableTextContrast($textColor, $backgroundColor, $surfaceColor);
        if ($contrastFallback) {
            $backgroundColor = self::DEFAULT_BACKGROUND_COLOR;
            $surfaceColor = self::DEFAULT_SURFACE_COLOR;
            $textColor = self::DEFAULT_TEXT_COLOR;
        }
        $fontFamily = $this->resolveFontFamily('brand_font_family', self::DEFAULT_FONT_FAMILY);
        $headingFontFamily = $this->resolveFontFamily(
            'brand_heading_font_family',
            self::DEFAULT_HEADING_FONT_FAMILY,
        );

        $overrides = [];
        foreach (self::CONFIG_KEYS as $key) {
            $overrides[substr($key, strlen('brand_')).'_overridden'] = $this->hasEnvironmentOverride($key);
        }

        return [
            'primary_color' => $primaryColor,
            'accent_color' => $accentColor,
            'navbar_color' => $navbarColor,
            'background_color' => $backgroundColor,
            'surface_color' => $surfaceColor,
            'text_color' => $textColor,
            'font_family' => $fontFamily['value'],
            'heading_font_family' => $headingFontFamily['value'],
            'font_family_css' => $fontFamily['css'],
            'heading_font_family_css' => $headingFontFamily['css'],
            'primary_contrast_color' => self::contrastColor($primaryColor),
            'accent_contrast_color' => self::contrastColor($accentColor),
            'navbar_contrast_color' => self::contrastColor($navbarColor),
            'primary_text_color' => self::readableBrandTextColor($primaryColor, $textColor, $backgroundColor, $surfaceColor),
            'accent_text_color' => self::readableBrandTextColor($accentColor, $textColor, $backgroundColor, $surfaceColor),
            'focus_color' => self::readableBrandTextColor($accentColor, $textColor, $backgroundColor, $surfaceColor, 3.0),
            'contrast_fallback' => $contrastFallback,
            ...$overrides,
            'all_overridden' => !in_array(false, $overrides, true),
        ];
    }

    /**
     * Return the individually normalized text palette before group contrast fallback.
     *
     * @return array{background_color: string, surface_color: string, text_color: string}
     */
    public function getConfiguredContrastColors(): array
    {
        return [
            'background_color' => $this->resolveColor('brand_background_color', self::DEFAULT_BACKGROUND_COLOR),
            'surface_color' => $this->resolveColor('brand_surface_color', self::DEFAULT_SURFACE_COLOR),
            'text_color' => $this->resolveColor('brand_text_color', self::DEFAULT_TEXT_COLOR),
        ];
    }

    private function resolveColor(string $key, string $default): string
    {
        return self::normalizeHexColor($this->getConfiguredValue($key)) ?? $default;
    }

    /** @return array{value: string, css: string} */
    private function resolveFontFamily(string $key, string $default): array
    {
        $normalized = self::normalizeFontFamily($this->getConfiguredValue($key));

        return $normalized ?? self::normalizeFontFamily($default) ?? [
            'value' => 'sans-serif',
            'css' => 'sans-serif',
        ];
    }

    private function getConfiguredValue(string $key): mixed
    {
        try {
            return $this->config->getWithEnvFallback($key, allowEmpty: true);
        } catch (\Throwable) {
            return null;
        }
    }

    private function hasEnvironmentOverride(string $key): bool
    {
        try {
            return $this->config->hasEnvironmentOverride($key, allowEmpty: true);
        } catch (\Throwable) {
            return false;
        }
    }

    public static function hasReadableTextContrast(
        mixed $textColor,
        mixed $backgroundColor,
        mixed $surfaceColor,
    ): bool {
        $textColor = self::normalizeHexColor($textColor);
        $backgroundColor = self::normalizeHexColor($backgroundColor);
        $surfaceColor = self::normalizeHexColor($surfaceColor);
        if ($textColor === null || $backgroundColor === null || $surfaceColor === null) {
            return false;
        }

        return self::contrastRatio($textColor, $backgroundColor) >= 4.5
            && self::contrastRatio($textColor, $surfaceColor) >= 4.5;
    }

    private static function readableBrandTextColor(
        string $preferredColor,
        string $fallbackColor,
        string $backgroundColor,
        string $surfaceColor,
        float $minimumRatio = 4.5,
    ): string {
        if (self::contrastRatio($preferredColor, $backgroundColor) >= $minimumRatio
            && self::contrastRatio($preferredColor, $surfaceColor) >= $minimumRatio) {
            return $preferredColor;
        }

        return $fallbackColor;
    }

    private static function contrastColor(string $backgroundColor): string
    {
        $luminance = self::relativeLuminance($backgroundColor);
        $blackContrast = ($luminance + 0.05) / 0.05;
        $whiteContrast = 1.05 / ($luminance + 0.05);

        return $blackContrast >= $whiteContrast ? '#000000' : '#FFFFFF';
    }

    private static function contrastRatio(string $firstColor, string $secondColor): float
    {
        $firstLuminance = self::relativeLuminance($firstColor);
        $secondLuminance = self::relativeLuminance($secondColor);
        $lighter = max($firstLuminance, $secondLuminance);
        $darker = min($firstLuminance, $secondLuminance);

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    private static function relativeLuminance(string $color): float
    {
        $red = hexdec(substr($color, 1, 2)) / 255;
        $green = hexdec(substr($color, 3, 2)) / 255;
        $blue = hexdec(substr($color, 5, 2)) / 255;

        $red = $red <= 0.04045 ? $red / 12.92 : (($red + 0.055) / 1.055) ** 2.4;
        $green = $green <= 0.04045 ? $green / 12.92 : (($green + 0.055) / 1.055) ** 2.4;
        $blue = $blue <= 0.04045 ? $blue / 12.92 : (($blue + 0.055) / 1.055) ** 2.4;
        return (0.2126 * $red) + (0.7152 * $green) + (0.0722 * $blue);
    }
}
