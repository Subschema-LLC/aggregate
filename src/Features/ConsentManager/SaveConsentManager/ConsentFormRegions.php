<?php

declare(strict_types=1);

namespace App\Features\ConsentManager\SaveConsentManager;

use App\Entities\ConsentManager\ConsentRegions;

/**
 * Extracts and normalizes region settings from the consent manager form.
 */
final class ConsentFormRegions
{
    /**
     * @param array<string, mixed> $form
     * @param array<string, mixed> $saved
     * @return list<array<string, mixed>>
     */
    public static function regions(array $form, array $saved): array
    {
        $savedRegions = [];
        foreach ($saved['regions'] ?? [] as $r) {
            if (isset($r['id'])) {
                $savedRegions[$r['id']] = $r;
            }
        }
        $regions = [];
        if (!isset($form['regions']) || !is_array($form['regions'])) {
            return $regions;
        }
        foreach ($form['regions'] as $reg) {
            if (!is_array($reg)) {
                continue;
            }
            $id = trim((string) ($reg['id'] ?? ''));
            $name = trim((string) ($reg['name'] ?? ''));
            if ($id === '' && $name === '') {
                continue;
            }
            $regions[] = self::buildRegionItem($reg, $id, $name, $savedRegions[$id] ?? []);
        }

        return $regions;
    }

    /**
     * @param array<string, mixed> $reg
     * @param array<string, mixed> $previous
     * @return array<string, mixed>
     */
    private static function buildRegionItem(
        array $reg,
        string $id,
        string $name,
        array $previous,
    ): array {
        $consentType = is_string($reg['consent_type'] ?? null) && $reg['consent_type'] !== ''
            ? $reg['consent_type']
            : ConsentRegions::DEFAULT_CONSENT_TYPE;
        $isDefault = ($reg['default'] ?? null) === '1' || ($reg['default'] ?? null) === true;
        $regItem = [
            'id' => $id,
            'name' => $name,
            'consent_type' => $consentType,
            'default' => $isDefault,
        ];
        $submittedRegionText = isset($reg['text']) && is_array($reg['text']) ? $reg['text'] : [];
        $regText = $previous['text'] ?? [];
        foreach ($submittedRegionText as $tk => $tv) {
            if (is_string($tv) && trim($tv) !== '') {
                $regText[$tk] = trim($tv);
            } else {
                unset($regText[$tk]);
            }
        }
        if ($regText !== []) {
            $regItem['text'] = $regText;
        }
        if (isset($previous['buttons'])) {
            $regItem['buttons'] = $previous['buttons'];
        }
        if (array_key_exists('close_button', $reg) && trim((string) $reg['close_button']) !== '') {
            $regItem['close_button'] = trim((string) $reg['close_button']);
        }
        if (($reg['default_granted_override'] ?? null) === '1') {
            $regItem['default_granted'] = self::submittedCategories(
                $reg['default_granted'] ?? [],
                'Region default-on categories',
            );
        }

        return $regItem;
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    public static function submittedCategories(mixed $value, string $label): array
    {
        if (!is_array($value) || array_filter($value, 'is_string') !== $value) {
            throw new \InvalidArgumentException(
                $label.' must be category names. Nothing was saved.',
            );
        }

        return array_values(array_unique(array_filter(
            array_map('trim', $value),
            static fn (string $c): bool => $c !== '',
        )));
    }
}
