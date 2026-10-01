<?php

declare(strict_types=1);

namespace App\Service\Glossary;

use App\Service\GeoIp\GeoArea;
use App\Service\PrivacySanitizer;
use App\Service\ReportingViewManager;
use Symfony\Component\Intl\Countries;
use Symfony\Component\Intl\Locales;
use Symfony\Component\Translation\TranslatorBagInterface;

/** Declared metadata only: this catalog has no database dependency. */
class BuiltinGlossaryCatalog
{
    /** Dimensions with a bi_dim_*_v1 view, in contract order. */
    public const DIMENSIONS = ['event_name', 'goal_event', 'referrer_channel', 'device_class', 'viewport_bucket', 'geo_area', 'website_token'];

    public function __construct(private readonly TranslatorBagInterface $translator)
    {
    }

    public function dimensions(): array
    {
        return [
            'event_name' => ['view'],
            // Codes come from configuration: goals.yaml and the registered websites.
            'goal_event' => [],
            'website_token' => [],
            'referrer_channel' => PrivacySanitizer::REFERRER_CHANNELS,
            'device_class' => PrivacySanitizer::DEVICE_CLASSES,
            'viewport_bucket' => PrivacySanitizer::VIEWPORT_BUCKETS,
            'geo_area' => [
                ...array_map(static fn (string $code): string => 'continent:'.$code, GeoArea::CONTINENT_CODES),
                ...array_map(static fn (string $code): string => 'country:'.$code, Countries::getCountryCodes()),
            ],
            'geo_level' => ['country', 'continent'],
            'privacy_mode' => ['anonymous', 'enhanced'],
        ];
    }

    /** Static contracts. Custom columns are added from the saved model by settings. */
    public static function coveredColumns(): array
    {
        $views = [
            'bi_anonymous_events_v1' => ['website_token', 'event_hour', 'event_name', 'page_path', 'referrer_channel', 'device_class', 'viewport_bucket', 'event_count'],
            'bi_anonymous_goals_v1' => ['website_token', 'event_day', 'goal_event', 'event_count'],
            'bi_anonymous_geo_events_v1' => ['website_token', 'event_day', 'event_name', 'geo_area', 'event_count'],
        ];
        foreach (self::DIMENSIONS as $dimension) {
            $views['bi_dim_'.$dimension.'_v1'] = [$dimension, $dimension.'_label', $dimension.'_group', $dimension.'_description', $dimension.'_sort'];
        }
        $views['bi_dim_geo_area_v1'][] = 'geo_level';
        $views['bi_glossary_values_v1'] = ['dimension', 'code', 'locale', 'label', 'label_locale', 'is_fallback', 'group_label', 'description', 'sort_order', 'is_default_locale'];
        $views['bi_glossary_columns_v1'] = ['object_name', 'column_name', 'locale', 'label', 'label_locale', 'is_fallback', 'description', 'is_default_locale'];
        foreach (ReportingViewManager::VIEW_NAMES as $view) {
            $views[$view] = ReportingViewManager::BUILTIN_COLUMNS;
        }

        return $views;
    }

    public function text(string $type, string $subject, string $code, string $field, string $locale): ?string
    {
        $key = $type.'.'.$subject.'.'.$code.'.'.$field;
        $catalogue = $this->translator->getCatalogue($locale);
        // defines(), unlike trans()/has(), never uses the translator's fallback.
        if ($catalogue->defines($key, 'bi_glossary')) {
            return $catalogue->get($key, 'bi_glossary');
        }
        if ($type === 'value' && $subject === 'geo_area' && str_starts_with($code, 'country:')) {
            $intlLocale = str_replace('-', '_', $locale);
            if ($field === 'label' && Locales::exists($intlLocale)) {
                return Countries::getName(substr($code, 8), $intlLocale);
            }
            // Countries are complete Intl data, not a duplicated country list.
            $key = 'value.geo_area.country.description';
            if ($field === 'description' && $catalogue->defines($key, 'bi_glossary')) {
                return $catalogue->get($key, 'bi_glossary');
            }
        }

        return null;
    }
}
