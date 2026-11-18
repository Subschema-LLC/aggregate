<?php

declare(strict_types=1);

namespace App\Entities\ConsentManager;

/**
 * Validates text wording and placeholders for consent banners.
 */
final class ConsentText
{
    private const LABEL = 120;
    private const PARAGRAPH = 1000;

    public const TEXT = [
        'builtin' => [
            'title' => self::LABEL, 'description' => self::PARAGRAPH, 'details' => self::PARAGRAPH,
            'categories_legend' => self::LABEL, 'categories' => self::LABEL,
            'category_tags' => self::LABEL, 'reject' => self::LABEL, 'accept' => self::LABEL,
            'save' => self::LABEL, 'reopen' => self::LABEL, 'dismiss' => self::LABEL,
            'dismiss_reject' => self::LABEL, 'privacy_link' => self::LABEL,
            'status_applied' => self::PARAGRAPH, 'status_not_saved' => self::PARAGRAPH,
            'status_other_tab' => self::PARAGRAPH,
        ],
        'standalone' => [
            'title' => self::LABEL, 'description' => self::PARAGRAPH, 'details' => self::PARAGRAPH,
            'privacy_link' => self::LABEL, 'reject' => self::LABEL, 'accept' => self::LABEL,
            'manage' => self::LABEL, 'save' => self::LABEL, 'reopen' => self::LABEL,
            'close' => self::LABEL, 'dismiss' => self::LABEL, 'dismiss_reject' => self::LABEL,
            'settings_label' => self::LABEL, 'preferences_tab' => self::LABEL,
            'requests_tab' => self::LABEL, 'preferences_intro' => self::PARAGRAPH,
            'categories_legend' => self::LABEL, 'categories' => self::LABEL,
            'category_tags' => self::LABEL, 'opt_out' => self::PARAGRAPH,
            'opt_out_help' => self::PARAGRAPH, 'gpc_notice' => self::PARAGRAPH,
            'storage_notice' => self::PARAGRAPH, 'status_applied' => self::PARAGRAPH,
            'status_not_saved' => self::PARAGRAPH, 'status_other_tab' => self::PARAGRAPH,
            'request_disclosure' => self::PARAGRAPH, 'request_email' => self::LABEL,
            'request_type' => self::LABEL, 'request_access' => self::LABEL,
            'request_delete' => self::LABEL, 'request_correct' => self::LABEL,
            'request_opt_out' => self::LABEL, 'request_message' => self::LABEL,
            'request_submit' => self::LABEL, 'request_invalid' => self::PARAGRAPH,
            'request_unavailable' => self::PARAGRAPH, 'request_sending' => self::PARAGRAPH,
            'request_failed' => self::PARAGRAPH, 'request_sent' => self::PARAGRAPH,
        ],
    ];

    public const DEFAULT_TEXT = [
        'builtin' => ConsentDefaultText::BUILTIN,
        'standalone' => ConsentDefaultText::STANDALONE,
    ];

    /**
     * @return array<string, mixed>
     */
    public static function validate(mixed $text, string $banner, string $path): array
    {
        if (!is_array($text) || (array_keys($text) !== [] && array_is_list($text))) {
            throw new \InvalidArgumentException(
                $path.' must be a mapping of text keys to wording.',
            );
        }
        $limits = self::TEXT[$banner] ?? [];
        $result = [];
        foreach ($text as $key => $value) {
            if (!is_string($key) || !array_key_exists($key, $limits)) {
                throw new \InvalidArgumentException(
                    $path.'.'.$key.' is not a supported text key. Supported keys: '
                    .implode(', ', array_keys($limits)).'.',
                );
            }
            if ($key === 'details') {
                $result[$key] = self::validateDetails($value, $limits[$key], $path.'.details');
            } elseif ($key === 'categories') {
                $result[$key] = self::validateCategoryLabels(
                    $value,
                    $limits[$key],
                    $path.'.categories',
                );
            } else {
                $result[$key] = self::wording($value, $limits[$key], $path.'.'.$key);
                if ($key === 'category_tags' && !str_contains($result[$key], '{tags}')) {
                    throw new \InvalidArgumentException(
                        $path.'.category_tags must contain {tags}, where the tag names go.',
                    );
                }
            }
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private static function validateDetails(mixed $value, int $limit, string $path): array
    {
        $paragraphs = is_string($value) ? [$value] : $value;
        if (!is_array($paragraphs) || !array_is_list($paragraphs) || count($paragraphs) > 4) {
            throw new \InvalidArgumentException(
                $path.' must be a list of at most 4 paragraphs; use [] for none.',
            );
        }

        return array_map(
            static fn (mixed $p): string => self::wording($p, $limit, $path),
            $paragraphs,
        );
    }

    /**
     * @return array<string, string>
     */
    private static function validateCategoryLabels(mixed $value, int $limit, string $path): array
    {
        if (!is_array($value) || (array_keys($value) !== [] && array_is_list($value))) {
            throw new \InvalidArgumentException(
                $path.' must map category names to labels.',
            );
        }
        $labels = [];
        foreach ($value as $category => $label) {
            if (!is_string($category)
                || preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $category) !== 1
                || in_array(
                    $category,
                    ['none', 'gpc', '__proto__', 'prototype', 'constructor'],
                    true,
                )
            ) {
                throw new \InvalidArgumentException(
                    $path.' keys must be category names of 1–32 lowercase letters, '
                    .'digits, underscores or hyphens.',
                );
            }
            $labels[$category] = self::wording($label, $limit, $path.'.'.$category);
        }

        return $labels;
    }

    public static function wording(mixed $value, int $maximum, string $path): string
    {
        if (!is_string($value) || preg_match('//u', $value) !== 1
            || preg_match('/\p{Cc}/u', $value) === 1
            || trim($value) === '' || strlen(trim($value)) > $maximum
        ) {
            throw new \InvalidArgumentException(
                $path.' must be nonempty plain text of at most '.$maximum
                .' UTF-8 bytes, without line breaks or control characters.',
            );
        }

        return trim($value);
    }

    public static function privacyPolicyUrl(mixed $value, string $path): string
    {
        if (!is_string($value) || strlen($value) > 2048 || preg_match('//u', $value) !== 1
            || preg_match('/\p{Cc}/u', $value) === 1
        ) {
            throw new \InvalidArgumentException(
                $path.' must be empty or an absolute HTTPS URL of at most 2048 bytes.',
            );
        }
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $parts = str_contains($value, '\\') || preg_match('/\s/u', $value) === 1
            || filter_var($value, FILTER_VALIDATE_URL) === false
            ? false
            : parse_url($value);
        if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https'
            || ($parts['host'] ?? '') === ''
            || isset($parts['user']) || isset($parts['pass'])
        ) {
            throw new \InvalidArgumentException(
                $path.' must be empty or an absolute HTTPS URL without credentials.',
            );
        }

        return $value;
    }
}
