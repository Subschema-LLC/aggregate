<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;

final class AnonymousBiViewSqlBuilder
{
    /**
     * Each column of an anonymous event cell, as read from an archived cell
     * and from a live event not yet archived (which counts once). The hourly,
     * daily, rollup and coverage views all read events through these sources.
     */
    private const EVENT_CELL_SOURCES = [
        'website_token' => ['archived_events.website_token', 'events.website_token'],
        'event_hour' => ['archived_events.event_hour', 'events.created_at'],
        'event_name' => ['archived_events.event_name', 'events.event_name'],
        'page_path' => ['archived_events.page_path', 'events.url'],
        'referrer_channel' => ['archived_events.referrer_channel', "COALESCE(events.referrer, 'unknown')"],
        'device_class' => ['archived_events.device_class', 'events.device_class'],
        'viewport_bucket' => ['archived_events.viewport_bucket', 'events.viewport_bucket'],
        'event_count' => ['archived_events.event_count', '1'],
    ];

    private ?bool $shifts = null;
    private ?ReportingTimeSql $timeSql = null;

    /**
     * @param array<string, array{name: string, members: list<string>}> $websiteGroups
     */
    public function __construct(
        private readonly AbstractPlatform $platform,
        private readonly string $reportingTimezone = ReportingTimezone::DEFAULT,
        private readonly ?string $websiteGroupColumn = null,
        private readonly array $websiteGroups = [],
    ) {
        if ($this->reportingTimezone !== ReportingTimezone::DEFAULT) {
            ReportingTimezone::validate($this->reportingTimezone);
        }
        if ($this->websiteGroupColumn !== null && $this->websiteGroupColumn !== '') {
            self::assertValidColumnName($this->websiteGroupColumn);
            self::validateWebsiteGroups($this->websiteGroups);
        }
    }

    public function dropViewIfExistsSql(string $viewName): string
    {
        if ($this->platform instanceof SQLServerPlatform) {
            return sprintf("IF OBJECT_ID('%1\$s', 'V') IS NOT NULL DROP VIEW %1\$s", $viewName);
        }

        return sprintf('DROP VIEW IF EXISTS %s', $viewName);
    }

    public function eventViewSql(int $minimumCellCount): string
    {
        return "CREATE VIEW bi_anonymous_events_v1 AS\n"
            .$this->hourlyCellsSql(
                'event_counts',
                $this->completedHourPredicate('archived_events.event_hour'),
                $this->completedHourPredicate('events.created_at'),
                $this->shiftsTime(),
            )
            .sprintf(<<<'SQL'

SELECT
    event_counts.website_token,
    event_counts.event_hour,
    event_counts.event_name,
    event_counts.page_path,
    event_counts.referrer_channel,
    event_counts.device_class,
    event_counts.viewport_bucket,
    event_counts.event_count%2$s
FROM event_counts
WHERE %1$s
SQL, self::hourlyCellReleased('event_counts.event_count', $minimumCellCount), $this->websiteGroupSelect('event_counts.website_token'));
    }

    public function goalViewSql(int $minimumCellCount): string
    {
        return sprintf(<<<'SQL'
CREATE VIEW bi_anonymous_goals_v1 AS
WITH combined_goal_cells AS (
    SELECT
        archived_goals.website_token,
        archived_goals.event_day,
        archived_goals.goal_event,
        archived_goals.event_count
    FROM analytics_archive_goals archived_goals
    WHERE archived_goals.privacy_mode = 'anonymous'
      AND %1$s
    UNION ALL
    SELECT
        events.website_token,
        %2$s AS event_day,
        events.goal_event,
        1 AS event_count
    FROM events
    WHERE events.archived_at IS NULL
      AND events.privacy_mode = 'anonymous'
      AND events.goal_event IS NOT NULL
      AND %3$s
),
goal_counts AS (
    SELECT
        website_token,
        event_day,
        goal_event,
        SUM(event_count) AS event_count
    FROM combined_goal_cells
    GROUP BY
        website_token,
        event_day,
        goal_event
)
SELECT
    goal_counts.website_token,
    goal_counts.event_day,
    goal_counts.goal_event,
    goal_counts.event_count%5$s
FROM goal_counts
WHERE goal_counts.event_count >= %4$d
SQL, $this->completedDayPredicate('archived_goals.event_day'), $this->eventDayExpression('events.created_at'), $this->completedDayPredicate('events.created_at'), $minimumCellCount, $this->websiteGroupSelect('goal_counts.website_token'));
    }

