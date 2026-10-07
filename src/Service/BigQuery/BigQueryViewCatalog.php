<?php

declare(strict_types=1);

namespace App\Service\BigQuery;

use App\Service\CustomDataSettings;
use App\Service\ReportingViewManager;

/**
 * The reporting views BigQuery sync can copy, and the BigQuery column types.
 *
 * Approved views are the routine BI contract: suppressed counts and labels.
 * Private views hold row-level or unsuppressed data; each one must be listed
 * separately under bigquery_private_views before it can leave the server.
 * Raw tables are never offered.
 *
 * Column types follow the documented view contracts rather than database
 * metadata, so a dataset has the same schema whichever engine Aggregate uses
 * (SQLite reports no type for computed view columns).
 */
final class BigQueryViewCatalog
{
    public const APPROVED = [
        'bi_anonymous_events_v1' => 'Hourly anonymous event counts, small cells withheld',
        'bi_anonymous_goals_v1' => 'Daily anonymous goal counts, small cells withheld',
        'bi_anonymous_geo_events_v1' => 'Daily anonymous geography counts, small areas pooled',
        'bi_dim_website_token_v1' => 'Website labels',
        'bi_dim_event_name_v1' => 'Event name labels',
        'bi_dim_goal_event_v1' => 'Goal labels',
        'bi_dim_referrer_channel_v1' => 'Referrer channel labels',
        'bi_dim_device_class_v1' => 'Device class labels',
        'bi_dim_viewport_bucket_v1' => 'Viewport bucket labels',
        'bi_dim_geo_area_v1' => 'Geography labels',
        'bi_glossary_values_v1' => 'Translated labels for every code',
        'bi_glossary_columns_v1' => 'Column descriptions',
    ];

    public const PRIVATE = [
        'analytics_custom_events_v1' => 'Every retained raw event, with modeled properties (row-level, both privacy modes)',
        'analytics_custom_pageviews_v1' => 'Retained raw page views, with modeled properties (row-level)',
        'analytics_custom_goals_v1' => 'Retained raw goal events, with modeled properties (row-level)',
        'analytics_archived_events_v1' => 'Archived hourly event counts, without suppression',
        'analytics_archived_pageviews_v1' => 'Archived hourly page view counts, without suppression',
        'analytics_archived_goals_v1' => 'Archived daily goal counts, without suppression',
    ];

    public const TYPES = ['STRING', 'INT64', 'FLOAT64', 'BOOL', 'DATE', 'TIMESTAMP'];

    public function __construct(private readonly CustomDataSettings $customData)
    {
    }

    public static function isApproved(string $view): bool
    {
        return isset(self::APPROVED[$view]);
    }

    public static function isPrivate(string $view): bool
    {
        return isset(self::PRIVATE[$view]);
    }

    public static function isKnown(string $view): bool
    {
        return self::isApproved($view) || self::isPrivate($view);
    }

    public static function describe(string $view): string
    {
        return self::APPROVED[$view] ?? self::PRIVATE[$view] ?? '';
    }

    /** The BigQuery type of one column of a catalog view. */
    public function columnType(string $view, string $column): string
    {
        if (in_array($view, ReportingViewManager::VIEW_NAMES, true)) {
            return match (true) {
                $column === 'id' => 'INT64',
                in_array($column, ['created_at', 'archived_at'], true) => 'TIMESTAMP',
                in_array($column, ReportingViewManager::BUILTIN_COLUMNS, true) => 'STRING',
                default => $this->customColumnType($column),
            };
        }

        return match (true) {
            $column === 'event_count' => 'INT64',
            $column === 'event_hour' => 'TIMESTAMP',
            $column === 'event_day' => 'DATE',
            $column === 'sort_order', str_ends_with($column, '_sort') => 'INT64',
            in_array($column, ['is_fallback', 'is_default_locale'], true) => 'BOOL',
            default => 'STRING',
        };
    }

    private function customColumnType(string $column): string
    {
        $numeric = $this->customData->numericReportingColumns()[$column] ?? null;
        if ($numeric === null) {
            return 'STRING';
        }

        return $numeric['type'] === 'integer' ? 'INT64' : 'FLOAT64';
    }
}
