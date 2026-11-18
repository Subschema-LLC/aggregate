<?php

declare(strict_types=1);

namespace App\Service;

use App\Entities\ConsentManager\ConsentButtons;
use App\Entities\ConsentManager\ConsentCategories as EntityConsentCategories;
use App\Entities\ConsentManager\ConsentExternalTags;
use App\Entities\ConsentManager\ConsentManager;
use App\Entities\ConsentManager\ConsentRegions;
use App\Entities\ConsentManager\ConsentText;
use App\Entities\ConsentManager\ConsentTheme;

/**
 * Adapter delegating to App\Entities\ConsentManager entities.
 */
final class ConsentAppearance
{
    public const BUILTIN = 'builtin';
    public const STANDALONE = 'standalone';

    public const KEYS = ConsentManager::KEYS;
    public const REOPEN_POSITIONS = ConsentButtons::REOPEN_POSITIONS;
    public const LAYOUTS = ConsentButtons::LAYOUTS;
    public const DEFAULT_LAYOUT = ConsentButtons::DEFAULT_LAYOUT;

    public const MAX_EXTERNAL_TAGS = ConsentExternalTags::MAX_EXTERNAL_TAGS;
    public const MAX_DETAILS = 4;
    public const MAX_REGIONS = ConsentRegions::MAX_REGIONS;
    public const CONSENT_TYPES = ConsentRegions::CONSENT_TYPES;
    public const DEFAULT_CONSENT_TYPE = ConsentRegions::DEFAULT_CONSENT_TYPE;
    public const LEGACY_CONSENT_TYPES = ConsentRegions::LEGACY_CONSENT_TYPES;
    public const CLOSE_BUTTONS = ConsentRegions::CLOSE_BUTTONS;
    public const DEFAULT_GRANTED = ConsentRegions::DEFAULT_GRANTED;
    public const MAX_CATEGORIES = EntityConsentCategories::MAX_CATEGORIES;

    public const TEXT = ConsentText::TEXT;
    public const DEFAULT_TEXT = ConsentText::DEFAULT_TEXT;
    public const THEME_DEFAULTS = ConsentTheme::THEME_DEFAULTS;
    public const BUTTON_DEFAULTS = ConsentButtons::BUTTON_DEFAULTS;

    /**
     * @return array<string, mixed>
     */
    public static function validate(array $settings, string $banner, string $path): array
    {
        $result = [];
        if (array_key_exists('text', $settings)) {
            $result['text'] = ConsentText::validate($settings['text'], $banner, $path.'.text');
        }
        if (array_key_exists('theme', $settings)) {
            $result['theme'] = ConsentTheme::validate($settings['theme'], $banner, $path.'.theme');
        }
        if (array_key_exists('buttons', $settings)) {
            $result['buttons'] = ConsentButtons::validate($settings['buttons'], $banner, $path.'.buttons');
        }
        if (array_key_exists('layout', $settings)) {
            $result['layout'] = ConsentButtons::validateLayout($settings['layout'], $path.'.layout');
        }
        if (array_key_exists('external_tags', $settings)) {
            $result['external_tags'] = ConsentExternalTags::validate($settings['external_tags'], $path.'.external_tags');
        }
        if (array_key_exists('consent_type', $settings)) {
            $result['consent_type'] = ConsentRegions::consentType($settings['consent_type'], $path.'.consent_type');
        }
        if (array_key_exists('close_button', $settings)) {
            $result['close_button'] = ConsentRegions::closeButton($settings['close_button'], $path.'.close_button');
        }
        if (array_key_exists('default_granted', $settings)) {
            $result['default_granted'] = EntityConsentCategories::categoryList($settings['default_granted'], $path.'.default_granted');
        }
        if (array_key_exists('default_region', $settings)) {
            $defReg = $settings['default_region'];
            if (is_string($defReg) && preg_match('/^[a-z0-9_-]{1,32}$/D', $defReg) === 1) {
                $result['default_region'] = $defReg;
            } elseif ($defReg !== null && $defReg !== '') {
                throw new \InvalidArgumentException($path.'.default_region must be 1–32 lowercase letters, digits, underscores, or hyphens.');
            }
        }
        if (array_key_exists('regions', $settings)) {
            $result['regions'] = ConsentRegions::validate(
                $settings['regions'],
                $banner,
                $path.'.regions',
                $result['default_region'] ?? null,
                $result['consent_type'] ?? ConsentRegions::DEFAULT_CONSENT_TYPE,
            );
            if (isset($result['default_region'])) {
                $matched = false;
                foreach ($result['regions'] as $reg) {
                    if ($reg['id'] === $result['default_region']) {
                        $matched = true;
                        break;
                    }
                }
                if (!$matched) {
                    throw new \InvalidArgumentException($path.'.default_region "'.$result['default_region'].'" does not match any configured region.');
                }
            } else {
                foreach ($result['regions'] as $reg) {
                    if ($reg['default']) {
                        $result['default_region'] = $reg['id'];
                        break;
                    }
                }
            }
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public static function browser(array $validated): array
    {
        return ConsentManager::toBrowserConfig($validated);
    }

    /** @return list<array<string, mixed>> */
    public static function regions(
        mixed $regions,
        string $banner,
        string $path,
        ?string $defaultRegion = null,
        string $defaultType = ConsentRegions::DEFAULT_CONSENT_TYPE,
    ): array {
        return ConsentRegions::validate($regions, $banner, $path, $defaultRegion, $defaultType);
    }

    /** @return list<array{id: string, name: string, category: string}> */
    public static function externalTags(mixed $tags, string $path): array
    {
        return ConsentExternalTags::validate($tags, $path);
    }

    /** @return list<string> */
    public static function externalTagCategories(array $validated): array
    {
        return ConsentExternalTags::categories($validated);
    }

    public static function privacyPolicyUrl(mixed $value, string $path): string
    {
        return ConsentText::privacyPolicyUrl($value, $path);
    }

    public static function consentType(mixed $value, string $path): string
    {
        return ConsentRegions::consentType($value, $path);
    }

    /** @return list<string> */
    public static function categoryList(mixed $value, string $path): array
    {
        return EntityConsentCategories::categoryList($value, $path);
    }
}
