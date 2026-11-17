<?php

declare(strict_types=1);

namespace App\Entities\AlertRule;

/**
 * A threshold to watch: what it covers, which events or failures it counts,
 * the condition and window that make it fire, and the destination IDs to
 * notify. An AlertRule always holds a valid rule; fromInput() throws
 * \InvalidArgumentException otherwise.
 *
 * @phpstan-type RuleArray array{
 *     id: string,
 *     enabled: bool,
 *     covers: string,
 *     source: 'events'|'tracking_failures',
 *     privacy_mode: 'all'|'enhanced'|'anonymous',
 *     event_name: string,
 *     condition: 'no_events'|'fewer_than'|'more_than',
 *     threshold: int,
 *     window: 'hour'|'day'|'week',
 *     destinations: list<string>
 * }
 */
final class AlertRule
{
    public const SOURCE_EVENTS = 'events';
    public const SOURCE_TRACKING_FAILURES = 'tracking_failures';
    public const SOURCES = [self::SOURCE_EVENTS, self::SOURCE_TRACKING_FAILURES];

    public const PRIVACY_ALL = 'all';
    public const PRIVACY_ENHANCED = 'enhanced';
    public const PRIVACY_ANONYMOUS = 'anonymous';
    public const PRIVACY_MODES = [
        self::PRIVACY_ALL, self::PRIVACY_ENHANCED, self::PRIVACY_ANONYMOUS,
    ];

    public const CONDITION_NO_EVENTS = 'no_events';
    public const CONDITION_FEWER_THAN = 'fewer_than';
    public const CONDITION_MORE_THAN = 'more_than';
    public const CONDITIONS = [
        self::CONDITION_NO_EVENTS, self::CONDITION_FEWER_THAN, self::CONDITION_MORE_THAN,
    ];

    public const WINDOW_HOUR = 'hour';
    public const WINDOW_DAY = 'day';
    public const WINDOW_WEEK = 'week';
    public const WINDOWS = [
        self::WINDOW_HOUR => 1,
        self::WINDOW_DAY => 24,
        self::WINDOW_WEEK => 168,
    ];

    /**
     * The counting query stops at N rows, so N is also the most rows one check
     * may read for a website. Keep it well below "count everything".
     */
    public const MAX_THRESHOLD = 100_000;

    private const FIELDS = [
        'id', 'rule', 'enabled', 'covers', 'website', 'websites', 'group', 'website_group',
        'source', 'filter', 'privacy_mode', 'event_name', 'condition', 'threshold', 'n',
        'window', 'destinations',
    ];

    /** @param RuleArray $values */
    private function __construct(private readonly array $values)
    {
    }

    /**
     * Accepts what YAML, the dashboard form and the command line send. The
     * references are checked as soon as the value they concern is read, so the
     * first problem in the input is the one reported.
     *
     * @param array<string, mixed> $candidate
     * @param list<string>|null    $knownDestinationIds when given, every destination must be in it
     */
    public static function fromInput(
        array $candidate,
        EventNames $eventNames,
        ?RuleReferences $references = null,
        ?array $knownDestinationIds = null,
    ): self {
        $unknown = array_diff(array_keys($candidate), self::FIELDS);
        if ($unknown !== []) {
            throw new \InvalidArgumentException(
                sprintf('Unknown alert rule field "%s".', (string) reset($unknown)),
            );
        }

        $id = $candidate['id'] ?? $candidate['rule'] ?? null;
        if (!is_string($id) || preg_match('/^[a-z0-9][a-z0-9_-]{0,62}$/D', trim($id)) !== 1) {
            throw new \InvalidArgumentException('Rule ID (rule) must be 1 to 63 lowercase '
                .'letters, numbers, hyphens or underscores.');
        }

        $enabled = RuleInput::boolean($candidate['enabled'] ?? true, 'enabled');
        $covers = RuleCovers::fromInput($candidate);
        $references?->assertCoversExist($covers);

        $filter = RuleFilter::fromInput($candidate, $eventNames);
        $source = $filter['source'];

        $rawCondition = $candidate['condition']
            ?? ($source === self::SOURCE_TRACKING_FAILURES ? self::CONDITION_MORE_THAN : null);
        $condition = is_string($rawCondition) ? strtolower(trim($rawCondition)) : null;
        $eventConditions = [self::CONDITION_NO_EVENTS, self::CONDITION_FEWER_THAN];
        if ($source === self::SOURCE_EVENTS && !in_array($condition, $eventConditions, true)) {
            throw new \InvalidArgumentException(
                'Condition for the events source must be no_events or fewer_than.',
            );
        }
        $failures = $source === self::SOURCE_TRACKING_FAILURES;
        if ($failures && $condition !== self::CONDITION_MORE_THAN) {
            throw new \InvalidArgumentException(
                'Condition for the tracking_failures source must be more_than.',
            );
        }
        /** @var 'no_events'|'fewer_than'|'more_than' $condition */
        $rawThreshold = $candidate['threshold'] ?? $candidate['n']
            ?? ($condition === self::CONDITION_NO_EVENTS ? 0 : null);
        $threshold = RuleInput::threshold($condition, $rawThreshold);

        $window = RuleInput::window($candidate['window'] ?? null);
        $references?->assertWindowFitsRetention($source, $filter['privacy_mode'], $window);

        return new self([
            'id' => trim($id),
            'enabled' => $enabled,
            'covers' => $covers,
            'source' => $source,
            'privacy_mode' => $filter['privacy_mode'],
            'event_name' => $filter['event_name'],
            'condition' => $condition,
            'threshold' => $threshold,
            'window' => $window,
            'destinations' => RuleInput::destinations(
                $candidate['destinations'] ?? [],
                $knownDestinationIds,
            ),
        ]);
    }

    /** @return RuleArray */
    public function toArray(): array
    {
        return $this->values;
    }

    /**
     * The alert_condition text in webhook payload v1, such as "fewer_than_10_per_day".
     *
     * @param RuleArray $rule
     */
    public static function conditionText(array $rule): string
    {
        return match ($rule['condition']) {
            self::CONDITION_NO_EVENTS => 'no_events_per_'.$rule['window'],
            self::CONDITION_FEWER_THAN => sprintf(
                'fewer_than_%d_per_%s',
                $rule['threshold'],
                $rule['window'],
            ),
            self::CONDITION_MORE_THAN => sprintf(
                'more_than_%d_tracking_failures_per_%s',
                $rule['threshold'],
                $rule['window'],
            ),
        };
    }

    public static function windowHours(string $window): int
    {
        return self::WINDOWS[$window] ?? 1;
    }
}
