<?php

declare(strict_types=1);

namespace App\Entities\ConsentManager;

/**
 * Root entity for consent manager configuration, validation and projection.
 */
final class ConsentManager
{
    public const KEYS = [
        'text', 'theme', 'buttons', 'layout', 'regions',
        'default_region', 'consent_type', 'close_button',
        'default_granted', 'external_tags',
    ];

    public const SUPPORTED = [
        'enabled', 'name', 'privacy_policy_url', 'precheck_categories',
        'respect_gpc', 'categories', ...self::KEYS,
    ];

    /**
     * @return array<string, mixed>
     */
    public static function validate(
        mixed $settings,
        string $defaultName,
        bool $defaultEnabled = true,
        string $banner = 'builtin',
        string $path = 'consent_manager',
    ): array {
        if (!is_array($settings) || (array_keys($settings) !== [] && array_is_list($settings))) {
            throw new \InvalidArgumentException(
                'Consent manager settings must be a YAML mapping.',
            );
        }
        if (array_diff(array_keys($settings), self::SUPPORTED) !== []) {
            throw new \InvalidArgumentException(
                'Consent manager settings support only '.implode(', ', self::SUPPORTED).'.',
            );
        }
        $enabled = array_key_exists('enabled', $settings) ? $settings['enabled'] : $defaultEnabled;
        $name = array_key_exists('name', $settings) ? $settings['name'] : $defaultName;
        if (!is_bool($enabled) || !is_string($name) || trim($name) === ''
            || strlen(trim($name)) > 120 || preg_match('//u', $name) !== 1
            || preg_match('/\p{Cc}/u', $name) === 1
        ) {
            throw new \InvalidArgumentException(
                'Consent controls need a YAML boolean enabled setting and a name of '
                .'1–120 UTF-8 bytes without control characters.',
            );
        }
        $result = ['enabled' => $enabled, 'name' => trim($name)];
        self::validateOptionalFields($settings, $result, $path);

        return $result + ConsentManagerAppearance::validate($settings, $banner, $path);
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $result
     */
    private static function validateOptionalFields(
        array $settings,
        array &$result,
        string $path,
    ): void {
        if (array_key_exists('privacy_policy_url', $settings)) {
            $result['privacy_policy_url'] = ConsentText::privacyPolicyUrl(
                $settings['privacy_policy_url'],
                $path.'.privacy_policy_url',
            );
        }
        if (array_key_exists('precheck_categories', $settings)) {
            $result['precheck_categories'] = self::validatePrecheck(
                $settings['precheck_categories'],
                $path.'.precheck_categories',
            );
        }
        if (array_key_exists('categories', $settings)) {
            $result['categories'] = ConsentCategories::categoryList(
                $settings['categories'],
                $path.'.categories',
            );
        }
        if (array_key_exists('respect_gpc', $settings)) {
            if (!is_bool($settings['respect_gpc'])) {
                throw new \InvalidArgumentException(
                    $path.'.respect_gpc must be a YAML boolean (true or false).',
                );
            }
            $result['respect_gpc'] = $settings['respect_gpc'];
        }
    }

    /**
     * @param list<mixed> $precheck
     * @return list<string>
     */
    private static function validatePrecheck(mixed $precheck, string $path): array
    {
        if (!is_array($precheck) || ($precheck !== [] && !array_is_list($precheck))) {
            throw new \InvalidArgumentException($path.' must be a list of category names.');
        }
        foreach ($precheck as $cat) {
            if (!is_string($cat) || preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $cat) !== 1) {
                throw new \InvalidArgumentException($path.' entries must be category names.');
            }
        }

        return array_values(array_unique($precheck));
    }

    /**
     * @param array<string, mixed> $validated
     * @return array<string, mixed>
     */
    public static function toBrowserConfig(array $validated): array
    {
        return ConsentBrowserConfig::toBrowserConfig($validated);
    }
}
