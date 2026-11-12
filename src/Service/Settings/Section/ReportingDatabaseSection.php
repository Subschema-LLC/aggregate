<?php

declare(strict_types=1);

namespace App\Service\Settings\Section;

use App\Service\AggregateConfigLoader;
use App\Service\ReportingDatabase\ReportingDatabaseSettings;
use App\Service\Settings\AbstractViewSyncSection;
use App\Service\ViewSync\ViewSyncSettings;

/**
 * Reporting database sync settings, as its page saves them. The connection
 * itself is REPORTING_DATABASE_URL in .env.local, set by
 * app:reporting-database:configure.
 */
final class ReportingDatabaseSection extends AbstractViewSyncSection
{
    public function __construct(AggregateConfigLoader $config, private readonly ReportingDatabaseSettings $settings)
    {
        parent::__construct($config);
    }

    public function name(): string
    {
        return 'reporting-database';
    }

    public function page(): string
    {
        return 'Reporting > Sync > Reporting database';
    }

    protected function settings(): ViewSyncSettings
    {
        return $this->settings;
    }
}