    public function geoViewSql(int $geoMinimumCellCount): string
    {
        return sprintf(<<<'SQL'
CREATE VIEW bi_anonymous_geo_events_v1 AS
WITH combined_geo_cells AS (
    SELECT
        archived_geo.website_token,
        archived_geo.event_day,
        archived_geo.event_name,
        archived_geo.geo_area,
        archived_geo.event_count
    FROM analytics_archive_geo_events archived_geo
    WHERE archived_geo.privacy_mode = 'anonymous'
      AND (
          archived_geo.geo_area LIKE 'country:__'
          OR archived_geo.geo_area LIKE 'continent:__'
      )
      AND %1$s
    UNION ALL
    SELECT
        events.website_token,
        %2$s AS event_day,
        events.event_name,
        events.geo_area,
        1 AS event_count
    FROM events
    WHERE events.archived_at IS NULL
      AND events.privacy_mode = 'anonymous'
      AND (
          events.geo_area LIKE 'country:__'
          OR events.geo_area LIKE 'continent:__'
      )
      AND %3$s
),
geo_counts AS (
    SELECT
        combined_geo_cells.website_token,
        combined_geo_cells.event_day,
        combined_geo_cells.event_name,
        CASE
            WHEN combined_geo_cells.geo_area LIKE 'country:%%' THEN 'country'
            ELSE 'continent'
        END AS geo_level,
        combined_geo_cells.geo_area,
        SUM(combined_geo_cells.event_count) AS event_count,
        %4$d AS minimum_cell_count
    FROM combined_geo_cells
    GROUP BY
        combined_geo_cells.website_token,
        combined_geo_cells.event_day,
        combined_geo_cells.event_name,
        CASE
            WHEN combined_geo_cells.geo_area LIKE 'country:%%' THEN 'country'
            ELSE 'continent'
        END,
        combined_geo_cells.geo_area
),
ranked_geo_counts AS (
    SELECT
        website_token,
        event_day,
        event_name,
        geo_level,
        geo_area,
        event_count,
        minimum_cell_count,
        SUM(
            CASE WHEN event_count < minimum_cell_count THEN 1 ELSE 0 END
        ) OVER (
            PARTITION BY website_token, event_day, event_name, geo_level
        ) AS primary_suppressed_count,
        ROW_NUMBER() OVER (
            PARTITION BY website_token, event_day, event_name, geo_level
            ORDER BY
                CASE WHEN event_count >= minimum_cell_count THEN 0 ELSE 1 END,
                event_count,
                geo_area
        ) AS visible_rank
    FROM geo_counts
),
classified_geo_counts AS (
    SELECT
        website_token,
        event_day,
        event_name,
        geo_level,
        geo_area,
        event_count,
        minimum_cell_count,
        CASE
            WHEN event_count < minimum_cell_count THEN 1
            WHEN primary_suppressed_count = 1 AND visible_rank = 1 THEN 1
            ELSE 0
        END AS pool_in_other
    FROM ranked_geo_counts
)
SELECT
    website_token,
    event_day,
    event_name,
    geo_area,
    event_count%5$s
FROM classified_geo_counts
WHERE event_count >= minimum_cell_count
  AND pool_in_other = 0
UNION ALL
SELECT
    website_token,
    event_day,
    event_name,
    CASE
        WHEN geo_level = 'country' THEN 'country:other'
        ELSE 'continent:other'
    END AS geo_area,
    SUM(event_count) AS event_count%5$s
FROM classified_geo_counts
WHERE pool_in_other = 1
GROUP BY
    website_token,
    event_day,
    event_name,
    geo_level,
    minimum_cell_count
HAVING SUM(event_count) >= minimum_cell_count
SQL, $this->completedDayPredicate('archived_geo.event_day'), $this->eventDayExpression('events.created_at'), $this->completedDayPredicate('events.created_at'), $geoMinimumCellCount, $this->websiteGroupSelect('website_token'));
    }

