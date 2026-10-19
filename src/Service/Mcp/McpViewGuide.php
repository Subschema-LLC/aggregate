<?php

declare(strict_types=1);

namespace App\Service\Mcp;

use App\Service\ViewSync\ViewCatalog;

/**
 * What an assistant needs to read the reporting views correctly, beyond each
 * column's description: the rules every answer follows, and each view's
 * grain, time column and standing notes. The same text goes to the
 * initialize instructions, describe_view, list_views and the guide resource,
 * and follows docs/BI-CONNECTION.md.
 */
final class McpViewGuide
{
    /** The event views bi_anonymous_coverage_v1 reports on. */
    public const COVERAGE_VIEWS = ['bi_anonymous_events_v1', 'bi_anonymous_daily_v1', 'bi_anonymous_daily_rollup_v1'];
    public const COVERAGE_VIEW = 'bi_anonymous_coverage_v1';
    /** Time columns, in the order a view's own is looked for. */
    private const TIME_COLUMNS = ['event_hour', 'event_day', 'created_at'];

    private const GRAINS = [
        'bi_anonymous_events_v1' => 'one row per completed hour, website, event, page and coarse dimensions',
        'bi_anonymous_goals_v1' => 'one row per completed UTC day, website and goal',
        'bi_anonymous_geo_events_v1' => 'one row per completed UTC day, website, event and area',
        'bi_anonymous_daily_v1' => 'one row per completed day, website, event, page group, channel and device',
        'bi_anonymous_daily_rollup_v1' => 'daily rows at several levels of detail (detail_level), each event counted once',
        'bi_anonymous_coverage_v1' => 'one row per completed day, website and event view, with a coverage band',
    ];

    private const NOTES = [
        'bi_anonymous_events_v1' => [
            'The most detailed view, so small sites lose the most to withholding. For daily questions about a small site, prefer bi_anonymous_daily_rollup_v1.',
        ],
        'bi_anonymous_daily_v1' => [
            'page_group is a page group label from the administrator\'s rules, or the sanitized path when no rule matches.',
            'Cells are withheld when small or when they would let another view be subtracted to reveal a small count.',
        ],
        'bi_anonymous_daily_rollup_v1' => [
            'Each event is in at most one row, so summing event_count by day adds up to the day\'s released events, usually nearly all of them.',
            '(other) in a column means the part not shown in more detailed rows, not every value. detail_level says which columns are known in a row: page_channel_device, page_channel, page, channel or day.',
            'Grouping by page_group gives each page group\'s released events plus an (other) row for events whose page is not shown; report that row as unattributed, not as a page.',
        ],
        'bi_anonymous_goals_v1' => [
            'event_count counts goal occurrences, not converters. Days are UTC whatever the reporting timezone.',
        ],
        'bi_anonymous_geo_events_v1' => [
            'Small countries are pooled into continent:other or country:other rows rather than withheld. Days are UTC.',
        ],
        'bi_anonymous_coverage_v1' => [
            'coverage_band is the share of the day\'s anonymous events the named view releases: 90-100, 50-90, 10-50 or 0-10. Days with too little traffic for a band are left out. Use get_coverage rather than querying this view directly.',
        ],
    ];

    /**
     * The rules for every answer, as one text.
     */
    public static function instructions(string $name): string
    {
        return implode("\n", [
            $name.' counts what happens on websites without identifying visitors. Read its data only through these tools.',
            '- event_count counts events (page views, interactions, goal occurrences), never people, visits or sessions. To add counts up, use query_view with group_by, which sums event_count; never count rows.',
            '- Small counts are withheld for privacy, and the current hour and day are never included. A missing row means withheld or not complete yet, never zero. Say so rather than reporting zero or a drop.',
            '- Before reporting a total or a trend from an event view, call get_coverage for the same days. A low band means much of that day was withheld.',
            '- Each view stands alone. Never add, subtract or join results from different views, or try to work out a withheld count.',
            '- Times are labels in the time zone describe_view states for each column, without an offset.',
            '- For small sites, prefer bi_anonymous_daily_rollup_v1: its daily totals add up, and its (other) rows pool what more detailed rows withhold.',
            '- Start with list_views, then describe_view for the view you will query.',
        ]);
    }

    public static function grain(string $view): string
    {
        return self::GRAINS[$view] ?? match (true) {
            str_starts_with($view, 'bi_dim_') => 'one row per code, with its label',
            str_starts_with($view, 'bi_glossary_') => 'definitions, one row per code or column and locale',
            ViewCatalog::isPrivate($view) => 'row-level or unsuppressed rows, outside the privacy protections',
            default => 'rows',
        };
    }

    /** @return list<string> */
    public static function notes(string $view): array
    {
        $notes = self::NOTES[$view] ?? [];
        if (ViewCatalog::isPrivate($view)) {
            $notes[] = 'A private view: small counts are not withheld and rows may describe individual events. Report only what the person asking needs, never one visitor\'s activity.';
        }
        if (str_starts_with($view, 'bi_dim_') || str_starts_with($view, 'bi_glossary_')) {
            $notes[] = 'Labels and definitions only, no traffic. describe_view already lists the codes of each event view column.';
        }

        return $notes;
    }

    /** @param list<string> $columns */
    public static function timeColumn(array $columns): ?string
    {
        foreach (self::TIME_COLUMNS as $column) {
            if (in_array($column, $columns, true)) {
                return $column;
            }
        }

        return null;
    }
}
