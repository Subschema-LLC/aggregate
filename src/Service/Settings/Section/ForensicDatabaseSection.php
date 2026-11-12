<?php

declare(strict_types=1);

namespace App\Service\Settings\Section;

use App\Service\AggregateConfigLoader;
use App\Service\ForensicDatabase\ForensicDatabaseSettings;
use App\Service\Settings\AbstractViewSyncSection;
use App\Service\ViewSync\ViewSyncSettings;

/**
 * Forensic database sync settings, as its page saves them. Everything it
 * copies is raw or unsuppressed, so adding a table or view, and confirming
 * that access is restricted, need --confirm, as the page's checkboxes do.
 * The connection is FORENSIC_DATABASE_URL, set by
 * app:forensic-database:configure.
 */
final class ForensicDatabaseSection extends AbstractViewSyncSection
{
    public function __construct(AggregateConfigLoader $config, private readonly ForensicDatabaseSettings $settings)
    {
        parent::__construct($config);
    }

    public function name(): string
    {
        return 'forensic-database';
    }

    public function page(): string
    {
        return 'Reporting > Sync > Forensic database';
    }

    protected function settings(): ViewSyncSettings
    {
        return $this->settings;
    }

    protected function assertConfirmed(array $changes, array $current, bool $confirmed): void
    {
        if ($confirmed) {
            return;
        }
        $restricted = $changes[ForensicDatabaseSettings::KEY_ACCESS_RESTRICTED] ?? null;
        if (in_array($restricted, [true, 1, '1', 'true'], true) && $current[ForensicDatabaseSettings::KEY_ACCESS_RESTRICTED] !== true) {
            throw new \InvalidArgumentException('Confirm with --confirm that only the people who investigate problems can read the forensic database.');
        }
        $added = [];
        foreach ([ForensicDatabaseSettings::KEY_TABLES, ForensicDatabaseSettings::KEY_VIEWS] as $key) {
            if (is_array($changes[$key] ?? null)) {
                array_push($added, ...array_diff(array_map('strval', $changes[$key]), $current[$key]));
            }
        }
        if ($added !== []) {
            throw new \InvalidArgumentException('Confirm with --confirm that what you added may be copied to the forensic database. It holds raw events and unsuppressed counts.');
        }
    }
}