    /**
     * Completed UTC-day cells by page group, channel and device, released only
     * when they cannot reveal a count the hourly view withholds.
     *
     * The hourly cells inside each daily cell are rebuilt here exactly as
     * bi_anonymous_events_v1 builds them, with the same threshold. A daily cell
     * is released when it reaches the threshold and the part of it the hourly
     * view withholds is either zero or at least the threshold, so subtracting
     * released hourly cells from a released daily cell never yields a count
     * below the threshold. The manager always regenerates both views together
     * with one threshold; they must never differ.
     *
     * @param list<array{pattern: string, group: string}> $pageGroups
     */
    public function dailyViewSql(int $minimumCellCount, array $pageGroups = []): string
    {
        return "CREATE VIEW bi_anonymous_daily_v1 AS\n".$this->dailyCellsSql($minimumCellCount, $pageGroups).sprintf(<<<'SQL'

SELECT
    daily_cells.website_token,
    daily_cells.event_day,
    daily_cells.event_name,
    daily_cells.page_group,
    daily_cells.referrer_channel,
    daily_cells.device_class,
    daily_cells.event_count%2$s
FROM daily_cells
WHERE daily_cells.event_count >= %1$d
  AND (
      daily_cells.event_count = daily_cells.released_hourly_count
      OR daily_cells.event_count - daily_cells.released_hourly_count >= %1$d
  )
SQL, $minimumCellCount, $this->websiteGroupSelect('daily_cells.website_token'));
    }

