<?php

declare(strict_types=1);

namespace App\Entities\AlertRule;

/**
 * What a rule counts: collected events, optionally narrowed to one privacy
 * mode and one event name, or every ingest and storage failure. The filters
 * may be given at the top of the rule or under filter.
 */
final class RuleFilter
{
    /**
     * @param array<string, mixed> $candidate
     *
     * @return array{
     *     source: 'events'|'tracking_failures',
     *     privacy_mode: 'all'|'enhanced'|'anonymous',
     *     event_name: string
     * }
     */
    public static function fromInput(array $candidate, EventNames $eventNames): array
    {
        $source = $candidate['source'] ?? AlertRule::SOURCE_EVENTS;
        $source = is_string($source) ? strtolower(trim($source)) : null;
        if (!in_array($source, AlertRule::SOURCES, true)) {
            throw new \InvalidArgumentException('Rule source must be events or tracking_failures.');
        }

        $filter = $candidate['filter'] ?? [];
        if (!is_array($filter) || ($filter !== [] && array_is_list($filter))) {
            throw new \InvalidArgumentException('Rule filter must be a mapping.');
        }
        $unknown = array_diff(array_keys($filter), ['privacy_mode', 'event_name']);
        if ($unknown !== []) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown alert rule filter "%s". Use privacy_mode and event_name.',
                (string) reset($unknown),
            ));
        }

        $privacyMode = self::privacyMode(
            $candidate['privacy_mode'] ?? $filter['privacy_mode'] ?? AlertRule::PRIVACY_ALL,
        );

        $rawEventName = $candidate['event_name'] ?? $filter['event_name'] ?? '';
        if (!is_string($rawEventName)) {
            throw new \InvalidArgumentException('Event name filter must be text.');
        }
        $eventName = trim($rawEventName);
        if ($eventName !== '' && !$eventNames->isSafe($eventName)) {
            throw new \InvalidArgumentException('Event name filter must use the safe event-name '
                .'format (letters, numbers, underscores, dots, colons or hyphens, starting with a '
                .'letter).');
        }

        $filtered = $privacyMode !== AlertRule::PRIVACY_ALL || $eventName !== '';
        if ($source === AlertRule::SOURCE_TRACKING_FAILURES && $filtered) {
            throw new \InvalidArgumentException('Privacy mode and event name filters apply only '
                .'to the events source; tracking_failures counts all ingest and storage failures.');
        }

        return ['source' => $source, 'privacy_mode' => $privacyMode, 'event_name' => $eventName];
    }

    /** @return 'all'|'enhanced'|'anonymous' */
    private static function privacyMode(mixed $raw): string
    {
        $mode = is_string($raw) ? strtolower(trim($raw)) : null;
        if ($mode === '') {
            return AlertRule::PRIVACY_ALL;
        }

        return match ($mode) {
            AlertRule::PRIVACY_ALL => AlertRule::PRIVACY_ALL,
            AlertRule::PRIVACY_ENHANCED => AlertRule::PRIVACY_ENHANCED,
            AlertRule::PRIVACY_ANONYMOUS => AlertRule::PRIVACY_ANONYMOUS,
            default => throw new \InvalidArgumentException(
                'Privacy mode filter must be all, enhanced or anonymous.',
            ),
        };
    }
}
