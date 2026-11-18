<?php

declare(strict_types=1);

namespace App\Entities\ConsentManager;

/**
 * Validates layout and button configuration for consent banners.
 */
final class ConsentButtons
{
    public const REOPEN_POSITIONS = ['bottom-left', 'bottom-right', 'hidden'];
    public const LAYOUTS = ['card', 'bar'];
    public const DEFAULT_LAYOUT = 'card';

    public const BUTTON_DEFAULTS = [
        'builtin' => ['show' => ['reject', 'accept', 'save'], 'reopen' => 'bottom-left'],
        'standalone' => ['show' => ['reject', 'accept', 'manage'], 'reopen' => 'bottom-right'],
    ];

    /**
     * @return array{show?: list<string>, reopen?: string}
     */
    public static function validate(mixed $buttons, string $banner, string $path): array
    {
        if (!is_array($buttons) || (array_keys($buttons) !== [] && array_is_list($buttons))
            || array_diff(array_keys($buttons), ['show', 'reopen']) !== []
        ) {
            throw new \InvalidArgumentException(
                $path.' must be a mapping with only show and reopen.',
            );
        }
        $allowed = self::BUTTON_DEFAULTS[$banner]['show'] ?? [];
        $result = [];
        if (array_key_exists('show', $buttons)) {
            $show = $buttons['show'];
            if (!is_array($show) || !array_is_list($show) || $show === []
                || array_diff($show, $allowed) !== []
                || count(array_unique($show)) !== count($show)
                || array_filter($show, 'is_string') !== $show
            ) {
                throw new \InvalidArgumentException(
                    $path.'.show must list each of '.implode(', ', $allowed)
                    .' at most once, in display order.',
                );
            }
            $grant = $banner === 'builtin' ? 'save' : 'manage';
            if (!in_array('reject', $show, true)) {
                throw new \InvalidArgumentException(
                    $path.'.show must include reject, so refusing is always one click, '
                    .'as easy as accepting.',
                );
            }
            if (!in_array('accept', $show, true) && !in_array($grant, $show, true)) {
                throw new \InvalidArgumentException(
                    $path.'.show must include accept or '.$grant
                    .', so visitors can allow categories.',
                );
            }
            $result['show'] = $show;
        }
        if (array_key_exists('reopen', $buttons)) {
            if (!in_array($buttons['reopen'], self::REOPEN_POSITIONS, true)) {
                throw new \InvalidArgumentException(
                    $path.'.reopen must be one of '.implode(', ', self::REOPEN_POSITIONS).'.',
                );
            }
            $result['reopen'] = $buttons['reopen'];
        }

        return $result;
    }

    public static function validateLayout(mixed $layout, string $path): string
    {
        if (!is_string($layout) || !in_array($layout, self::LAYOUTS, true)) {
            throw new \InvalidArgumentException(
                $path.' must be one of '.implode(', ', self::LAYOUTS).'.',
            );
        }

        return $layout;
    }
}
