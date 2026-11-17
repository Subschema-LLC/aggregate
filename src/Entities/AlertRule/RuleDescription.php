<?php

declare(strict_types=1);

namespace App\Entities\AlertRule;

/**
 * A rule's condition and window in plain language, such as
 * "Fewer than 5 enhanced events in 1 day (24 h)", for pages and alert emails.
 *
 * @phpstan-import-type RuleArray from AlertRule
 */
final class RuleDescription
{
    /** @param RuleArray $rule */
    public static function condition(array $rule): string
    {
        $windowLabel = match ($rule['window']) {
            AlertRule::WINDOW_HOUR => '1 hour (1 h)',
            AlertRule::WINDOW_DAY => '1 day (24 h)',
            AlertRule::WINDOW_WEEK => '1 week (168 h)',
        };

        if ($rule['source'] === AlertRule::SOURCE_TRACKING_FAILURES) {
            return sprintf(
                'More than %d tracking failures in %s',
                $rule['threshold'],
                $windowLabel,
            );
        }

        $modeLabel = match ($rule['privacy_mode']) {
            AlertRule::PRIVACY_ENHANCED => 'enhanced ',
            AlertRule::PRIVACY_ANONYMOUS => 'anonymous ',
            default => '',
        };
        $eventLabel = $rule['event_name'] !== '' ? sprintf('"%s" ', $rule['event_name']) : '';
        $allModes = $rule['privacy_mode'] === AlertRule::PRIVACY_ALL && $rule['event_name'] === '';
        $modeSuffix = $allModes ? ' (all modes)' : '';
        $what = $modeLabel.$eventLabel.'events'.$modeSuffix;

        if ($rule['condition'] === AlertRule::CONDITION_NO_EVENTS) {
            return sprintf('No %s in %s', $what, $windowLabel);
        }

        return sprintf('Fewer than %d %s in %s', $rule['threshold'], $what, $windowLabel);
    }
}
