<?php

declare(strict_types=1);

namespace App\Service\ViewSync;

use App\Service\BigQuery\BigQuerySettings;
use App\Service\ForensicDatabase\ForensicDatabaseSettings;
use App\Service\ReportingDatabase\ReportingDatabaseSettings;

/**
 * The destinations a view sync can copy reporting views to, in the order the
 * Sync page shows them as tabs, and whether each is turned on. The dashboard's
 * Sync menu entry appears once one is on (navigation condition view_sync_on).
 */
final class ViewSyncDestinations
{
    public function __construct(
        private readonly BigQuerySettings $bigQuery,
        private readonly ReportingDatabaseSettings $reportingDatabase,
        private readonly ForensicDatabaseSettings $forensicDatabase,
    ) {
    }

    /**
     * Each destination's admin page and whether it is on; null when its
     * settings are invalid, so the page can still be opened to repair them.
     *
     * @return array<string, array{label: string, route: string, on: ?bool}>
     */
    public function all(): array
    {
        return [
            'bigquery' => ['label' => 'BigQuery', 'route' => 'app_bigquery', 'on' => $this->isOn($this->bigQuery)],
            'reporting_database' => ['label' => 'Reporting database', 'route' => 'app_reporting_database', 'on' => $this->isOn($this->reportingDatabase)],
            // Administrators only; the tab is hidden from standard users.
            'forensic_database' => ['label' => 'Forensic database', 'route' => 'app_forensic_database', 'on' => $this->isOn($this->forensicDatabase)],
        ];
    }

    /**
     * Whether a destination is on, or its settings are invalid and need its
     * page; both keep the Sync menu entry visible.
     */
    public function anyNeedsThePage(): bool
    {
        foreach ($this->all() as $destination) {
            if ($destination['on'] !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * The page the Sync menu entry opens: the first destination that is on, or
     * the first one, among those the user may open.
     *
     * @param (callable(string): bool)|null $allowed whether the user may open a route; every route when null
     */
    public function landingRoute(?callable $allowed = null): string
    {
        $destinations = array_values(array_filter($this->all(), static fn (array $destination): bool => $allowed === null || $allowed($destination['route'])));
        $destinations = $destinations === [] ? array_values($this->all()) : $destinations;
        foreach ($destinations as $destination) {
            if ($destination['on'] === true) {
                return $destination['route'];
            }
        }

        return $destinations[0]['route'];
    }

    private function isOn(ViewSyncSettings $settings): ?bool
    {
        try {
            return (bool) $settings->toArray()[$settings::keys()['enabled']];
        } catch (\Throwable) {
            return null;
        }
    }
}