    /**
     * The daily cells, then the cells the daily view withholds rolled up
     * through coarser rows instead of dropped: devices pooled, then channels,
     * then pages (keeping the channel), then everything for the event and day.
     * A pooled value is "(other)": the part not shown in more detailed rows.
     *
     * Every released row, at every level, reaches the threshold and contains
     * an hourly-withheld part that is zero or at least the threshold. Each
     * daily cell lands in exactly one row, so rows never overlap, and any set
     * of rows minus the released hourly cells inside it leaves zero or at least
     * the threshold. The most detailed rows are exactly bi_anonymous_daily_v1.
     * Whatever is still below the threshold after the last level is dropped.
     *
     * @param list<array{pattern: string, group: string}> $pageGroups
     */
    public function rollupViewSql(int $minimumCellCount, array $pageGroups = []): string
    {
        // The group sums are computed in their own step and compared in the
        // next: MariaDB evaluates several window functions combined in one
        // boolean expression incorrectly, so no expression mixes them.
        $level = static function (string $name, string $from, string $partition) use ($minimumCellCount): string {
            return sprintf(<<<'SQL'
,
%1$s_sums AS (
    SELECT
        website_token,
        event_day,
        event_name,
        page_group,
        referrer_channel,
        device_class,
        event_count,
        withheld_hourly_count,
        SUM(event_count) OVER (PARTITION BY %3$s) AS group_count,
        SUM(withheld_hourly_count) OVER (PARTITION BY %3$s) AS group_withheld_count
    FROM %2$s
    WHERE is_released = 0
),
%1$s AS (
    SELECT
        website_token,
        event_day,
        event_name,
        page_group,
        referrer_channel,
        device_class,
        event_count,
        withheld_hourly_count,
        CASE
            WHEN group_count >= %4$d
             AND (group_withheld_count = 0 OR group_withheld_count >= %4$d)
            THEN 1 ELSE 0
        END AS is_released
    FROM %1$s_sums
)
SQL, $name, $from, $partition, $minimumCellCount);
        };

        return "CREATE VIEW bi_anonymous_daily_rollup_v1 AS\n"
            .$this->dailyCellsSql($minimumCellCount, $pageGroups)
            .sprintf(<<<'SQL'
,
level_full AS (
    SELECT
        website_token,
        event_day,
        event_name,
        page_group,
        referrer_channel,
        device_class,
        event_count,
        event_count - released_hourly_count AS withheld_hourly_count,
        CASE
            WHEN event_count >= %1$d
             AND (event_count = released_hourly_count OR event_count - released_hourly_count >= %1$d)
            THEN 1 ELSE 0
        END AS is_released
    FROM daily_cells
)
SQL, $minimumCellCount)
            .$level('level_page_channel', 'level_full', 'website_token, event_day, event_name, page_group, referrer_channel')
            .$level('level_page', 'level_page_channel', 'website_token, event_day, event_name, page_group')
            .$level('level_channel', 'level_page', 'website_token, event_day, event_name, referrer_channel')
            .$level('level_day', 'level_channel', 'website_token, event_day, event_name')
            .sprintf(<<<'SQL'

SELECT
    website_token,
    event_day,
    event_name,
    page_group,
    referrer_channel,
    device_class,
    event_count,
    %1$s AS detail_level%6$s
FROM level_full
WHERE is_released = 1
UNION ALL
SELECT
    website_token,
    event_day,
    event_name,
    page_group,
    referrer_channel,
    '(other)' AS device_class,
    SUM(event_count) AS event_count,
    %2$s AS detail_level%6$s
FROM level_page_channel
WHERE is_released = 1
GROUP BY website_token, event_day, event_name, page_group, referrer_channel
UNION ALL
SELECT
    website_token,
    event_day,
    event_name,
    page_group,
    '(other)' AS referrer_channel,
    '(other)' AS device_class,
    SUM(event_count) AS event_count,
    %3$s AS detail_level%6$s
FROM level_page
WHERE is_released = 1
GROUP BY website_token, event_day, event_name, page_group
UNION ALL
SELECT
    website_token,
    event_day,
    event_name,
    '(other)' AS page_group,
    referrer_channel,
    '(other)' AS device_class,
    SUM(event_count) AS event_count,
    %4$s AS detail_level%6$s
FROM level_channel
WHERE is_released = 1
GROUP BY website_token, event_day, event_name, referrer_channel
UNION ALL
SELECT
    website_token,
    event_day,
    event_name,
    '(other)' AS page_group,
    '(other)' AS referrer_channel,
    '(other)' AS device_class,
    SUM(event_count) AS event_count,
    %5$s AS detail_level%6$s
FROM level_day
WHERE is_released = 1
GROUP BY website_token, event_day, event_name
SQL,
                $this->label('page_channel_device'),
                $this->label('page_channel'),
                $this->label('page'),
                $this->label('channel'),
                $this->label('day'),
                $this->websiteGroupSelect('website_token'),
            );
    }

