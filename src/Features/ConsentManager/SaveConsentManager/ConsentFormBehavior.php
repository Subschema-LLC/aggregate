<?php

declare(strict_types=1);

namespace App\Features\ConsentManager\SaveConsentManager;

use App\Entities\ConsentManager\ConsentButtons;
use App\Entities\ConsentManager\ConsentRegions;

/**
 * Extracts button arrangements and consent behavior options from form inputs.
 */
final class ConsentFormBehavior
{
    /**
     * @param array<string, mixed> $buttonsInput
     * @return array<string, mixed>
     */
    public static function buttons(array $buttonsInput): array
    {
        $buttons = [];
        $defaultButtons = ConsentButtons::BUTTON_DEFAULTS['builtin'];
        $show = $buttonsInput['show'] ?? implode(',', $defaultButtons['show']);
        if (!is_string($show) || !array_key_exists($show, ConsentFormValues::arrangements())) {
            throw new \InvalidArgumentException(
                'Choose one of the listed button arrangements. Nothing was saved.',
            );
        }
        if ($show !== implode(',', $defaultButtons['show'])) {
            $buttons['show'] = explode(',', $show);
        }
        $reopen = $buttonsInput['reopen'] ?? $defaultButtons['reopen'];
        if ($reopen !== $defaultButtons['reopen']) {
            $buttons['reopen'] = $reopen;
        }
        if (array_diff(array_keys($buttonsInput), ['show', 'reopen']) !== []) {
            throw new \InvalidArgumentException(
                'The consent manager form contained an unexpected button setting. '
                .'Nothing was saved.',
            );
        }

        return $buttons;
    }

    /**
     * @param array<string, mixed> $form
     * @return array<string, mixed>
     */
    public static function behavior(array $form): array
    {
        $behavior = [];
        if (array_key_exists('consent_type', $form)) {
            $type = $form['consent_type'];
            $behavior['consent_type'] = $type === ConsentRegions::DEFAULT_CONSENT_TYPE
                ? null
                : $type;
        }
        if (array_key_exists('close_button', $form)) {
            $behavior['close_button'] = $form['close_button'] === ''
                ? null
                : $form['close_button'];
        }
        if (array_key_exists('default_granted', $form)) {
            $granted = ConsentFormRegions::submittedCategories(
                $form['default_granted'],
                'Default-on categories',
            );
            $sorted = $granted;
            sort($sorted);
            $behavior['default_granted'] = $sorted === ConsentRegions::DEFAULT_GRANTED
                ? null
                : $granted;
        }
        if (array_key_exists('respect_gpc', $form)) {
            if (!in_array($form['respect_gpc'], ['0', '1'], true)) {
                throw new \InvalidArgumentException(
                    'The Global Privacy Control setting must be on or off. Nothing was saved.',
                );
            }
            $behavior['respect_gpc'] = $form['respect_gpc'] === '1' ? null : false;
        }

        return $behavior;
    }
}
