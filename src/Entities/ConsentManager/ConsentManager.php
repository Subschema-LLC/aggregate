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

    /** @param array<string, mixed> $values */
    private function __construct(private readonly array $values)
    {
    }

    public static function fromInput(
        mixed $settings,
        string $defaultName,
        bool $defaultEnabled = true,
        string $banner = 'builtin',
        string $path = 'consent_manager',
    ): self {
        if (!is_array($settings) || ($settings !== [] && array_is_list($settings))
            || array_diff(array_keys($settings), self::SUPPORTED) !== []
        ) {
            throw new \InvalidArgumentException(
                'Consent manager settings support only '.implode(', ', self::SUPPORTED).'.',
            );
        }
        $enabled = array_key_exists('enabled', $settings) ? $settings['enabled'] : $defaultEnabled;
        $name = array_key_exists('name', $settings) ? $settings['name'] : $defaultName;
        if (!is_bool($enabled) || !is_string($name) || trim($name) === ''
            || strlen($name) > 120 || preg_match('//u', $name) !== 1
            || preg_match('/[\x00-\x1f\x7f]/', $name) === 1
        ) {
            throw new \InvalidArgumentException(
                'Consent controls need a YAML boolean enabled setting and a name of '
                .'1–120 UTF-8 bytes without control characters.',
            );
        }
        $result = ['enabled' => $enabled, 'name' => trim($name)];
        self::validateOptionalFields($settings, $result, $path);

        $appearance = ConsentManagerAppearance::validate($settings, $banner, $path);

        return new self($result + $appearance);
    }

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
        return self::fromInput($settings, $defaultName, $defaultEnabled, $banner, $path)->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->values;
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
            $clean = self::validatePrecheck(
                $settings['precheck_categories'],
                $path.'.precheck_categories',
            );
            if ($clean !== []) {
                $result['precheck_categories'] = $clean;
            }
        }
        if (array_key_exists('categories', $settings)) {
            $categories = ConsentCategories::categoryList(
                $settings['categories'],
                $path.'.categories',
            );
            if ($categories !== []) {
                $result['categories'] = $categories;
            }
        }
        if (array_key_exists('respect_gpc', $settings)) {
            if (!is_bool($settings['respect_gpc'])) {
                throw new \InvalidArgumentException(
                    $path.'.respect_gpc must be a YAML boolean (true or false).',
                );
            }
            if ($settings['respect_gpc'] === false) {
                $result['respect_gpc'] = false;
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function validatePrecheck(mixed $precheck, string $path): array
    {
        if (!is_array($precheck) || ($precheck !== [] && !array_is_list($precheck))) {
            throw new \InvalidArgumentException($path.' must be a list of category names.');
        }
        $clean = [];
        foreach ($precheck as $cat) {
            if (!is_string($cat) || preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $cat) !== 1
                || in_array($cat, ['none', 'gpc'], true)
            ) {
                throw new \InvalidArgumentException($path.' entries must be category names.');
            }
            if (!in_array($cat, $clean, true)) {
                $clean[] = $cat;
            }
        }

        return $clean;
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