    /**
     * For each website and completed UTC day with at least the threshold of
     * anonymous events, the share of those events each event view releases,
     * as a band rather than a number: an exact share would reveal the day's
     * total and so the withheld remainder. It reads the published views, so it
     * reports exactly what they release, and is created after them.
     */
    public function coverageViewSql(int $minimumCellCount): string
    {
        return "CREATE VIEW bi_anonymous_coverage_v1 AS\n"
            .self::combinedEventCellsSql(
                self::eventCellColumns('website_token', 'event_hour', 'event_count'),
                $this->reportingDayPredicate('archived_events.event_hour'),
                $this->reportingDayPredicate('events.created_at'),
            )
            .sprintf(<<<'SQL'
,
day_totals AS (
    SELECT
        website_token,
        %1$s AS event_day,
        SUM(event_count) AS total_count
    FROM combined_event_cells
    GROUP BY
        website_token,
        %1$s
),
view_names AS (
    SELECT 1 AS view_id, %5$s AS view_name
    UNION ALL
    SELECT 2 AS view_id, %6$s AS view_name
    UNION ALL
    SELECT 3 AS view_id, %7$s AS view_name
),
released AS (
    SELECT
        1 AS view_id,
        website_token,
        %2$s AS event_day,
        SUM(event_count) AS released_count
    FROM bi_anonymous_events_v1
    WHERE %3$s
    GROUP BY
        website_token,
        %2$s
    UNION ALL
    SELECT
        2 AS view_id,
        website_token,
        event_day,
        SUM(event_count) AS released_count
    FROM bi_anonymous_daily_v1
    GROUP BY
        website_token,
        event_day
    UNION ALL
    SELECT
        3 AS view_id,
        website_token,
        event_day,
        SUM(event_count) AS released_count
    FROM bi_anonymous_daily_rollup_v1
    GROUP BY
        website_token,
        event_day
)
SELECT
    day_totals.website_token,
    day_totals.event_day,
    view_names.view_name,
    CASE
        WHEN COALESCE(released.released_count, 0) * 10 >= day_totals.total_count * 9 THEN %8$s
        WHEN COALESCE(released.released_count, 0) * 2 >= day_totals.total_count THEN %9$s
        WHEN COALESCE(released.released_count, 0) * 10 >= day_totals.total_count THEN %10$s
        ELSE %11$s
    END AS coverage_band%12$s
FROM day_totals
CROSS JOIN view_names
LEFT JOIN released
    ON released.website_token = day_totals.website_token
   AND released.event_day = day_totals.event_day
   AND released.view_id = view_names.view_id
WHERE day_totals.total_count >= %4$d
SQL,
            $this->reportingDayExpression('event_hour'),
            $this->shiftsTime()
                ? $this->timeSql()->localDayExpression('bi_anonymous_events_v1.event_hour')
                : $this->eventDayExpression('bi_anonymous_events_v1.event_hour'),
            $this->shiftsTime()
                ? $this->timeSql()->completedLocalDayPredicate('bi_anonymous_events_v1.event_hour')
                : $this->completedDayPredicate('bi_anonymous_events_v1.event_hour'),
            $minimumCellCount,
            $this->label('bi_anonymous_events_v1'),
            $this->label('bi_anonymous_daily_v1'),
            $this->label('bi_anonymous_daily_rollup_v1'),
            $this->label('90-100'),
            $this->label('50-90'),
            $this->label('10-50'),
            $this->label('0-10'),
            $this->websiteGroupSelect('day_totals.website_token'),
        );
    }

    /**
     * A string literal for a column whose values are all literals. MySQL and
     * MariaDB give literals stored in a view the character set's default
     * collation, which can differ from a client's connection collation (MySQL
     * 8's utf8mb4_0900_ai_ci against PHP's utf8mb4_general_ci), and comparing
     * the two fails with "Illegal mix of collations". An explicit collation,
     * the one Doctrine gives the tables, makes such filters work for any
     * client.
     */
    private function label(string $value): string
    {
        $literal = $this->platform->quoteStringLiteral($value);

        return $this->platform instanceof AbstractMySQLPlatform ? $literal.' COLLATE utf8mb4_unicode_ci' : $literal;
    }

    /**
     * The shared CTEs of the daily views, from the WITH clause through
     * daily_cells: the hourly cells rebuilt exactly as bi_anonymous_events_v1
     * builds them, mapped to page groups and summed by completed UTC day, with
     * the part the hourly view releases at this threshold.
     *
     * @param list<array{pattern: string, group: string}> $pageGroups
     */
    private function dailyCellsSql(int $minimumCellCount, array $pageGroups): string
    {
        return $this->hourlyCellsSql(
            'hourly_cells',
            $this->reportingDayPredicate('archived_events.event_hour'),
            $this->reportingDayPredicate('events.created_at'),
        )
            .sprintf(<<<'SQL'
,
grouped_cells AS (
    SELECT
        website_token,
        %1$s AS event_day,
        event_name,
        %2$s AS page_group,
        referrer_channel,
        device_class,
        event_count,
        CASE WHEN %3$s THEN event_count ELSE 0 END AS released_hourly_count
    FROM hourly_cells
),
daily_cells AS (
    SELECT
        website_token,
        event_day,
        event_name,
        page_group,
        referrer_channel,
        device_class,
        SUM(event_count) AS event_count,
        SUM(released_hourly_count) AS released_hourly_count
    FROM grouped_cells
    GROUP BY
        website_token,
        event_day,
        event_name,
        page_group,
        referrer_channel,
        device_class
)
SQL,
                $this->reportingDayExpression('event_hour'),
                $this->pageGroupExpression('page_path', $pageGroups),
                self::hourlyCellReleased('event_count', $minimumCellCount),
            );
    }

