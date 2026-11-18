<?php

declare(strict_types=1);

namespace App\Entities\ConsentManager;

/**
 * Validates regional rules and fallback behaviors for consent banners.
 */
final class ConsentRegions
{
    public const MAX_REGIONS = 10;
    public const CONSENT_TYPES = ['implicit_us', 'explicit_us', 'explicit_eu'];
    public const DEFAULT_CONSENT_TYPE = 'explicit_eu';
    public const LEGACY_CONSENT_TYPES = ['implicit' => 'implicit_us', 'explicit' => 'explicit_eu'];
    public const CLOSE_BUTTONS = ['reject', 'dismiss', 'hidden'];
    public const DEFAULT_GRANTED = ['analytics'];

    /**
     * @return list<array<string, mixed>>
     */
    public static function validate(
        mixed $regions,
        string $banner,
        string $path,
        ?string $defaultRegion = null,
        string $defaultType = self::DEFAULT_CONSENT_TYPE,
    ): array {
        if (!is_array($regions) || ($regions !== [] && !array_is_list($regions))
            || count($regions) > self::MAX_REGIONS
        ) {
            throw new \InvalidArgumentException(
                $path.' must be a list of at most '.self::MAX_REGIONS.' regions.',
            );
        }
        $result = [];
        $seenIds = [];
        $hasDefault = false;
        foreach ($regions as $index => $region) {
            if (!is_array($region) || array_is_list($region)) {
                throw new \InvalidArgumentException($path.'['.$index.'] must be a mapping.');
            }
            $itemPath = $path.'['.$index.']';
            self::checkKeys($region, $itemPath);
            $id = $region['id'] ?? null;
            if (!is_string($id) || preg_match('/^[a-z0-9_-]{1,32}$/D', $id) !== 1) {
                throw new \InvalidArgumentException(
                    $itemPath.'.id must be 1–32 lowercase letters, digits, '
                    .'underscores, or hyphens.',
                );
            }
            if (isset($seenIds[$id])) {
                throw new \InvalidArgumentException($itemPath.'.id "'.$id.'" is duplicated.');
            }
            $seenIds[$id] = true;

            $name = $region['name'] ?? null;
            if (!is_string($name) || trim($name) === '' || strlen(trim($name)) > 120) {
                throw new \InvalidArgumentException(
                    $itemPath.'.name must be nonempty plain text of at most 120 bytes.',
                );
            }

            $consentType = ($region['consent_type'] ?? null) === null
                ? $defaultType
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
                $normalized['text'] = ConsentText::validate(
                    $region['text'],
                    $banner,
                    $itemPath.'.text',
                );
            }
            if (array_key_exists('buttons', $region)) {
                $normalized['buttons'] = ConsentButtons::validate(
                    $region['buttons'],
                    $banner,
                    $itemPath.'.buttons',
                );
            }
            if (array_key_exists('close_button', $region)) {
                $normalized['close_button'] = self::closeButton(
                    $region['close_button'],
                    $itemPath.'.close_button',
                );
            }
            if (array_key_exists('default_granted', $region)) {
                if ($consentType !== 'implicit_us') {
                    throw new \InvalidArgumentException(
                        $itemPath.'.default_granted applies only to implicit_us regions; '
                        .'explicit consent types start every optional category denied.',
                    );
                }
                $normalized['default_granted'] = ConsentCategories::categoryList(
                    $region['default_granted'],
                    $itemPath.'.default_granted',
                );
            }
            $result[] = $normalized;
        }

        return $result;
    }

    private static function checkKeys(array $region, string $itemPath): void
    {
        $supportedKeys = [
            'id', 'name', 'consent_type', 'default', 'text',
            'buttons', 'close_button', 'default_granted',
        ];
        foreach (array_keys($region) as $k) {
            if (!in_array($k, $supportedKeys, true)) {
                throw new \InvalidArgumentException(
                    $itemPath.'.'.$k.' is not a supported region option.',
                );
            }
        }
    }

    public static function consentType(mixed $value, string $path): string
    {
        $type = is_string($value) ? (self::LEGACY_CONSENT_TYPES[$value] ?? $value) : $value;
        if (!is_string($type) || !in_array($type, self::CONSENT_TYPES, true)) {
            throw new \InvalidArgumentException(
                $path.' must be one of '.implode(', ', self::CONSENT_TYPES).'.',
            );
        }

        return $type;
    }

    public static function closeButton(mixed $value, string $path): string
    {
        if (!is_string($value) || !in_array($value, self::CLOSE_BUTTONS, true)) {
            throw new \InvalidArgumentException(
                $path.' must be one of '.implode(', ', self::CLOSE_BUTTONS).'.',
            );
        }

        return $value;
    }
}
