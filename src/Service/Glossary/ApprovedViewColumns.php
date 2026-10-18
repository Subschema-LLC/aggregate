<?php

declare(strict_types=1);

namespace App\Service\Glossary;

use App\Service\AnonymousBiViewManager;

/**
 * The columns of the approved bi_anonymous_* views, as the Data Structure page
 * lists them: the column list the glossary covers, each column's definition
 * from the BI glossary (the text BI tools read in bi_glossary_columns_v1), a
 * logical type, and the codes of short built-in dimensions.
 */
final class ApprovedViewColumns
{
    /** Logical types by column; the physical SQL type varies by database engine. */
    private const TYPES = [
        'website_token' => 'String',
        'event_hour' => 'Datetime',
        'event_day' => 'Date',
        'event_name' => 'String',
        'goal_event' => 'String',
        'page_path' => 'Text',
        'page_group' => 'Text',
        'referrer_channel' => 'Category',
        'device_class' => 'Category',
        'viewport_bucket' => 'Category',
        'geo_area' => 'Category',
        'event_count' => 'Integer',
        'detail_level' => 'Category',
        'view_name' => 'String',
        'coverage_band' => 'Category',
    ];
    /** Goal and geography views stay on UTC days; the others follow reporting_timezone. */
    private const UTC_DAY_VIEWS = ['bi_anonymous_goals_v1', 'bi_anonymous_geo_events_v1'];
    /** Dimensions with few enough codes to list beside the definition. */
    private const LISTED_CODES = ['referrer_channel', 'device_class', 'viewport_bucket'];
    private const LOCALE = 'en';

    public function __construct(private readonly BuiltinGlossaryCatalog $glossary)
    {
    }

    /**
     * Every approved view's columns, in view order.
     *
     * @return array<string, list<array{name: string, type: string, description: string, codes: list<string>}>>
     */
    public function all(): array
    {
        $views = [];
        foreach (AnonymousBiViewManager::VIEW_NAMES as $view) {
            $views[$view] = $this->columns($view);
        }

        return $views;
    }

    /**
     * @return list<array{name: string, type: string, description: string, codes: list<string>}>
     */
    public function columns(string $view): array
    {
        if (!in_array($view, AnonymousBiViewManager::VIEW_NAMES, true)) {
            throw new \InvalidArgumentException(sprintf('%s is not an approved view.', $view));
        }
        $dimensions = $this->glossary->dimensions();
        $columns = [];
        foreach (BuiltinGlossaryCatalog::coveredColumns()[$view] as $name) {
            $type = self::TYPES[$name] ?? throw new \LogicException(sprintf('Column %s.%s has no logical type.', $view, $name));
            if ($name === 'event_day' && in_array($view, self::UTC_DAY_VIEWS, true)) {
                $type = 'UTC date';
            }
            $columns[] = [
                'name' => $name,
                'type' => $type,
                'description' => $this->glossary->text('column', $view, $name, 'description', self::LOCALE)
                    ?? throw new \LogicException(sprintf('Column %s.%s has no glossary definition.', $view, $name)),
                'codes' => in_array($name, self::LISTED_CODES, true) ? array_values($dimensions[$name]) : [],
            ];
        }

        return $columns;
    }
}