    /**
     * The hourly event cells, from the WITH clause through the CTE named
     * $cells: anonymous events summed per stored UTC hour, event, page path,
     * channel, device and viewport. bi_anonymous_events_v1 releases these
     * cells and the daily views account for the ones it withholds, so both
     * build them here and cannot drift apart.
     *
     * With $localHourLabel, event_hour is the hour in the reporting timezone
     * and utc_hour the stored hour. Cells are still grouped by the stored
     * hour, so two UTC hours that share a local label after a daylight-saving
     * fallback stay separate cells and the threshold applies to each.
     */
    private function hourlyCellsSql(string $cells, string $archivePredicate, string $livePredicate, bool $localHourLabel = false): string
    {
        $columns = self::EVENT_CELL_SOURCES;
        if ($localHourLabel) {
            [$archived, $live] = $columns['event_hour'];
            $columns = [
                'website_token' => $columns['website_token'],
                'utc_hour' => [$archived, $live],
                'event_hour' => [$this->timeSql()->hourLabel($archived), $this->timeSql()->hourLabel($live)],
            ] + $columns;
        }
        $key = implode(",\n        ", array_diff(array_keys($columns), ['event_count']));

        return self::combinedEventCellsSql($columns, $archivePredicate, $livePredicate).<<<SQL
,
{$cells} AS (
    SELECT
        {$key},
        SUM(event_count) AS event_count
    FROM combined_event_cells
    GROUP BY
        {$key}
)
SQL;
    }

    /**
     * Whether the hourly view releases a cell with this count. The daily
     * views use the same test to work out the part of each day the hourly
     * view already shows.
     */
    private static function hourlyCellReleased(string $count, int $minimumCellCount): string
    {
        return sprintf('%s >= %d', $count, $minimumCellCount);
    }

    /**
     * The combined_event_cells CTE that opens the WITH clause: anonymous
     * archived cells and anonymous live events not yet archived, one row per
     * archived cell or live event.
     *
     * @param array<string, array{string, string}> $columns output column => [archived, live] expression
     */
    private static function combinedEventCellsSql(array $columns, string $archivePredicate, string $livePredicate): string
    {
        $select = static function (string $table, int $side) use ($columns): string {
            $items = [];
            foreach ($columns as $column => $sources) {
                $items[] = $sources[$side] === $table.'.'.$column ? $sources[$side] : $sources[$side].' AS '.$column;
            }

            return implode(",\n        ", $items);
        };
        $archived = $select('archived_events', 0);
        $live = $select('events', 1);

        return <<<SQL
WITH combined_event_cells AS (
    SELECT
        {$archived}
    FROM analytics_archive_events archived_events
    WHERE archived_events.privacy_mode = 'anonymous'
      AND {$archivePredicate}
    UNION ALL
    SELECT
        {$live}
    FROM events
    WHERE events.archived_at IS NULL
      AND events.privacy_mode = 'anonymous'
      AND {$livePredicate}
)
SQL;
    }

    /**
     * The named columns of EVENT_CELL_SOURCES, in the order given.
     *
     * @return array<string, array{string, string}>
     */
    private static function eventCellColumns(string ...$names): array
    {
        return array_map(static fn (string $name): array => self::EVENT_CELL_SOURCES[$name], array_combine($names, $names));
    }

