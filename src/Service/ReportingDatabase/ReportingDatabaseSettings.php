<?php

declare(strict_types=1);

namespace App\Service\ReportingDatabase;

use App\Service\ViewSync\ViewCatalog;
use App\Service\ViewSync\ViewSyncSettings;

/**
 * Reporting database sync settings: only the view sync's (see
 * ViewSyncSettings).
 *
 * The connection itself is infrastructure, like DATABASE_URL: it comes from
 * REPORTING_DATABASE_URL in .env.local or the server environment, because it
 * holds a password (see ReportingDatabaseConnection).
 */
final class ReportingDatabaseSettings extends ViewSyncSettings
{
    public const KEY_ENABLED = 'reporting_database_enabled';
    public const KEY_VIEWS = 'reporting_database_views';
    public const KEY_PRIVATE_VIEWS = 'reporting_database_private_views';
    public const KEY_INTERVAL = 'reporting_database_interval_minutes';

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            self::KEY_ENABLED => false,
            self::KEY_VIEWS => array_keys(ViewCatalog::APPROVED),
            self::KEY_PRIVATE_VIEWS => [],
            self::KEY_INTERVAL => 60,
        ];
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array{reporting_database_enabled: bool, reporting_database_views: list<string>, reporting_database_private_views: list<string>, reporting_database_interval_minutes: int}
     */
    public static function validate(array $values): array
    {
        $values = array_replace(self::defaults(), $values);

        $enabled = self::enabled($values[self::KEY_ENABLED], self::KEY_ENABLED);
        [$views, $privateViews] = self::views($values, $enabled, 'the reporting database', 'copied to the reporting database', 'Choose at least one view to copy, or turn the reporting database sync off.');
        $interval = self::interval($values[self::KEY_INTERVAL]);

        return [
            self::KEY_ENABLED => $enabled,
            self::KEY_VIEWS => $views,
            self::KEY_PRIVATE_VIEWS => $privateViews,
            self::KEY_INTERVAL => $interval,
        ];
    }

    public static function keys(): array
    {
        return ['enabled' => self::KEY_ENABLED, 'views' => self::KEY_VIEWS, 'private_views' => self::KEY_PRIVATE_VIEWS, 'interval' => self::KEY_INTERVAL];
    }

    protected static function emptyEnvironmentKeys(): array
    {
        return [self::KEY_PRIVATE_VIEWS];
    }

    protected static function name(): string
    {
        return 'reporting database';
    }
}
