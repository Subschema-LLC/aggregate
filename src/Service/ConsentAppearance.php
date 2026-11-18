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
 * Wording, colors and buttons for the two consent banners: the built-in banner
 * (`consent_manager`) and the standalone drop-in (`standalone_consent`).
 *
 * Values are plain text, #RRGGBB colors and fixed button names, so nothing here
 * can inject markup, CSS or script. Only overrides are stored; each banner's
 * JavaScript holds its default wording. The browser receives camelCase keys.
 */
final class ConsentAppearance
{
    public const BUILTIN = 'builtin';
    public const STANDALONE = 'standalone';

    /** The mapping keys this class owns inside a banner's settings. */
    public const KEYS = ConsentManager::KEYS;
    public const REOPEN_POSITIONS = ConsentButtons::REOPEN_POSITIONS;
    /** card: each banner's floating panel (the default). bar: a full-width bar across the bottom of the window. */
    public const LAYOUTS = ConsentButtons::LAYOUTS;
    public const DEFAULT_LAYOUT = ConsentButtons::DEFAULT_LAYOUT;

    /**
     * Tags the website loads itself, outside the tag manager: each has an id the
     * page's markup refers to (data-consent-tag), a name visitors see under its
     * category, and the category that must be allowed before it runs.
     */
    public const MAX_EXTERNAL_TAGS = ConsentExternalTags::MAX_EXTERNAL_TAGS;
    public const MAX_DETAILS = 4;
    public const MAX_REGIONS = ConsentRegions::MAX_REGIONS;
    /**
     * How a region's banner treats visitors who have not chosen yet, and what its
     * close button (×) does:
     * - implicit_us: a notice; enhanced analytics starts granted, × hides the notice.
     * - explicit_us: optional categories start denied, × hides the banner and keeps them denied.
     * - explicit_eu: optional categories start denied, × rejects them all and saves that choice.
     */
    public const CONSENT_TYPES = ConsentRegions::CONSENT_TYPES;
    /** The consent type without a banner-wide consent_type, and for regions that name none. */
    public const DEFAULT_CONSENT_TYPE = ConsentRegions::DEFAULT_CONSENT_TYPE;
    /** Names accepted from earlier releases, and the type each now means. */
    public const LEGACY_CONSENT_TYPES = ConsentRegions::LEGACY_CONSENT_TYPES;
    /**
     * What the close button does when close_button is set: reject every optional
     * category and save that, close without a choice, or not show it. Unset, it
     * follows the consent type: reject for explicit_eu, dismiss otherwise.
     */
    public const CLOSE_BUTTONS = ConsentRegions::CLOSE_BUTTONS;
    /** What implicit_us grants before a choice when default_granted is not set. */
    public const DEFAULT_GRANTED = ConsentRegions::DEFAULT_GRANTED;
    public const MAX_CATEGORIES = EntityConsentCategories::MAX_CATEGORIES;

    /**
     * Text keys and their limits in UTF-8 bytes. `details` is a list of extra
     * paragraphs and `categories` maps category names to labels. `title` may
     * contain {name}; the standalone `storage_notice` may contain {days};
     * `category_tags`, the line naming a category's external tags, must contain {tags}.
     */
    public const TEXT = ConsentText::TEXT;
    /**
     * Default wording, mirrored by `defaultText` in public/consent.js and
     * micro-consent-dropins/js/consent-ui.js (a test compares them).
     */
    public const DEFAULT_TEXT = ConsentText::DEFAULT_TEXT;
    /** Mirrors the defaults in public/consent.css and micro-consent-dropins/css/consent-ui.css. */
    public const THEME_DEFAULTS = ConsentTheme::THEME_DEFAULTS;
    /** Every allowed first-view button, in default order, and the default reopen position. */
    public const BUTTON_DEFAULTS = ConsentButtons::BUTTON_DEFAULTS;

    /**
     * Validates the keys in KEYS present in $settings and returns only those,
     * normalized. $path prefixes error messages.
     *
     * @return array<string, mixed>
     */
    public static function validate(array $settings, string $banner, string $path): array
    {
        return \App\Entities\ConsentManager\ConsentManagerAppearance::validate($settings, $banner, $path);
    }

    /** camelCase browser configuration for validated settings. */
    public static function browser(array $validated): array
    {
        return \App\Entities\ConsentManager\ConsentBrowserConfig::toBrowserConfig($validated);
    }

    /**
     * Validates a list of regions. Each needs an id and a name; consent_type
     * defaults to $defaultType (the banner's), and the older explicit and
     * implicit are read as explicit_eu and implicit_us. close_button and
     * default_granted override the banner's for that region.
     */
    public static function regions(mixed $regions, string $banner, string $path, ?string $defaultRegion = null, string $defaultType = self::DEFAULT_CONSENT_TYPE): array
    {
        return ConsentRegions::validate($regions, $banner, $path, $defaultRegion, $defaultType);
    }

    /**
     * Validates external_tags: up to MAX_EXTERNAL_TAGS mappings of a unique id
     * (1–64 letters, digits, underscores or hyphens, beginning with a letter, as
     * tag manager IDs), a name of plain text and a consent category.
     *
     * @return list<array{id: string, name: string, category: string}>
     */
    public static function externalTags(mixed $tags, string $path): array
    {
        return ConsentExternalTags::validate($tags, $path);
    }

    /** @return list<string> The categories validated external_tags use, in first-use order. */
    public static function externalTagCategories(array $validatedTags): array
    {
        return ConsentExternalTags::categories($validatedTags);
    }

    /** Empty, or an absolute HTTPS URL without credentials, spaces or backslashes. */
    public static function privacyPolicyUrl(mixed $url, string $path): string
    {
        return ConsentText::privacyPolicyUrl($url, $path);
    }

    /** One of CONSENT_TYPES; the older explicit and implicit are read as explicit_eu and implicit_us. */
    public static function consentType(mixed $type, string $path): string
    {
        return ConsentRegions::consentType($type, $path);
    }

    /** @return list<string> Category names; [] is allowed. */
    public static function categoryList(mixed $categories, string $path): array
    {
        return EntityConsentCategories::categoryList($categories, $path);
    }
}