    /**
     * A CASE expression mapping a sanitized path to its page group, the path
     * itself when no rule matches. Patterns are validated by BiPageGroups;
     * literals are LIKE-escaped with "!" and quoted for the platform.
     *
     * @param list<array{pattern: string, group: string}> $pageGroups
     */
    public function pageGroupExpression(string $column, array $pageGroups): string
    {
        if ($pageGroups === []) {
            return $column;
        }

        $cases = [];
        foreach (BiPageGroups::validate($pageGroups) as $rule) {
            $cases[] = sprintf('WHEN %s THEN %s', $this->pageGroupCondition($column, $rule['pattern']), $this->platform->quoteStringLiteral($rule['group']));
        }

        return "CASE\n            ".implode("\n            ", $cases)."\n            ELSE ".$column."\n        END";
    }

    /**
     * A CASE expression mapping a website token column to its group's display
     * name from config/website-scopes.yaml, or "(ungrouped)" when the website
     * belongs to no group.
     *
     * @param array<string, array{name: string, members: list<string>}>|null $websiteGroups
     */
    public function websiteGroupExpression(string $column, ?array $websiteGroups = null): string
    {
        $groups = self::validateWebsiteGroups($websiteGroups ?? $this->websiteGroups);
        $fallback = $this->label(CustomDataSettings::UNGROUPED_WEBSITE_GROUP);
        $cases = [];
        foreach ($groups as $group) {
            if ($group['members'] === []) {
                continue;
            }
            $tokens = array_map($this->platform->quoteStringLiteral(...), $group['members']);
            $condition = count($tokens) === 1
                ? sprintf('%s = %s', $column, $tokens[0])
                : sprintf('%s IN (%s)', $column, implode(', ', $tokens));
            $cases[] = sprintf('WHEN %s THEN %s', $condition, $this->label($group['name']));
        }
        if ($cases === []) {
            return $this->platform instanceof PostgreSQLPlatform ? sprintf('CAST(%s AS text)', $fallback) : $fallback;
        }

        return "CASE\n            ".implode("\n            ", $cases)."\n            ELSE ".$fallback."\n        END";
    }

    private function websiteGroupSelect(string $tokenColumn): string
    {
        if ($this->websiteGroupColumn === null || $this->websiteGroupColumn === '') {
            return '';
        }

        return ",\n    ".$this->websiteGroupExpression($tokenColumn).' AS '.$this->websiteGroupColumn;
    }

    /**
     * @param array<mixed> $websiteGroups
     *
     * @return list<array{name: string, members: list<string>}>
     */
    private static function validateWebsiteGroups(array $websiteGroups): array
    {
        if (count($websiteGroups) > WebsiteScopeCatalog::MAX_GROUPS) {
            throw new \InvalidArgumentException('At most '.WebsiteScopeCatalog::MAX_GROUPS.' website groups can be compiled into a view.');
        }
        $validated = [];
        $seenTokens = [];
        foreach ($websiteGroups as $group) {
            if (!is_array($group) || !is_string($group['name'] ?? null) || !is_array($group['members'] ?? null)) {
                throw new \InvalidArgumentException('Each website group must provide a name and a list of member tokens.');
            }
            $name = trim($group['name']);
            if ($name === '' || strlen($name) > WebsiteScopeCatalog::MAX_NAME || preg_match('/\p{C}/u', $name) === 1) {
                throw new \InvalidArgumentException('A website group name must be 1 to '.WebsiteScopeCatalog::MAX_NAME.' characters without control characters.');
            }
            $members = $group['members'];
            if (($members !== [] && !array_is_list($members)) || count($members) > WebsiteScopeCatalog::MAX_MEMBERS) {
                throw new \InvalidArgumentException('A website group membership list is not valid.');
            }
            $tokens = [];
            foreach ($members as $token) {
                if (!is_string($token) || $token === '' || strlen($token) > 191 || preg_match('/\s/', $token) === 1 || isset($seenTokens[$token])) {
                    throw new \InvalidArgumentException('Each website token in a group must be valid and belong to at most one group.');
                }
                $seenTokens[$token] = true;
                $tokens[] = $token;
            }
            $validated[] = ['name' => $name, 'members' => $tokens];
        }

        return $validated;
    }

