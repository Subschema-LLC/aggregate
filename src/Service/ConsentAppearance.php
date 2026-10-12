<?php

declare(strict_types=1);

namespace App\Service;

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
    public const KEYS = ['text', 'theme', 'buttons', 'regions', 'default_region', 'consent_type', 'close_button', 'default_granted'];
    public const REOPEN_POSITIONS = ['bottom-left', 'bottom-right', 'hidden'];
    public const MAX_DETAILS = 4;
    public const MAX_REGIONS = 10;
    /**
     * How a region's banner treats visitors who have not chosen yet, and what its
     * close button (×) does:
     * - implicit_us: a notice; enhanced analytics starts granted, × hides the notice.
     * - explicit_us: optional categories start denied, × hides the banner and keeps them denied.
     * - explicit_eu: optional categories start denied, × rejects them all and saves that choice.
     */
    public const CONSENT_TYPES = ['implicit_us', 'explicit_us', 'explicit_eu'];
    /** The consent type without a banner-wide consent_type, and for regions that name none. */
    public const DEFAULT_CONSENT_TYPE = 'explicit_eu';
    /** Names accepted from earlier releases, and the type each now means. */
    public const LEGACY_CONSENT_TYPES = ['implicit' => 'implicit_us', 'explicit' => 'explicit_eu'];
    /**
     * What the close button does when close_button is set: reject every optional
     * category and save that, close without a choice, or not show it. Unset, it
     * follows the consent type: reject for explicit_eu, dismiss otherwise.
     */
    public const CLOSE_BUTTONS = ['reject', 'dismiss', 'hidden'];
    /** What implicit_us grants before a choice when default_granted is not set. */
    public const DEFAULT_GRANTED = ['analytics'];
    public const MAX_CATEGORIES = 10;

    private const LABEL = 120;
    private const PARAGRAPH = 1000;

    /**
     * Text keys and their limits in UTF-8 bytes. `details` is a list of extra
     * paragraphs and `categories` maps category names to labels. `title` may
     * contain {name}; the standalone `storage_notice` may contain {days}.
     */
    public const TEXT = [
        self::BUILTIN => [
            'title' => self::LABEL, 'description' => self::PARAGRAPH, 'details' => self::PARAGRAPH,
            'categories_legend' => self::LABEL, 'categories' => self::LABEL,
            'reject' => self::LABEL, 'accept' => self::LABEL, 'save' => self::LABEL, 'reopen' => self::LABEL,
            'dismiss' => self::LABEL, 'dismiss_reject' => self::LABEL, 'privacy_link' => self::LABEL,
            'status_applied' => self::PARAGRAPH, 'status_not_saved' => self::PARAGRAPH, 'status_other_tab' => self::PARAGRAPH,
        ],
        self::STANDALONE => [
            'title' => self::LABEL, 'description' => self::PARAGRAPH, 'details' => self::PARAGRAPH,
            'privacy_link' => self::LABEL,
            'reject' => self::LABEL, 'accept' => self::LABEL, 'manage' => self::LABEL, 'save' => self::LABEL,
            'reopen' => self::LABEL, 'close' => self::LABEL, 'dismiss' => self::LABEL, 'dismiss_reject' => self::LABEL,
            'settings_label' => self::LABEL, 'preferences_tab' => self::LABEL, 'requests_tab' => self::LABEL,
            'preferences_intro' => self::PARAGRAPH, 'categories_legend' => self::LABEL, 'categories' => self::LABEL,
            'opt_out' => self::PARAGRAPH, 'opt_out_help' => self::PARAGRAPH, 'gpc_notice' => self::PARAGRAPH,
            'storage_notice' => self::PARAGRAPH,
            'status_applied' => self::PARAGRAPH, 'status_not_saved' => self::PARAGRAPH, 'status_other_tab' => self::PARAGRAPH,
            'request_disclosure' => self::PARAGRAPH, 'request_email' => self::LABEL, 'request_type' => self::LABEL,
            'request_access' => self::LABEL, 'request_delete' => self::LABEL, 'request_correct' => self::LABEL,
            'request_opt_out' => self::LABEL, 'request_message' => self::LABEL, 'request_submit' => self::LABEL,
            'request_invalid' => self::PARAGRAPH, 'request_unavailable' => self::PARAGRAPH, 'request_sending' => self::PARAGRAPH,
            'request_failed' => self::PARAGRAPH, 'request_sent' => self::PARAGRAPH,
        ],
    ];

    /**
     * Default wording, mirrored by `defaultText` in public/consent.js and
     * micro-consent-dropins/js/consent-ui.js (a test compares them).
     */
    public const DEFAULT_TEXT = [
        self::BUILTIN => [
            'title' => '{name}: privacy choices',
            'description' => 'Choose which optional categories to allow. Enhanced analytics uses browser identifiers and additional event details. Tags in each selected category may load third-party scripts.',
            'details' => [
                'Coarse anonymous measurement may continue after rejection. You can change your choice here at any time. Withdrawal removes analytics identifiers and stops future enhanced detail; it does not erase stored history.',
                'After withdrawal, reload this page to stop optional scripts already loaded. Tags configured to require no consent can run regardless of these choices. See this website’s privacy notice for its data, purposes, and providers.',
            ],
            'categories_legend' => 'Optional categories',
            'categories' => ['analytics' => 'Enhanced analytics'],
            'reject' => 'Reject all optional categories',
            'accept' => 'Accept all optional categories',
            'save' => 'Save selected choices',
            'reopen' => 'Privacy choices',
            'dismiss' => 'Close this notice',
            'dismiss_reject' => 'Reject all optional categories and close',
            'privacy_link' => 'Read this website’s privacy notice',
            'status_applied' => 'Your privacy choices have been applied.',
            'status_not_saved' => 'This choice could not be saved; choose again on your next visit.',
            'status_other_tab' => 'Your privacy choices were updated in another tab.',
        ],
        self::STANDALONE => [
            'title' => '{name}: privacy choices',
            'description' => 'Choose which optional categories to allow. They start denied. You can change your choices at any time.',
            'details' => [
                'These choices apply to connected tools. If this website uses privacy-minimized analytics, coarse measurement may continue after rejection. See its privacy notice for the actual data and providers.',
            ],
            'privacy_link' => 'Read this website’s privacy notice',
            'reject' => 'Reject optional categories',
            'accept' => 'Accept optional categories',
            'manage' => 'Manage choices',
            'save' => 'Save selected choices',
            'reopen' => 'Privacy choices',
            'close' => 'Close privacy settings',
            'dismiss' => 'Close this notice',
            'dismiss_reject' => 'Reject optional categories and close',
            'settings_label' => 'Privacy settings',
            'preferences_tab' => 'Preferences',
            'requests_tab' => 'Privacy request',
            'preferences_intro' => 'Allow only the categories you choose. Rejection and withdrawal stop future actions in connected tools. Scripts already loaded may continue until you reload, and their cookies and stored history are not automatically erased.',
            'categories_legend' => 'Optional categories',
            'categories' => ['analytics' => 'Analytics (enhanced details when connected to the analytics tracker)'],
            'opt_out' => 'Opt out of sale, sharing, or targeted advertising in connected tools',
            'opt_out_help' => 'This opt-out disables the marketing category and signals connected providers. It cannot enforce choices for tools that are not connected or process an organization-wide privacy request.',
            'gpc_notice' => 'Your browser sends Global Privacy Control. We keep the advertising opt-out on and marketing denied. This signal does not grant analytics consent.',
            'storage_notice' => 'Your category choices, opt-out, policy revision, and save time are stored in this browser for up to {days} days. No visitor identifier is added.',
            'status_applied' => 'Your privacy choices have been applied.',
            'status_not_saved' => 'They could not be saved. Choose again on your next visit.',
            'status_other_tab' => 'Privacy choices were updated in another tab.',
            'request_disclosure' => 'Submitting this form sends your email address, request type, and optional message to Formspree for this website’s operator. Nothing is sent until you submit. No visitor identifier or page URL is added. Do not include sensitive information.',
            'request_email' => 'Email address',
            'request_type' => 'Request type',
            'request_access' => 'Access my data',
            'request_delete' => 'Delete my data',
            'request_correct' => 'Correct my data',
            'request_opt_out' => 'Opt out of sale, sharing, or targeted advertising',
            'request_message' => 'Message (optional, up to 2,000 characters)',
            'request_submit' => 'Submit privacy request',
            'request_invalid' => 'Enter a valid email address and request type, and keep your message within 2,000 characters.',
            'request_unavailable' => 'This request could not be sent. Use the contact information in this website’s privacy notice.',
            'request_sending' => 'Sending your request to Formspree…',
            'request_failed' => 'Your request could not be submitted. Your entries are still here; retry or use the contact information in this website’s privacy notice.',
            'request_sent' => 'Your request was submitted to Formspree for this website’s operator. The operator must review and process it; submission does not erase stored data.',
        ],
    ];

    /** Mirrors the defaults in public/consent.css and micro-consent-dropins/css/consent-ui.css. */
    public const THEME_DEFAULTS = [
        self::BUILTIN => [
            'background' => '#FFFFFF', 'text' => '#202124', 'accent' => '#2459B8', 'border' => '#202124',
            'button_background' => '#FFFFFF', 'button_text' => '#202124', 'button_border' => '#202124',
        ],
        self::STANDALONE => [
            'background' => '#FFFFFF', 'text' => '#17212D', 'accent' => '#174F85', 'border' => '#B7C1CE',
            'button_background' => '#FFFFFF', 'button_text' => '#174F85', 'button_border' => '#174F85',
        ],
    ];

    /** Every allowed first-view button, in default order, and the default reopen position. */
    public const BUTTON_DEFAULTS = [
        self::BUILTIN => ['show' => ['reject', 'accept', 'save'], 'reopen' => 'bottom-left'],
        self::STANDALONE => ['show' => ['reject', 'accept', 'manage'], 'reopen' => 'bottom-right'],
    ];

    /**
     * Validates the keys in KEYS present in $settings and returns only those,
     * normalized. $path prefixes error messages.
     *
     * @return array{text?: array, theme?: array<string, string>, buttons?: array{show?: list<string>, reopen?: string}, consent_type?: string, close_button?: string, default_granted?: list<string>, regions?: list<array>, default_region?: string}
     */
    public static function validate(array $settings, string $banner, string $path): array
    {
        if (!isset(self::TEXT[$banner])) {
            throw new \LogicException('Unknown consent banner.');
        }
        $result = [];
        if (array_key_exists('text', $settings)) {
            $result['text'] = self::text($settings['text'], $banner, $path.'.text');
        }
        if (array_key_exists('theme', $settings)) {
            $result['theme'] = self::theme($settings['theme'], $banner, $path.'.theme');
        }
        if (array_key_exists('buttons', $settings)) {
            $result['buttons'] = self::buttons($settings['buttons'], $banner, $path.'.buttons');
        }
        if (array_key_exists('consent_type', $settings)) {
            $result['consent_type'] = self::consentType($settings['consent_type'], $path.'.consent_type');
        }
        if (array_key_exists('close_button', $settings)) {
            $result['close_button'] = self::closeButton($settings['close_button'], $path.'.close_button');
        }
        // Used wherever the effective type is implicit_us, the banner's or a region's.
        if (array_key_exists('default_granted', $settings)) {
            $result['default_granted'] = self::categoryList($settings['default_granted'], $path.'.default_granted');
        }
        if (array_key_exists('default_region', $settings)) {
            $defReg = $settings['default_region'];
            if ($defReg === null || $defReg === '') {
                // null or empty string means not set
            } elseif (!is_string($defReg) || preg_match('/^[a-z0-9_-]{1,32}$/D', $defReg) !== 1) {
                throw new \InvalidArgumentException($path.'.default_region must be 1–32 lowercase letters, digits, underscores, or hyphens.');
            } else {
                $result['default_region'] = $defReg;
            }
        }
        if (array_key_exists('regions', $settings)) {
            $result['regions'] = self::regions($settings['regions'], $banner, $path.'.regions', $result['default_region'] ?? null,
                $result['consent_type'] ?? self::DEFAULT_CONSENT_TYPE);
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

    /** camelCase browser configuration for validated settings. */
    public static function browser(array $validated): array
    {
        $browser = [];
        foreach (['text', 'theme', 'buttons'] as $group) {
            if (!array_key_exists($group, $validated)) continue;
            $values = [];
            foreach ($validated[$group] as $key => $value) {
                $values[self::camel($key)] = $value;
            }
            $browser[$group] = $values;
        }
        foreach (['consent_type', 'close_button', 'default_granted'] as $key) {
            if (array_key_exists($key, $validated)) {
                $browser[self::camel($key)] = $validated[$key];
            }
        }

        if (array_key_exists('regions', $validated)) {
            $browserRegions = [];
            foreach ($validated['regions'] as $reg) {
                $item = [
                    'id' => $reg['id'],
                    'name' => $reg['name'],
                    'consentType' => $reg['consent_type'],
                    'default' => $reg['default'],
                ];
                foreach (['close_button', 'default_granted'] as $key) {
                    if (array_key_exists($key, $reg)) {
                        $item[self::camel($key)] = $reg[$key];
                    }
                }
                if (isset($reg['text'])) {
                    $item['text'] = [];
                    foreach ($reg['text'] as $tk => $tv) {
                        $item['text'][self::camel($tk)] = $tv;
                    }
                }
                if (isset($reg['buttons'])) {
                    $item['buttons'] = [];
                    foreach ($reg['buttons'] as $bk => $bv) {
                        $item['buttons'][self::camel($bk)] = $bv;
                    }
                }
                $browserRegions[] = $item;
            }
            $browser['regions'] = $browserRegions;
        }

        if (array_key_exists('default_region', $validated) && $validated['default_region'] !== null) {
            $browser['defaultRegion'] = $validated['default_region'];
        }

        return $browser;
    }

    /**
     * Validates a list of regions. Each needs an id and a name; consent_type
     * defaults to $defaultType (the banner's), and the older explicit and
     * implicit are read as explicit_eu and implicit_us. close_button and
     * default_granted override the banner's for that region.
     */
    public static function regions(mixed $regions, string $banner, string $path, ?string $defaultRegion = null, string $defaultType = self::DEFAULT_CONSENT_TYPE): array
    {
        if (!is_array($regions) || ($regions !== [] && !array_is_list($regions)) || count($regions) > self::MAX_REGIONS) {
            throw new \InvalidArgumentException($path.' must be a list of at most '.self::MAX_REGIONS.' regions.');
        }
        $result = [];
        $seenIds = [];
        $hasDefault = false;
        foreach ($regions as $index => $region) {
            if (!is_array($region) || array_is_list($region)) {
                throw new \InvalidArgumentException($path.'['.$index.'] must be a mapping.');
            }
            $itemPath = $path.'['.$index.']';
            $supportedKeys = ['id', 'name', 'consent_type', 'default', 'text', 'buttons', 'close_button', 'default_granted'];
            foreach (array_keys($region) as $k) {
                if (!in_array($k, $supportedKeys, true)) {
                    throw new \InvalidArgumentException($itemPath.'.'.$k.' is not a supported region option.');
                }
            }
            $id = $region['id'] ?? null;
            if (!is_string($id) || preg_match('/^[a-z0-9_-]{1,32}$/D', $id) !== 1) {
                throw new \InvalidArgumentException($itemPath.'.id must be 1–32 lowercase letters, digits, underscores, or hyphens.');
            }
            if (isset($seenIds[$id])) {
                throw new \InvalidArgumentException($itemPath.'.id "'.$id.'" is duplicated.');
            }
            $seenIds[$id] = true;

            $name = $region['name'] ?? null;
            if (!is_string($name) || trim($name) === '' || strlen(trim($name)) > self::LABEL) {
                throw new \InvalidArgumentException($itemPath.'.name must be nonempty plain text of at most '.self::LABEL.' bytes.');
            }

            $consentType = ($region['consent_type'] ?? null) === null ? $defaultType
                : self::consentType($region['consent_type'], $itemPath.'.consent_type');

            $isDefault = (bool) ($region['default'] ?? false);
            if ($defaultRegion !== null) {
                $isDefault = ($id === $defaultRegion);
            }
            if ($isDefault && $hasDefault) {
                throw new \InvalidArgumentException($path.' may have at most one default region.');
            }
            if ($isDefault) {
                $hasDefault = true;
            }

            $normalized = [
                'id' => $id,
                'name' => trim($name),
                'consent_type' => $consentType,
                'default' => $isDefault,
            ];

            if (array_key_exists('text', $region)) {
                $normalized['text'] = self::text($region['text'], $banner, $itemPath.'.text');
            }
            if (array_key_exists('buttons', $region)) {
                $normalized['buttons'] = self::buttons($region['buttons'], $banner, $itemPath.'.buttons');
            }
            if (array_key_exists('close_button', $region)) {
                $normalized['close_button'] = self::closeButton($region['close_button'], $itemPath.'.close_button');
            }
            if (array_key_exists('default_granted', $region)) {
                if ($consentType !== 'implicit_us') {
                    throw new \InvalidArgumentException($itemPath.'.default_granted applies only to implicit_us regions; explicit consent types start every optional category denied.');
                }
                $normalized['default_granted'] = self::categoryList($region['default_granted'], $itemPath.'.default_granted');
            }

            $result[] = $normalized;
        }

        return $result;
    }

    /** Empty, or an absolute HTTPS URL without credentials, spaces or backslashes. */
    public static function privacyPolicyUrl(mixed $value, string $path): string
    {
        if (!is_string($value) || strlen($value) > 2048 || preg_match('//u', $value) !== 1 || preg_match('/\p{Cc}/u', $value) === 1) {
            throw new \InvalidArgumentException($path.' must be empty or an absolute HTTPS URL of at most 2048 bytes.');
        }
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $parts = str_contains($value, '\\') || preg_match('/\s/u', $value) === 1 || filter_var($value, FILTER_VALIDATE_URL) === false
            ? false : parse_url($value);
        if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') === ''
            || isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException($path.' must be empty or an absolute HTTPS URL without credentials.');
        }

        return $value;
    }

    /** One of CONSENT_TYPES; the older explicit and implicit are read as explicit_eu and implicit_us. */
    public static function consentType(mixed $value, string $path): string
    {
        $type = is_string($value) ? (self::LEGACY_CONSENT_TYPES[$value] ?? $value) : $value;
        if (!is_string($type) || !in_array($type, self::CONSENT_TYPES, true)) {
            throw new \InvalidArgumentException($path.' must be one of '.implode(', ', self::CONSENT_TYPES).'.');
        }

        return $type;
    }

    private static function closeButton(mixed $value, string $path): string
    {
        if (!is_string($value) || !in_array($value, self::CLOSE_BUTTONS, true)) {
            throw new \InvalidArgumentException($path.' must be one of '.implode(', ', self::CLOSE_BUTTONS).'.');
        }

        return $value;
    }

    /** @return list<string> Category names; [] is allowed. */
    private static function categoryList(mixed $value, string $path): array
    {
        if (!is_array($value) || ($value !== [] && !array_is_list($value)) || count($value) > self::MAX_CATEGORIES) {
            throw new \InvalidArgumentException($path.' must be a list of at most '.self::MAX_CATEGORIES.' category names; use [] for none.');
        }
        $result = [];
        foreach ($value as $category) {
            if (!is_string($category) || preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $category) !== 1
                || in_array($category, ['none', 'gpc', '__proto__', 'prototype', 'constructor'], true)) {
                throw new \InvalidArgumentException($path.' entries must be category names of 1–32 lowercase letters, digits, underscores or hyphens, beginning with a letter.');
            }
            if (!in_array($category, $result, true)) {
                $result[] = $category;
            }
        }

        return $result;
    }

    private static function text(mixed $text, string $banner, string $path): array
    {
        if (!self::isMapping($text)) {
            throw new \InvalidArgumentException($path.' must be a mapping of text keys to wording.');
        }
        $limits = self::TEXT[$banner];
        $result = [];
        foreach ($text as $key => $value) {
            if (!is_string($key) || !array_key_exists($key, $limits)) {
                throw new \InvalidArgumentException($path.'.'.$key.' is not a supported text key. Supported keys: '.implode(', ', array_keys($limits)).'.');
            }
            if ($key === 'details') {
                $paragraphs = is_string($value) ? [$value] : $value;
                if (!is_array($paragraphs) || !array_is_list($paragraphs) || count($paragraphs) > self::MAX_DETAILS) {
                    throw new \InvalidArgumentException($path.'.details must be a list of at most '.self::MAX_DETAILS.' paragraphs; use [] for none.');
                }
                $result[$key] = array_map(static fn (mixed $paragraph): string => self::wording($paragraph, $limits[$key], $path.'.details'), $paragraphs);
            } elseif ($key === 'categories') {
                if (!self::isMapping($value)) {
                    throw new \InvalidArgumentException($path.'.categories must map category names to labels.');
                }
                $labels = [];
                foreach ($value as $category => $label) {
                    if (!is_string($category) || preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $category) !== 1
                        || in_array($category, ['none', 'gpc', '__proto__', 'prototype', 'constructor'], true)) {
                        throw new \InvalidArgumentException($path.'.categories keys must be category names of 1–32 lowercase letters, digits, underscores or hyphens.');
                    }
                    $labels[$category] = self::wording($label, $limits[$key], $path.'.categories.'.$category);
                }
                $result[$key] = $labels;
            } else {
                $result[$key] = self::wording($value, $limits[$key], $path.'.'.$key);
            }
        }

        return $result;
    }

    private static function wording(mixed $value, int $maximum, string $path): string
    {
        if (!is_string($value) || preg_match('//u', $value) !== 1 || preg_match('/\p{Cc}/u', $value) === 1
            || trim($value) === '' || strlen(trim($value)) > $maximum) {
            throw new \InvalidArgumentException($path.' must be nonempty plain text of at most '.$maximum.' UTF-8 bytes, without line breaks or control characters.');
        }

        return trim($value);
    }

    /** @return array<string, string> */
    private static function theme(mixed $theme, string $banner, string $path): array
    {
        if (!self::isMapping($theme)) {
            throw new \InvalidArgumentException($path.' must be a mapping of color names to #RRGGBB values.');
        }
        $defaults = self::THEME_DEFAULTS[$banner];
        $result = [];
        foreach ($theme as $key => $value) {
            if (!is_string($key) || !array_key_exists($key, $defaults)) {
                throw new \InvalidArgumentException($path.'.'.$key.' is not a supported color. Supported colors: '.implode(', ', array_keys($defaults)).'.');
            }
            $color = BrandingTheme::normalizeHexColor($value);
            if ($color === null) {
                throw new \InvalidArgumentException($path.'.'.$key.' must be a hex color such as \'#1A4F8B\' (quote it in YAML).');
            }
            $result[$key] = $color;
        }
        $colors = array_replace($defaults, $result);
        foreach ([['text', 'background', 4.5], ['accent', 'background', 4.5], ['button_text', 'button_background', 4.5]] as [$foreground, $background, $minimum]) {
            $ratio = BrandingTheme::contrastRatio($colors[$foreground], $colors[$background]);
            if ($ratio < $minimum) {
                throw new \InvalidArgumentException(sprintf('%s: %s %s on %s %s has a contrast ratio of %.2f:1; readable text needs at least %.1f:1.',
                    $path, $foreground, $colors[$foreground], $background, $colors[$background], $ratio, $minimum));
            }
        }
        $edge = max(BrandingTheme::contrastRatio($colors['button_background'], $colors['background']),
            BrandingTheme::contrastRatio($colors['button_border'], $colors['background']));
        if ($edge < 3.0) {
            throw new \InvalidArgumentException(sprintf('%s: buttons need a button_background or button_border with at least 3:1 contrast against the background (currently %.2f:1), so their edges are visible.', $path, $edge));
        }

        return $result;
    }

    private static function buttons(mixed $buttons, string $banner, string $path): array
    {
        if (!self::isMapping($buttons) || array_diff(array_keys($buttons), ['show', 'reopen']) !== []) {
            throw new \InvalidArgumentException($path.' must be a mapping with only show and reopen.');
        }
        $allowed = self::BUTTON_DEFAULTS[$banner]['show'];
        $result = [];
        if (array_key_exists('show', $buttons)) {
            $show = $buttons['show'];
            if (!is_array($show) || !array_is_list($show) || $show === []
                || array_diff($show, $allowed) !== [] || count(array_unique($show)) !== count($show)
                || array_filter($show, 'is_string') !== $show) {
                throw new \InvalidArgumentException($path.'.show must list each of '.implode(', ', $allowed).' at most once, in display order.');
            }
            $grant = $banner === self::BUILTIN ? 'save' : 'manage';
            if (!in_array('reject', $show, true)) {
                throw new \InvalidArgumentException($path.'.show must include reject, so refusing is always one click, as easy as accepting.');
            }
            if (!in_array('accept', $show, true) && !in_array($grant, $show, true)) {
                throw new \InvalidArgumentException($path.'.show must include accept or '.$grant.', so visitors can also allow categories.');
            }
            $result['show'] = $show;
        }
        if (array_key_exists('reopen', $buttons)) {
            if (!in_array($buttons['reopen'], self::REOPEN_POSITIONS, true)) {
                throw new \InvalidArgumentException($path.'.reopen must be one of '.implode(', ', self::REOPEN_POSITIONS).'.');
            }
            $result['reopen'] = $buttons['reopen'];
        }

        return $result;
    }

    private static function isMapping(mixed $value): bool
    {
        return is_array($value) && ($value === [] || !array_is_list($value));
    }

    private static function camel(string $key): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $key))));
    }
}
