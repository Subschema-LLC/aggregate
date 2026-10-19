<?php

declare(strict_types=1);

namespace App\Service\ReportingApi;

use App\Service\Glossary\BuiltinGlossaryCatalog;
use App\Service\ReportingTimezone;
use App\Service\ReportingViewManager;
use App\Service\ViewSync\ViewCatalog;
use App\Service\ViewSync\ViewReader;
use App\Service\ViewSync\ViewSyncException;

/**
 * The columns of a reporting view as the API describes and validates them:
 * the names the view actually has (custom columns included), the catalog
 * type of each (ViewCatalog, the same types BigQuery and the reporting
 * database receive), its API type, the filter operators it accepts, its
 * built-in glossary description, and, for time columns, the time zone its
 * values are labelled in.
 */
class ViewSchema
{
    /** Catalog types (ViewCatalog::TYPES) as the API names them. */
    public const API_TYPES = [
        'STRING' => 'string',
        'INT64' => 'integer',
        'FLOAT64' => 'number',
        'BOOL' => 'boolean',
        'DATE' => 'date',
        'TIMESTAMP' => 'timestamp',
    ];

    /**
     * Filter operators by catalog type. Ranges are not offered on text,
     * because comparing text follows each database's collation.
     */
    public const OPERATORS = [
        'STRING' => ['eq', 'ne', 'in', 'starts_with', 'null'],
        'INT64' => ['eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'in', 'null'],
        'FLOAT64' => ['eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'null'],
        'BOOL' => ['eq', 'ne', 'null'],
        'DATE' => ['eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'in', 'null'],
        'TIMESTAMP' => ['eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'in', 'null'],
    ];

    /**
     * Time columns the views label in the installation reporting timezone;
     * every other time column is UTC (goal and geography days, archives and
     * the custom views' stored times).
     */
    private const REPORTING_TIME_COLUMNS = [
        'bi_anonymous_events_v1' => ['event_hour'],
        'bi_anonymous_daily_v1' => ['event_day'],
        'bi_anonymous_daily_rollup_v1' => ['event_day'],
        'bi_anonymous_coverage_v1' => ['event_day'],
    ];

    private const LOCALE = 'en';

    /** @var array<string, list<array{name: string, catalog_type: string, type: string, operators: list<string>, description: ?string, timezone: ?string}>> */
    private array $columns = [];

    public function __construct(
        private readonly ViewReader $reader,
        private readonly BuiltinGlossaryCatalog $glossary,
        private readonly ReportingTimezone $reportingTimezone,
    ) {
    }

    /**
     * @return list<array{name: string, catalog_type: string, type: string, operators: list<string>, description: ?string, timezone: ?string}>
     *
     * @throws ApiProblem when the view does not exist in this database
     */
    public function columns(string $view): array
    {
        if (isset($this->columns[$view])) {
            return $this->columns[$view];
        }
        try {
            $fields = $this->reader->columns($view);
        } catch (ViewSyncException $error) {
            throw new ApiProblem(503, 'view_unavailable', sprintf('The view %s is not available on this installation.', $view), previous: $error);
        }
        $columns = [];
        foreach ($fields as $field) {
            $type = $field['type'];
            $columns[] = [
                'name' => $field['name'],
                'catalog_type' => $type,
                'type' => self::API_TYPES[$type],
                'operators' => self::OPERATORS[$type],
                'description' => $this->glossary->text('column', $view, $field['name'], 'description', self::LOCALE),
                'timezone' => in_array($type, ['DATE', 'TIMESTAMP'], true) ? $this->timezone($view, $field['name']) : null,
            ];
        }

        return $this->columns[$view] = $columns;
    }

    /**
     * Columns that, in this order, make every row unique, so offset paging
     * is stable: the custom views' id, otherwise every column (each released
     * cell is unique on its dimensions).
     *
     * @param list<string> $names the view's columns, in view order
     *
     * @return list<string>
     */
    public static function uniqueOrder(string $view, array $names): array
    {
        if (in_array($view, ReportingViewManager::VIEW_NAMES, true) && in_array('id', $names, true)) {
            return ['id'];
        }

        return $names;
    }

    public function reportingTimezone(): string
    {
        return $this->reportingTimezone->name();
    }

    public static function describe(string $view): string
    {
        return ViewCatalog::describe($view);
    }

    private function timezone(string $view, string $column): string
    {
        return in_array($column, self::REPORTING_TIME_COLUMNS[$view] ?? [], true) ? $this->reportingTimezone() : 'UTC';
    }
}
