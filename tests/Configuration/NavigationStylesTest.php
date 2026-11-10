<?php

declare(strict_types=1);

namespace App\Tests\Configuration;

use PHPUnit\Framework\TestCase;

/**
 * The brand navbar color is operator-configured and stays the same in light and
 * dark page themes, so navbar tints must derive from its computed contrast
 * color. Hard-coded white tints disappear on light navbars.
 */
final class NavigationStylesTest extends TestCase
{
    private const NAVBAR_STYLESHEETS = [
        'assets/styles/components/layout/navigation.css',
        'assets/styles/components/layout/quick_search.css',
        'assets/styles/components/branding/navbar_surface.css',
    ];

    public function testNavbarStylesDoNotHardCodeWhiteTints(): void
    {
        foreach (self::NAVBAR_STYLESHEETS as $stylesheet) {
            $css = (string) file_get_contents(dirname(__DIR__, 2).'/'.$stylesheet);

            self::assertDoesNotMatchRegularExpression('/rgba\(\s*255\s*,\s*255\s*,\s*255\s*,/i', $css, $stylesheet);
            self::assertStringContainsString('var(--app-brand-navbar-contrast)', $css, $stylesheet);
        }
    }

    public function testSearchOutlineAndPlaceholderUseStrongNavbarContrastMixes(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2).'/assets/styles/components/layout/quick_search.css');

        self::assertSame(1, preg_match('/\.quick-search-input \{[^}]*border: 1px solid color-mix\(in srgb, var\(--app-brand-navbar-contrast\) (\d+)%/s', $css, $border));
        self::assertSame(1, preg_match('/\.quick-search-input::placeholder \{[^}]*color: color-mix\(in srgb, var\(--app-brand-navbar-contrast\) (\d+)%/s', $css, $placeholder));
        // Measured in a browser: 80% keeps the outline at 3:1 or better and 92%
        // keeps placeholders near 4.5:1 across light, dark and mid-tone navbars.
        self::assertGreaterThanOrEqual(80, (int) $border[1]);
        self::assertGreaterThanOrEqual(92, (int) $placeholder[1]);
    }

    public function testSignInCardFieldsUseTheMeasuredNavbarContrastMixes(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2).'/assets/styles/components/branding/navbar_surface.css');

        self::assertSame(1, preg_match('/\.app-navbar-surface \.select select \{[^}]*border-color: color-mix\(in srgb, var\(--app-brand-navbar-contrast\) (\d+)%/s', $css, $border));
        self::assertSame(1, preg_match('/\.app-navbar-surface \.textarea::placeholder \{[^}]*color: color-mix\(in srgb, var\(--app-brand-navbar-contrast\) (\d+)%/s', $css, $placeholder));
        // The same thresholds as the navigation search field above.
        self::assertGreaterThanOrEqual(80, (int) $border[1]);
        self::assertGreaterThanOrEqual(92, (int) $placeholder[1]);
        // Field text sits on the unmixed navigation color, so it keeps the
        // contrast BrandingTheme guarantees for the navigation contrast color.
        self::assertSame(1, preg_match('/\.app-navbar-surface \.select select \{[^}]*background-color: var\(--app-brand-navbar\);/s', $css));
    }
}
