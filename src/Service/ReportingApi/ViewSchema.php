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
 * The columns of a reporting view as the API and the MCP server describe and
 * validate them: the names the view actually has (custom columns included),
 * the catalog type of each (ViewCatalog, the same types BigQuery and the
 * reporting database receive), its API type, the filter operators it accepts,
 * its label and description, and, for time columns, the time zone its values
 * are labelled in. Columns holding a glossary dimension (device_class,
 * referrer_channel and so on) also list their codes with labels.
 *
 * columns() is what validating and running a query needs, with the built-in
 * descriptions; it reads no glossary. described() adds labels, descriptions
 * and code labels from the published glossary, bi_glossary_columns_v1 and
 * bi_glossary_values_v1 in the default locale, so a schema says what BI tools
 * read, including an administrator's edits and custom columns. Until the
 * glossary has been published, or when its views cannot be read, the built-in
 * text is used instead.
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
    /** Codes listed for one column; a longer list is cut and says so. */
    public const MAX_VALUES = 1000;

    /** @var array<string, list<array{name: string, catalog_type: string, type: string, operators: list<string>, description: ?string, timezone: ?string}>> */
    private array $columns = [];
    /** @var array{columns: array<string, array<string, array{label: ?string, description: ?string}>>, values: array<string, list<array{code: string, label: string, description: ?string}>>}|null */
    private ?array $published = null;

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
     * The columns with their labels and descriptions from the published
     * glossary, and the codes of columns that hold a glossary dimension.
     *
     * @return list<array{name: string, catalog_type: string, type: string, operators: list<string>, label: ?string, description: ?string, timezone: ?string, values: ?list<array{code: string, label: string, description: ?string}>, values_truncated: bool}>
     *
     * @throws ApiProblem when the view does not exist in this database
     */
    public function described(string $view): array
    {
        $published = $this->published();
        $described = [];
        foreach ($this->columns($view) as $column) {
            $name = $column['name'];
            $text = $published['columns'][$view][$name] ?? null;
            $values = null;
            if (!ViewCatalog::isMetadata($view) && in_array($name, BuiltinGlossaryCatalog::DIMENSIONS, true)) {
                $values = $published['values'][$name] ?? $this->builtinValues($name);
            }
            $described[] = [
                ...$column,
                'label' => $text['label'] ?? $this->glossary->text('column', $view, $name, 'label', self::LOCALE),
                'description' => $text['description'] ?? $column['description'],
                'values' => $values === null ? null : array_slice($values, 0, self::MAX_VALUES),
                'values_truncated' => $values !== null && count($values) > self::MAX_VALUES,
            ];
        }

        return $described;
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

    /**
     * The published glossary in its default locale, read once per request.
     * Empty when it has not been published or its views cannot be read; the
     * caller then uses the built-in text.
     *
     * @return array{columns: array<string, array<string, array{label: ?string, description: ?string}>>, values: array<string, list<array{code: string, label: string, description: ?string}>>}
     */
    private function published(): array
    {
        if ($this->published !== null) {
            return $this->published;
        }
        $published = ['columns' => [], 'values' => []];
        try {
            foreach ($this->defaultLocaleRows('bi_glossary_columns_v1') as $row) {
                $object = (string) ($row['object_name'] ?? '');
                $column = (string) ($row['column_name'] ?? '');
                if ($object !== '' && $column !== '') {
                    $published['columns'][$object][$column] = ['label' => self::text($row['label'] ?? null), 'description' => self::text($row['description'] ?? null)];
                }
            }
            $values = [];
            foreach ($this->defaultLocaleRows('bi_glossary_values_v1') as $row) {
                $dimension = (string) ($row['dimension'] ?? '');
                $code = (string) ($row['code'] ?? '');
                if ($dimension !== '' && $code !== '') {
                    $values[$dimension][] = ['sort' => (int) ($row['sort_order'] ?? 0), 'code' => $code, 'label' => self::text($row['label'] ?? null) ?? $code, 'description' => self::text($row['description'] ?? null)];
                }
            }
            foreach ($values as $dimension => $entries) {
                usort($entries, static fn (array $a, array $b): int => [$a['sort'], $a['code']] <=> [$b['sort'], $b['code']]);
                $published['values'][$dimension] = array_map(static fn (array $entry): array => ['code' => $entry['code'], 'label' => $entry['label'], 'description' => $entry['description']], $entries);
            }
        } catch (ViewSyncException|\Doctrine\DBAL\Exception) {
            $published = ['columns' => [], 'values' => []];
        }

        return $this->published = $published;
    }

    /**
     * Rows of a glossary view in its default locale, by column name. Filtered
     * here rather than in SQL: the flag's storage type differs by engine.
     *
     * @return iterable<array<string, mixed>>
     */
    private function defaultLocaleRows(string $view): iterable
    {
        $read = $this->reader->read($view);
        $names = array_column($read['fields'], 'name');
        foreach ($read['rows'] as $values) {
            $row = array_combine($names, $values);
            if (in_array($row['is_default_locale'] ?? null, [true, 1, '1', 't', 'true'], true)) {
                yield $row;
            }
        }
    }

    /** @return list<array{code: string, label: string, description: ?string}>|null the built-in codes, or null when they come only from configuration */
    private function builtinValues(string $dimension): ?array
    {
        $codes = $this->glossary->dimensions()[$dimension] ?? [];
        if ($codes === []) {
            return null;
        }

        return array_map(fn (string $code): array => [
            'code' => $code,
            'label' => $this->glossary->text('value', $dimension, $code, 'label', self::LOCALE) ?? $code,
            'description' => $this->glossary->text('value', $dimension, $code, 'description', self::LOCALE),
        ], array_map('strval', $codes));
    }

    private static function text(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private function timezone(string $view, string $column): string
    {
        return in_array($column, self::REPORTING_TIME_COLUMNS[$view] ?? [], true) ? $this->reportingTimezone() : 'UTC';
    }
}
