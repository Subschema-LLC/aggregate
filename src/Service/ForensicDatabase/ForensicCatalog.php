<?php

declare(strict_types=1);

namespace App\Service\ForensicDatabase;

use App\Service\ViewSync\ViewCatalog;

/**
 * What the forensic database copies, for investigating problems in collected
 * data: copies of the raw tables, which no other sync offers, and the private
 * reporting views (ViewCatalog::PRIVATE) as they are.
 *
 * Each table copy names its source table and every column, with its catalog
 * type (ViewCatalog::TYPES), so a copy has the same columns and types on every
 * engine. A column with a hash domain holds a browser-generated identifier;
 * ForensicReader replaces it with a keyed hash under a key made for that one
 * copy and never stored (ForensicPseudonyms), and the column is renamed so it
 * cannot be mistaken for the identifier itself.
 */
final class ForensicCatalog
{
    /**
     * Table copy => source table, description and columns
     * (copy column => [source column, type, hash domain?]).
     *
     * @var array<string, array{source: string, description: string, columns: array<string, array{0: string, 1: string, 2?: string}>}>
     */
    public const TABLES = [
        'forensic_events_v1' => [
            'source' => 'events',
            'description' => 'Every retained event, both privacy modes, every column; visitor and session IDs replaced by hashes made with a new random key at each sync',
            'columns' => [
                'id' => ['id', 'INT64'],
                'website_token' => ['website_token', 'STRING'],
                'event_name' => ['event_name', 'STRING'],
                'url' => ['url', 'STRING'],
                'referrer' => ['referrer', 'STRING'],
                'generalized_user_agent' => ['generalized_user_agent', 'STRING'],
                'privacy_mode' => ['privacy_mode', 'STRING'],
                'device_class' => ['device_class', 'STRING'],
                'viewport_bucket' => ['viewport_bucket', 'STRING'],
                'geo_area' => ['geo_area', 'STRING'],
                'screen_width' => ['screen_width', 'INT64'],
                'visitor_hash' => ['visitor_id', 'STRING', 'visitor'],
                'session_hash' => ['session_id', 'STRING', 'session'],
                'consent_state' => ['consent_state', 'STRING'],
                'custom_data' => ['custom_data', 'STRING'],
                'goal_event' => ['goal_event', 'STRING'],
                'created_at' => ['created_at', 'TIMESTAMP'],
                'archived_at' => ['archived_at', 'TIMESTAMP'],
            ],
        ],
        'forensic_archive_events_v1' => [
            'source' => 'analytics_archive_events',
            'description' => 'Archived hourly event cells, without suppression',
            'columns' => [
                'cell_key' => ['cell_key', 'STRING'],
                'website_token' => ['website_token', 'STRING'],
                'event_hour' => ['event_hour', 'TIMESTAMP'],
                'privacy_mode' => ['privacy_mode', 'STRING'],
                'event_name' => ['event_name', 'STRING'],
                'page_path' => ['page_path', 'STRING'],
                'referrer_channel' => ['referrer_channel', 'STRING'],
                'device_class' => ['device_class', 'STRING'],
                'viewport_bucket' => ['viewport_bucket', 'STRING'],
                'event_count' => ['event_count', 'INT64'],
            ],
        ],
        'forensic_archive_geo_events_v1' => [
            'source' => 'analytics_archive_geo_events',
            'description' => 'Archived daily geography cells, without suppression',
            'columns' => [
                'cell_key' => ['cell_key', 'STRING'],
                'website_token' => ['website_token', 'STRING'],
                'event_day' => ['event_day', 'DATE'],
                'privacy_mode' => ['privacy_mode', 'STRING'],
                'event_name' => ['event_name', 'STRING'],
                'geo_area' => ['geo_area', 'STRING'],
                'event_count' => ['event_count', 'INT64'],
            ],
        ],
        'forensic_archive_goals_v1' => [
            'source' => 'analytics_archive_goals',
            'description' => 'Archived daily goal cells, without suppression',
            'columns' => [
                'cell_key' => ['cell_key', 'STRING'],
                'website_token' => ['website_token', 'STRING'],
                'event_day' => ['event_day', 'DATE'],
                'privacy_mode' => ['privacy_mode', 'STRING'],
                'goal_event' => ['goal_event', 'STRING'],
                'event_count' => ['event_count', 'INT64'],
            ],
        ],
    ];

    public static function isTable(string $name): bool
    {
        return isset(self::TABLES[$name]);
    }

    /** Whether the forensic database copies this table or private view. */
    public static function isKnown(string $name): bool
    {
        return self::isTable($name) || ViewCatalog::isPrivate($name);
    }

    public static function describe(string $name): string
    {
        return self::TABLES[$name]['description'] ?? ViewCatalog::PRIVATE[$name] ?? '';
    }

    /**
     * Every table copy and private view, with its description, as the admin
     * page lists them.
     *
     * @return array<string, string>
     */
    public static function tableDescriptions(): array
    {
        return array_map(static fn (array $table): string => $table['description'], self::TABLES);
    }

    /**
     * Every name a forensic database can hold, which its check looks for.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return [...array_keys(self::TABLES), ...array_keys(ViewCatalog::PRIVATE)];
    }
}