    private static function assertValidColumnName(string $column): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $column) !== 1
            || in_array($column, CustomDataSettings::RESERVED_COLUMNS, true)) {
            throw new \InvalidArgumentException('The website group reporting column must be a lowercase SQL identifier (up to 63 characters) and cannot use a built-in column name.');
        }
    }

    private function pageGroupCondition(string $column, string $pattern): string
    {
        [$prefix, $last] = BiPageGroups::split($pattern);
        $literal = fn (string $value): string => $this->platform->quoteStringLiteral($value);
        $like = fn (string $value): string => $literal($value)." ESCAPE '!'";

        if ($last === '**') {
            return sprintf('(%1$s = %2$s OR %1$s LIKE %3$s)', $column, $literal(rtrim($prefix, '/')), $like(self::likeEscape($prefix).'%'));
        }
        if (!str_contains($last, '*')) {
            return sprintf('%s = %s', $column, $literal($pattern));
        }

        $segment = implode('%', array_map(self::likeEscape(...), explode('*', $last)));

        return sprintf(
            '(%1$s LIKE %2$s AND %1$s NOT LIKE %3$s)',
            $column,
            $like(self::likeEscape($prefix).$segment),
            $like(self::likeEscape($prefix).'%/%'),
        );
    }

    private static function likeEscape(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    /**
     * Completed day in the reporting timezone. UTC keeps the original predicate
     * so an unset timezone does not change the view SQL.
     */
    private function reportingDayPredicate(string $column): string
    {
        return $this->shiftsTime()
            ? $this->timeSql()->completedReportingDayPredicate($column)
            : $this->completedDayPredicate($column);
    }

    /** Local date of a stored UTC hour. The plain date cast when the zone is UTC. */
    private function reportingDayExpression(string $column): string
    {
        return $this->shiftsTime()
            ? $this->timeSql()->dayOfUtcHour($column)
            : $this->eventDayExpression($column);
    }

    private function shiftsTime(): bool
    {
        return $this->shifts ??= !ReportingTimezone::isIdentity($this->reportingTimezone);
    }

    private function timeSql(): ReportingTimeSql
    {
        return $this->timeSql ??= new ReportingTimeSql($this->platform, $this->reportingTimezone);
    }

    private function completedHourPredicate(string $column): string
    {
        return match (true) {
            $this->platform instanceof AbstractMySQLPlatform =>
                sprintf("%s < DATE_FORMAT(UTC_TIMESTAMP(), '%%Y-%%m-%%d %%H:00:00')", $column),
            $this->platform instanceof PostgreSQLPlatform =>
                sprintf("%s < date_trunc('hour', CURRENT_TIMESTAMP AT TIME ZONE 'UTC')", $column),
            $this->platform instanceof SQLServerPlatform =>
                sprintf('%s < DATEADD(hour, DATEDIFF(hour, 0, SYSUTCDATETIME()), 0)', $column),
            $this->platform instanceof SQLitePlatform =>
                sprintf("%s < strftime('%%Y-%%m-%%d %%H:00:00', 'now')", $column),
            default => '1 = 0',
        };
    }

    private function completedDayPredicate(string $column): string
    {
        return match (true) {
            $this->platform instanceof AbstractMySQLPlatform =>
                sprintf('%s < UTC_DATE()', $column),
            $this->platform instanceof PostgreSQLPlatform =>
                sprintf("%s < CAST(CURRENT_TIMESTAMP AT TIME ZONE 'UTC' AS date)", $column),
            $this->platform instanceof SQLServerPlatform =>
                sprintf('%s < CAST(SYSUTCDATETIME() AS date)', $column),
            $this->platform instanceof SQLitePlatform =>
                sprintf("%s < date('now')", $column),
            default => '1 = 0',
        };
    }

    private function eventDayExpression(string $column): string
    {
        return match (true) {
            $this->platform instanceof AbstractMySQLPlatform,
            $this->platform instanceof PostgreSQLPlatform,
            $this->platform instanceof SQLServerPlatform => sprintf('CAST(%s AS date)', $column),
            $this->platform instanceof SQLitePlatform => sprintf('date(%s)', $column),
            default => 'NULL',
        };
    }
}
