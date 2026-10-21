<?php

declare(strict_types=1);

namespace App\Service\DataTransfer;

/**
 * The collected tables a transfer may copy. Configuration, views, glossary
 * rows, and task history are rebuilt on the target and are not included.
 */
final class TransferCatalog
{
    public const FORMAT = 1;

    /** @var list<string> */
    public const ANONYMOUS_REFERRERS = ['direct', 'internal', 'search', 'social', 'email', 'referral', 'unknown'];

    /** @var list<string> */
    public const DEVICE_CLASSES = ['mobile', 'tablet', 'desktop', 'bot', 'unknown'];

    /** @var list<string> */
    public const VIEWPORT_BUCKETS = ['small', 'medium', 'large', 'unknown'];

    /**
     * @var array<string, array{dataset: string, primary: string, identity: bool, website: bool, columns: array<string, array<string, mixed>>}>
     */
    private const TABLES = [
        'events' => [
            'dataset' => 'events',
            'primary' => 'id',
            'identity' => true,
            'website' => true,
            'columns' => [
                'id' => ['kind' => 'integer', 'min' => 1],
                'website_token' => ['kind' => 'token'],
                'event_name' => ['kind' => 'event_name'],
                'url' => ['kind' => 'text', 'max' => 1048576],
                'referrer' => ['kind' => 'text', 'nullable' => true, 'max' => 1048576],
                'generalized_user_agent' => ['kind' => 'string', 'nullable' => true, 'max' => 191],
                'privacy_mode' => ['kind' => 'privacy_mode'],
                'device_class' => ['kind' => 'enum', 'values' => self::DEVICE_CLASSES],
                'viewport_bucket' => ['kind' => 'enum', 'values' => self::VIEWPORT_BUCKETS],
                'geo_area' => ['kind' => 'geo_area', 'nullable' => true],
                'screen_width' => ['kind' => 'integer', 'nullable' => true, 'min' => 0],
                'visitor_id' => ['kind' => 'string', 'nullable' => true, 'max' => 191],
                'session_id' => ['kind' => 'string', 'nullable' => true, 'max' => 191],
                'consent_state' => ['kind' => 'consent_state', 'nullable' => true],
                'custom_data' => ['kind' => 'json', 'nullable' => true],
                'goal_event' => ['kind' => 'event_name', 'nullable' => true],
                'created_at' => ['kind' => 'datetime'],
                'archived_at' => ['kind' => 'datetime', 'nullable' => true],
            ],
        ],
        'analytics_archive_events' => [
            'dataset' => 'archives',
            'primary' => 'cell_key',
            'identity' => false,
            'website' => true,
            'columns' => [
                'cell_key' => ['kind' => 'cell_key'],
                'website_token' => ['kind' => 'token'],
                'event_hour' => ['kind' => 'datetime'],
                'privacy_mode' => ['kind' => 'privacy_mode'],
                'event_name' => ['kind' => 'event_name'],
                'page_path' => ['kind' => 'text', 'max' => 1048576],
                'referrer_channel' => ['kind' => 'text', 'max' => 1048576],
                'device_class' => ['kind' => 'enum', 'values' => self::DEVICE_CLASSES],
                'viewport_bucket' => ['kind' => 'enum', 'values' => self::VIEWPORT_BUCKETS],
                'event_count' => ['kind' => 'bigint'],
            ],
        ],
        'analytics_archive_goals' => [
            'dataset' => 'archives',
            'primary' => 'cell_key',
            'identity' => false,
            'website' => true,
            'columns' => [
                'cell_key' => ['kind' => 'cell_key'],
                'website_token' => ['kind' => 'token'],
                'event_day' => ['kind' => 'date'],
                'privacy_mode' => ['kind' => 'privacy_mode'],
                'goal_event' => ['kind' => 'event_name'],
                'event_count' => ['kind' => 'bigint'],
            ],
        ],
        'analytics_archive_geo_events' => [
            'dataset' => 'archives',
            'primary' => 'cell_key',
            'identity' => false,
            'website' => true,
            'columns' => [
                'cell_key' => ['kind' => 'cell_key'],
                'website_token' => ['kind' => 'token'],
                'event_day' => ['kind' => 'date'],
                'privacy_mode' => ['kind' => 'privacy_mode'],
                'event_name' => ['kind' => 'event_name'],
                'geo_area' => ['kind' => 'geo_area'],
                'event_count' => ['kind' => 'bigint'],
            ],
        ],
        'users' => [
            'dataset' => 'users',
            'primary' => 'id',
            'identity' => true,
            'website' => false,
            'columns' => [
                'id' => ['kind' => 'integer', 'min' => 1],
                'username' => ['kind' => 'username'],
                'roles' => ['kind' => 'roles'],
                'password' => ['kind' => 'secret'],
                'created_at' => ['kind' => 'datetime'],
            ],
        ],
    ];

    /** @return list<string> */
    public static function tables(): array
    {
        return array_keys(self::TABLES);
    }

    /** @return list<string> */
    public static function datasetTables(string $dataset): array
    {
        return match ($dataset) {
            'events' => ['events'],
            'archives' => ['analytics_archive_events', 'analytics_archive_goals', 'analytics_archive_geo_events'],
            'users' => ['users'],
            default => throw new DataTransferException('Choose events, archives, or users.'),
        };
    }

    /** @return array<string, array<string, mixed>> */
    public static function columns(string $table): array
    {
        return self::definition($table)['columns'];
    }

    public static function primary(string $table): string
    {
        return self::definition($table)['primary'];
    }

    public static function isIdentity(string $table): bool
    {
        return self::definition($table)['identity'];
    }

    public static function hasWebsite(string $table): bool
    {
        return self::definition($table)['website'];
    }

    /** @return list<string> */
    public static function dimensions(string $table): array
    {
        return match ($table) {
            'analytics_archive_events' => ['website_token', 'event_hour', 'privacy_mode', 'event_name', 'page_path', 'referrer_channel', 'device_class', 'viewport_bucket'],
            'analytics_archive_goals' => ['website_token', 'event_day', 'privacy_mode', 'goal_event'],
            'analytics_archive_geo_events' => ['website_token', 'event_day', 'privacy_mode', 'event_name', 'geo_area'],
            default => [],
        };
    }

    /** @return list<string> */
    public static function packageFiles(): array
    {
        $files = ['manifest.json'];
        foreach (self::tables() as $table) {
            $files[] = $table.'.jsonl';
        }

        return $files;
    }

    /**
     * @return array{dataset: string, primary: string, identity: bool, website: bool, columns: array<string, array<string, mixed>>}
     */
    private static function definition(string $table): array
    {
        if (!isset(self::TABLES[$table])) {
            throw new DataTransferException('Choose events, archives, or users.');
        }

        return self::TABLES[$table];
    }
}
