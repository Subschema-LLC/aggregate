<?php

declare(strict_types=1);

namespace App\Entities\ConsentManager;

/**
 * Validates theme colors and WCAG 2 contrast ratios for consent banners.
 */
final class ConsentTheme
{
    public const THEME_DEFAULTS = [
        'builtin' => [
            'background' => '#FFFFFF', 'text' => '#202124', 'accent' => '#2459B8',
            'border' => '#202124', 'button_background' => '#FFFFFF',
            'button_text' => '#202124', 'button_border' => '#202124',
        ],
        'standalone' => [
            'background' => '#FFFFFF', 'text' => '#17212D', 'accent' => '#174F85',
            'border' => '#B7C1CE', 'button_background' => '#FFFFFF',
            'button_text' => '#174F85', 'button_border' => '#174F85',
        ],
    ];

    /**
     * @return array<string, string>
     */
    public static function validate(mixed $theme, string $banner, string $path): array
    {
        if (!is_array($theme) || (array_keys($theme) !== [] && array_is_list($theme))) {
            throw new \InvalidArgumentException(
                $path.' must be a mapping of color names to #RRGGBB values.',
            );
        }
        $defaults = self::THEME_DEFAULTS[$banner] ?? [];
        $result = [];
        foreach ($theme as $key => $value) {
            if (!is_string($key) || !array_key_exists($key, $defaults)) {
                throw new \InvalidArgumentException(
                    $path.'.'.$key.' is not a supported color. Supported colors: '
                    .implode(', ', array_keys($defaults)).'.',
                );
            }
            $color = self::normalizeHexColor($value);
            if ($color === null) {
                throw new \InvalidArgumentException(
                    $path.'.'.$key.' must be a hex color such as \'#1A4F8B\' (quote it in YAML).',
                );
            }
            $result[$key] = $color;
        }
        $colors = array_replace($defaults, $result);
        foreach ([
            ['text', 'background', 4.5],
            ['accent', 'background', 4.5],
            ['button_text', 'button_background', 4.5],
        ] as [$foreground, $background, $minimum]) {
            $ratio = self::contrastRatio($colors[$foreground], $colors[$background]);
            if ($ratio < $minimum) {
                throw new \InvalidArgumentException(sprintf(
                    '%s: %s %s on %s %s has a contrast ratio of %.2f:1; '
                    .'readable text needs at least %.1f:1.',
                    $path,
                    $foreground,
                    $colors[$foreground],
                    $background,
                    $colors[$background],
                    $ratio,
                    $minimum,
                ));
            }
        }
        $edge = max(
            self::contrastRatio($colors['button_background'], $colors['background']),
            self::contrastRatio($colors['button_border'], $colors['background']),
        );
        if ($edge < 3.0) {
            throw new \InvalidArgumentException(sprintf(
                '%s: buttons need a button_background or button_border with at least 3:1 '
                .'contrast against the background (currently %.2f:1), so their edges are visible.',
                $path,
                $edge,
            ));
        }

        return $result;
    }

    public static function normalizeHexColor(mixed $color): ?string
    {
        if (!is_string($color)) {
            return null;
        }
        $color = trim($color);
        if (preg_match('/^#([0-9a-fA-F]{3})$/D', $color, $matches) === 1) {
            return strtoupper(sprintf(
                '#%s%s%s%s%s%s',
                $matches[1][0],
                $matches[1][0],
                $matches[1][1],
                $matches[1][1],
                $matches[1][2],
                $matches[1][2],
            ));
        }
        if (preg_match('/^#([0-9a-fA-F]{6})$/D', $color, $matches) === 1) {
            return strtoupper($color);
        }

        return null;
    }

    public static function contrastRatio(string $firstColor, string $secondColor): float
    {
        $firstLuminance = self::relativeLuminance($firstColor);
        $secondLuminance = self::relativeLuminance($secondColor);
        $lighter = max($firstLuminance, $secondLuminance);
        $darker = min($firstLuminance, $secondLuminance);

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    public static function relativeLuminance(string $color): float
    {
        $red = hexdec(substr($color, 1, 2)) / 255;
        $green = hexdec(substr($color, 3, 2)) / 255;
        $blue = hexdec(substr($color, 5, 2)) / 255;

        $r = $red <= 0.04045 ? $red / 12.92 : (($red + 0.055) / 1.055) ** 2.4;
        $g = $green <= 0.04045 ? $green / 12.92 : (($green + 0.055) / 1.055) ** 2.4;
        $b = $blue <= 0.04045 ? $blue / 12.92 : (($blue + 0.055) / 1.055) ** 2.4;

        return (0.2126 * $r) + (0.7152 * $g) + (0.0722 * $b);
    }
}
