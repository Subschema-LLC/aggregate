<?php

declare(strict_types=1);

namespace App\Entities\ConsentManager;

/**
 * Validates appearance, region rules and themes for consent managers.
 */
final class ConsentManagerAppearance
{
    /**
     * @param array<string, mixed> $settings
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
            $result['buttons'] = ConsentButtons::validate(
                $settings['buttons'],
                $banner,
                $path.'.buttons',
            );
        }
        if (array_key_exists('layout', $settings)) {
            $result['layout'] = ConsentButtons::validateLayout(
                $settings['layout'],
                $path.'.layout',
            );
        }
        if (array_key_exists('external_tags', $settings)) {
            $result['external_tags'] = ConsentExternalTags::validate(
                $settings['external_tags'],
                $path.'.external_tags',
            );
        }
        if (array_key_exists('consent_type', $settings)) {
            $result['consent_type'] = ConsentRegions::consentType(
                $settings['consent_type'],
                $path.'.consent_type',
            );
        }
        if (array_key_exists('close_button', $settings)) {
            $result['close_button'] = ConsentRegions::closeButton(
                $settings['close_button'],
                $path.'.close_button',
            );
        }
        if (array_key_exists('default_granted', $settings)) {
            $result['default_granted'] = ConsentCategories::categoryList(
                $settings['default_granted'],
                $path.'.default_granted',
            );
        }
        self::validateRegionSettings($settings, $banner, $result, $path);

        return $result;
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $result
     */
    private static function validateRegionSettings(
        array $settings,
        string $banner,
        array &$result,
        string $path,
    ): void {
        if (array_key_exists('default_region', $settings)) {
            $defReg = $settings['default_region'];
            if (is_string($defReg) && preg_match('/^[a-z0-9_-]{1,32}$/D', $defReg) === 1) {
                $result['default_region'] = $defReg;
            } elseif ($defReg !== null && $defReg !== '') {
                throw new \InvalidArgumentException(
                    $path.'.default_region must be 1–32 lowercase letters, '
                    .'digits, underscores, or hyphens.',
                );
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
            self::checkDefaultRegionMatch($result, $path);
        }
    }

    private static function checkDefaultRegionMatch(array &$result, string $path): void
    {
        if (isset($result['default_region'])) {
            $matched = false;
            foreach ($result['regions'] as $reg) {
                if ($reg['id'] === $result['default_region']) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                throw new \InvalidArgumentException(
                    $path.'.default_region "'.$result['default_region']
                    .'" does not match any configured region.',
                );
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
}
