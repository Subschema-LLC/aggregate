<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\ParameterBag;

/**
 * The Branding page's form: validates a submission and returns the YAML
 * changes it makes and the logo action it asks for. Each error is an
 * InvalidArgumentException whose message the page shows. Values set by
 * environment variables are left out, and a field whose submitted value
 * equals the value the page showed (its _original_ field) is not written,
 * so saving does not pin defaults.
 */
final class BrandingSettingsForm
{
    /**
     * @return array{settings: array<string, string>, upload: ?UploadedFile, remove_logo: bool}
     */
    public static function fromSubmission(ParameterBag $values, mixed $uploadedLogo, AggregateConfigLoader $config): array
    {
        $brandName = trim((string) $values->get('brand_name', ''));
        $logoText = trim((string) $values->get('brand_logo_text', ''));
        $removeLogo = $values->getBoolean('remove_brand_logo');
        $brandNameOverridden = $config->hasEnvironmentOverride('brand_name', allowEmpty: true);
        $logoTextOverridden = $config->hasEnvironmentOverride('brand_logo_text', allowEmpty: true);
        $brandNameChanged = !$brandNameOverridden
            && self::submittedValueChanged($values, 'brand_name', $brandName);
        $logoTextChanged = !$logoTextOverridden
            && self::submittedValueChanged($values, 'brand_logo_text', $logoText);
        $themeSettings = [];

        if (!$brandNameOverridden && $brandName === '') {
            throw new \InvalidArgumentException('Brand name is required.');
        }

        if (!$brandNameOverridden && (preg_match('//u', $brandName) !== 1
            || mb_strlen($brandName) > 100
            || preg_match('/[\x00-\x1F\x7F]/u', $brandName) === 1)) {
            throw new \InvalidArgumentException('Brand name must be at most 100 characters and cannot contain control characters.');
        }

        if (!$logoTextOverridden && (preg_match('//u', $logoText) !== 1
            || mb_strlen($logoText) > 100
            || preg_match('/[\x00-\x1F\x7F]/u', $logoText) === 1)) {
            throw new \InvalidArgumentException('Logo text must be at most 100 characters and cannot contain control characters.');
        }

        $themeColors = [
            'brand_primary_color' => 'Primary color',
            'brand_accent_color' => 'Accent color',
            'brand_navbar_color' => 'Navigation color',
            'brand_background_color' => 'Page background color',
            'brand_surface_color' => 'Surface color',
            'brand_text_color' => 'Text color',
        ];
        foreach ($themeColors as $key => $label) {
            if (!$values->has($key)
                || $config->hasEnvironmentOverride($key, allowEmpty: true)) {
                continue;
            }

            $color = BrandingTheme::normalizeHexColor($values->get($key));
            if ($color === null) {
                throw new \InvalidArgumentException($label.' must use #RGB or #RRGGBB hexadecimal notation.');
            }

            $originalColor = BrandingTheme::normalizeHexColor(
                $values->get('_original_'.$key),
            );
            if ($values->has('_original_'.$key) && $color === $originalColor) {
                continue;
            }
            $themeSettings[$key] = $color;
        }

        $themeFonts = [
            'brand_font_family' => 'Interface font stack',
            'brand_heading_font_family' => 'Heading font stack',
        ];
        foreach ($themeFonts as $key => $label) {
            if (!$values->has($key)
                || $config->hasEnvironmentOverride($key, allowEmpty: true)) {
                continue;
            }

            $font = BrandingTheme::normalizeFontFamily($values->get($key));
            if ($font === null) {
                throw new \InvalidArgumentException($label.' must contain one to eight comma-separated local/system font family names using only letters, numbers, spaces, underscores, or hyphens.');
            }

            $originalFont = BrandingTheme::normalizeFontFamily(
                $values->get('_original_'.$key),
            );
            if ($values->has('_original_'.$key)
                && $originalFont !== null
                && $font['value'] === $originalFont['value']) {
                continue;
            }
            $themeSettings[$key] = $font['value'];
        }

        $contrastKeys = [
            'brand_background_color' => 'background_color',
            'brand_surface_color' => 'surface_color',
            'brand_text_color' => 'text_color',
        ];
        if (array_intersect_key($themeSettings, $contrastKeys) !== []) {
            $currentTheme = (new BrandingTheme($config))->getConfiguredContrastColors();
            $proposedContrastColors = [];
            foreach ($contrastKeys as $configKey => $themeKey) {
                $proposedContrastColors[$themeKey] = $themeSettings[$configKey] ?? $currentTheme[$themeKey];
            }
            if (!BrandingTheme::hasReadableTextContrast(
                $proposedContrastColors['text_color'],
                $proposedContrastColors['background_color'],
                $proposedContrastColors['surface_color'],
            )) {
                throw new \InvalidArgumentException('Text color must have at least 4.5:1 contrast against both the page background and surface colors.');
            }
        }

        if ($uploadedLogo !== null && !$uploadedLogo instanceof UploadedFile) {
            throw new \InvalidArgumentException('The submitted logo upload is invalid.');
        }

        if ($uploadedLogo instanceof UploadedFile && $removeLogo) {
            throw new \InvalidArgumentException('Choose either a new logo or remove the current logo, not both.');
        }

        $hasLogoMutation = $uploadedLogo instanceof UploadedFile || $removeLogo;
        if ($hasLogoMutation && $config->hasEnvironmentOverride('brand_logo_path', allowEmpty: true)) {
            throw new \InvalidArgumentException('The logo path is controlled by BRAND_LOGO_PATH. Change or remove that environment override before using logo upload controls.');
        }

        $settings = $themeSettings;
        if ($brandNameChanged) {
            $settings['brand_name'] = $brandName;
        }
        if ($logoTextChanged) {
            $settings['brand_logo_text'] = $logoText;
        }

        return [
            'settings' => $settings,
            'upload' => $uploadedLogo instanceof UploadedFile ? $uploadedLogo : null,
            'remove_logo' => $removeLogo,
        ];
    }

    private static function submittedValueChanged(ParameterBag $values, string $key, string $value): bool
    {
        $originalKey = '_original_'.$key;
        if (!$values->has($originalKey)) {
            return true;
        }

        return trim((string) $values->get($originalKey)) !== $value;
    }
}
