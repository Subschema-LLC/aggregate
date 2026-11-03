<?php

declare(strict_types=1);

namespace App\Service\ForensicDatabase;

use App\Service\ViewSync\ViewCatalog;
use App\Service\ViewSync\ViewSyncDefinition;
use App\Service\ViewSync\ViewSyncSettings;

/**
 * Forensic database sync settings (see ViewSyncSettings): the table copies
 * (ForensicCatalog::TABLES) and private reporting views to copy, the
 * interval, and the confirmation that access to an engine which cannot be
 * marked is restricted.
 *
 * The sync's "views" are its table copies and its "private views" are the
 * private reporting views, so the shared runner, status and scheduler code
 * treats both as the views of one sync. Approved views are not offered: they
 * belong in the reporting database.
 *
 * The connection itself is FORENSIC_DATABASE_URL in .env.local or the server
 * environment, because it holds a password (see ForensicDatabaseConnection).
 */
final class ForensicDatabaseSettings extends ViewSyncSettings
{
    public const KEY_ENABLED = 'forensic_database_enabled';
    public const KEY_TABLES = 'forensic_database_tables';
    public const KEY_VIEWS = 'forensic_database_views';
    public const KEY_INTERVAL = 'forensic_database_interval_minutes';
    public const KEY_ACCESS_RESTRICTED = 'forensic_database_access_restricted';

    /** Each sync re-reads every table and rehashes every identifier, so once a day by default. */
    public const DEFAULT_INTERVAL = 1440;

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            self::KEY_ENABLED => false,
            self::KEY_TABLES => array_keys(ForensicCatalog::TABLES),
            self::KEY_VIEWS => array_keys(ViewCatalog::PRIVATE),
            self::KEY_INTERVAL => self::DEFAULT_INTERVAL,
            self::KEY_ACCESS_RESTRICTED => false,
        ];
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array{forensic_database_enabled: bool, forensic_database_tables: list<string>, forensic_database_views: list<string>, forensic_database_interval_minutes: int, forensic_database_access_restricted: bool}
     */
    public static function validate(array $values): array
    {
        $values = array_replace(self::defaults(), $values);

        $enabled = self::enabled($values[self::KEY_ENABLED], self::KEY_ENABLED);
        $tables = self::viewList($values[self::KEY_TABLES], self::KEY_TABLES);
        foreach ($tables as $table) {
            if (!ForensicCatalog::isTable($table)) {
                throw new \InvalidArgumentException($table.' is not a table the forensic database copies. Choose from '.implode(', ', array_keys(ForensicCatalog::TABLES)).(ViewCatalog::isPrivate($table) ? ', and list private views under '.self::KEY_VIEWS : '').'.');
            }
        }
        $views = self::viewList($values[self::KEY_VIEWS], self::KEY_VIEWS);
        foreach ($views as $view) {
            if (!ViewCatalog::isPrivate($view)) {
                throw new \InvalidArgumentException($view.' is not a private reporting view. '.(ViewCatalog::isApproved($view) ? 'Approved views belong in the reporting database.' : 'Choose from '.implode(', ', array_keys(ViewCatalog::PRIVATE)).'.'));
            }
        }
        if ($enabled && $tables === [] && $views === []) {
            throw new \InvalidArgumentException(self::definition()->noneChosen);
        }

        return [
            self::KEY_ENABLED => $enabled,
            self::KEY_TABLES => self::inCatalogOrder($tables, ForensicCatalog::TABLES),
            self::KEY_VIEWS => self::inCatalogOrder($views, ViewCatalog::PRIVATE),
            self::KEY_INTERVAL => self::interval($values[self::KEY_INTERVAL]),
            self::KEY_ACCESS_RESTRICTED => self::enabled($values[self::KEY_ACCESS_RESTRICTED], self::KEY_ACCESS_RESTRICTED),
        ];
    }

    /**
     * Turns the sync on, keeping the tables and views selected (every one of
     * them unless changed) and the interval unless one is given. Settings the
     * environment controls keep their values (see save()), so the result says
     * what is in effect.
     *
     * @return array{forensic_database_enabled: bool, forensic_database_tables: list<string>, forensic_database_views: list<string>, forensic_database_interval_minutes: int, forensic_database_access_restricted: bool}
     *
     * @throws \InvalidArgumentException when nothing is selected to copy, or the interval is invalid
     * @throws \RuntimeException         when the configuration cannot be written
     */
    public function enable(?int $interval = null): array
    {
        $candidate = [self::KEY_ENABLED => true];
        if ($interval !== null) {
            $candidate[self::KEY_INTERVAL] = $interval;
        }
        $this->save($candidate);

        return $this->toArray();
    }

    /** Whether the administrator confirmed that access to an unmarkable forensic database is restricted. */
    public function accessRestricted(): bool
    {
        return $this->toArray()[self::KEY_ACCESS_RESTRICTED];
    }

    public static function keys(): array
    {
        return ['enabled' => self::KEY_ENABLED, 'views' => self::KEY_TABLES, 'private_views' => self::KEY_VIEWS, 'interval' => self::KEY_INTERVAL];
    }

    protected static function emptyEnvironmentKeys(): array
    {
        return [self::KEY_TABLES, self::KEY_VIEWS];
    }

    public static function definition(): ViewSyncDefinition
    {
        return new ViewSyncDefinition(
            taskType: ForensicDatabaseSyncRunner::TASK_TYPE,
            label: 'Forensic database sync',
            subject: 'The forensic database sync',
            settingsName: 'forensic database',
            destination: 'the forensic database',
            selectedFor: 'the forensic database',
            copiedTo: 'copied to the forensic database',
            noneChosen: 'Choose at least one table or view to copy, or turn the forensic database sync off.',
            viewsSaved: 'The tables and views to copy were saved. Copies you removed stay in the forensic database until you drop them there.',
            command: ForensicDatabaseBackgroundSync::COMMAND,
            logFile: 'var/forensic-database/last-run.log',
            exportDirectory: 'var/forensic-database',
        );
    }
}
