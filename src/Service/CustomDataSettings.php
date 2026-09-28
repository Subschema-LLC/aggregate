<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Yaml\Yaml;

/** The deployment's shareable contract for custom properties and URL attribution. */
class CustomDataSettings
{
    public const PROPERTIES_KEY = 'custom_data_properties';
    public const MAPPINGS_KEY = 'query_parameter_mappings';
    public const PAGE_SEQUENCE_ENABLED_KEY = 'page_sequence_enabled';
    public const PAGE_SEQUENCE_METHOD_KEY = 'page_sequence_method';
    public const PAGE_SEQUENCE_METHODS = ['session_storage', 'url_parameter'];
    public const PAGE_SEQUENCE_QUERY_PARAMETER = 'aggregate_page_sequence';
    public const PAGE_SEQUENCE_PROPERTY = 'page_sequence';
    public const PAGE_SEQUENCE_MAXIMUM = 20;
    public const UTM_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id'];
    public const TYPES = ['scalar', 'string', 'integer', 'float', 'double', 'boolean'];
    public const MAXIMUM_SAFE_INTEGER = 9_007_199_254_740_991;
    private const RESERVED_COLUMNS = [
        'id', 'website_token', 'event_name', 'page_path', 'referrer_channel', 'privacy_mode',
        'device_class', 'viewport_bucket', 'geo_area', 'goal_event', 'created_at', 'archived_at',
        'url', 'referrer', 'screen_width', 'visitor_id', 'session_id', 'consent_state',
        'custom_data', 'generalized_user_agent', 'event_hour', 'event_day', 'event_count',
    ];

    public function __construct(private readonly AggregateConfigLoader $config)
    {
    }

    public static function defaults(): array
    {
        $properties = [];
        foreach (self::UTM_KEYS as $key) {
            $properties[$key] = [
                'description' => match ($key) {
                    'utm_source' => 'Campaign source.',
                    'utm_medium' => 'Broad marketing channel, such as email, social, or cpc.',
                    'utm_campaign' => 'Campaign name.',
                    'utm_term' => 'Campaign search term.',
                    'utm_content' => 'Campaign creative or content variant.',
                    default => 'Campaign identifier.',
                },
                'consent_required' => true,
                'column' => $key,
            ];
        }

        return [
            self::PROPERTIES_KEY => $properties,
            self::MAPPINGS_KEY => array_combine(self::UTM_KEYS, self::UTM_KEYS),
            self::PAGE_SEQUENCE_ENABLED_KEY => false,
            self::PAGE_SEQUENCE_METHOD_KEY => 'session_storage',
        ];
    }

    public function toArray(): array
    {
        $this->config->assertHealthy();
        $defaults = self::defaults();
        $raw = $this->config->all();
        $properties = array_key_exists(self::PROPERTIES_KEY, $raw) ? $raw[self::PROPERTIES_KEY] : $defaults[self::PROPERTIES_KEY];
        // A replacement model can omit UTMs entirely. Default capture only
        // includes UTM properties that the replacement model still defines.
        $defaultMappings = is_array($properties)
            ? array_intersect_key($defaults[self::MAPPINGS_KEY], $properties)
            : [];
        unset($defaultMappings[$this->markerName()]);

        return $this->validate([
            self::PROPERTIES_KEY => $properties,
            self::MAPPINGS_KEY => array_key_exists(self::MAPPINGS_KEY, $raw) ? $raw[self::MAPPINGS_KEY] : $defaultMappings,
            self::PAGE_SEQUENCE_ENABLED_KEY => array_key_exists(self::PAGE_SEQUENCE_ENABLED_KEY, $raw) ? $raw[self::PAGE_SEQUENCE_ENABLED_KEY] : false,
            self::PAGE_SEQUENCE_METHOD_KEY => array_key_exists(self::PAGE_SEQUENCE_METHOD_KEY, $raw) ? $raw[self::PAGE_SEQUENCE_METHOD_KEY] : 'session_storage',
        ]);
    }

    public function save(array $settings): void
    {
        $this->config->updateMany(function (array $current) use ($settings): array {
            // Older forms and API callers omit the method. Preserve the
            // current value under the write lock rather than implicitly
            // changing a URL counter into browser storage.
            if (!array_key_exists(self::PAGE_SEQUENCE_METHOD_KEY, $settings)) {
                $settings[self::PAGE_SEQUENCE_METHOD_KEY] = array_key_exists(self::PAGE_SEQUENCE_METHOD_KEY, $current)
                    ? $current[self::PAGE_SEQUENCE_METHOD_KEY] : 'session_storage';
            }
            $validated = $this->validate($settings);
            // Recheck the related marker setting under the same write lock;
            // a concurrent marker edit must not create a JSON-key collision.
            $markerName = $this->config->hasEnvironmentOverride('internal_traffic_name', true)
                ? $this->config->getWithEnvFallback('internal_traffic_name', 'orgInternalTraffic', true)
                : ($current['internal_traffic_name'] ?? 'orgInternalTraffic');
            if ($validated[self::PAGE_SEQUENCE_ENABLED_KEY] && $markerName === self::PAGE_SEQUENCE_PROPERTY) {
                throw new \InvalidArgumentException('The organization marker name cannot be page_sequence while page sequence collection is enabled.');
            }

            return $validated;
        });
    }

    public function exportYaml(): string
    {
        return "# Aggregate custom data model. Merge into the active aggregate YAML configuration.\n"
            ."# Collection rules apply to future events; regenerate reporting views after column changes.\n"
            .Yaml::dump($this->toArray(), 5, 2);
    }

    /** @return array<string, array{description: string, consent_required: bool, column: string, type?: string, numeric_column?: string}> */
    public function properties(): array
    {
        return $this->toArray()[self::PROPERTIES_KEY];
    }

    /** @return array<string, string> SQL alias => literal top-level JSON key */
    public function reportingColumns(): array
    {
        $columns = [];
        foreach ($this->properties() as $key => $definition) {
            if ($definition['column'] !== '') {
                $columns[$definition['column']] = $key;
            }
        }

        return $columns;
    }

    /** @return array<string, array{property: string, type: string}> SQL alias => numeric projection */
    public function numericReportingColumns(): array
    {
        $columns = [];
        foreach ($this->properties() as $key => $definition) {
            if (($definition['numeric_column'] ?? '') !== '') {
                $columns[$definition['numeric_column']] = ['property' => $key, 'type' => $definition['type']];
            }
        }

        return $columns;
    }

    /** @return array<string, string> Explicit collection types; unspecified properties retain scalar behavior. */
    public function propertyTypes(): array
    {
        $types = [];
        foreach ($this->properties() as $key => $definition) {
            if (($definition['type'] ?? 'scalar') !== 'scalar') {
                $types[$key] = $definition['type'];
            }
        }

        return $types;
    }

    /** @return array{queryParameters: array<string, string>, consentFreeProperties: list<string>, pageSequenceEnabled: bool, pageSequenceMethod: string, pageSequenceExcludedPaths?: list<string>, propertyTypes?: array<string, string>} */
    public function toBrowserConfig(): array
    {
        $settings = $this->toArray();
        $markerName = $this->markerName();
        $consentFree = [];
        $types = [];
        foreach ($settings[self::PROPERTIES_KEY] as $key => $definition) {
            // Generated page depth has its own opt-in and cannot become an
            // ordinary property through browser allowlists or URL mappings.
            if ($key === self::PAGE_SEQUENCE_PROPERTY) {
                continue;
            }
            if (!$definition['consent_required'] && $key !== $markerName) {
                $consentFree[] = $key;
            }
            if ($key !== $markerName && self::isValidPropertyKey($key) && ($definition['type'] ?? 'scalar') !== 'scalar') {
                $types[$key] = $definition['type'];
            }
        }

        $privacyPolicy = new PrivacyPolicy($this->config);
        $pageSequenceEnabled = $settings[self::PAGE_SEQUENCE_ENABLED_KEY] && $privacyPolicy->isAnonymousTrackingEnabled();
        $browserConfig = [
            'queryParameters' => $settings[self::MAPPINGS_KEY],
            'consentFreeProperties' => $consentFree,
            'pageSequenceEnabled' => $pageSequenceEnabled,
            'pageSequenceMethod' => $settings[self::PAGE_SEQUENCE_METHOD_KEY],
        ];
        if ($pageSequenceEnabled) {
            $browserConfig['pageSequenceExcludedPaths'] = $privacyPolicy->excludedPaths();
        }
        if ($types !== []) {
            $browserConfig['propertyTypes'] = $types;
        }

        return $browserConfig;
    }

    /** Server enforcement is independent of any browser-side override. */
    public function filterEventData(mixed $value, bool $enhancedConsent): ?array
    {
        return $this->filterValidatedEventData($value, $enhancedConsent, $this->toArray());
    }

    /** Preview an explicitly supplied model without changing the saved configuration. */
    public function filterEventDataForModel(mixed $value, bool $enhancedConsent, array $model): ?array
    {
        return $this->filterValidatedEventData($value, $enhancedConsent, $this->validate($model));
    }

    private function filterValidatedEventData(mixed $value, bool $enhancedConsent, array $settings): ?array
    {
        $clean = (new PrivacySanitizer())->sanitizeEventData($value) ?? [];
        $markerName = $this->markerName();
        foreach ($clean as $key => $item) {
            if ($key === self::PAGE_SEQUENCE_PROPERTY) {
                $pageSequence = self::sanitizePageSequence($item);
                if (!$settings[self::PAGE_SEQUENCE_ENABLED_KEY] || $pageSequence === null) {
                    unset($clean[$key]);
                } else {
                    $clean[$key] = $pageSequence;
                }
                continue;
            }
            $type = $settings[self::PROPERTIES_KEY][$key]['type'] ?? 'scalar';
            if ($key === $markerName || !self::isValidPropertyKey($key)
                || (!$enhancedConsent && ($settings[self::PROPERTIES_KEY][$key]['consent_required'] ?? true))
                || !self::matchesType($item, $type)) {
                unset($clean[$key]);
            } elseif ($type === 'integer' && $item !== null) {
                // JSON has one numeric type. An integral exponent/decimal
                // representation is valid, but fractional values never round.
                $clean[$key] = (int) $item;
            }
        }

        return $clean ?: null;
    }

    /** Validate page depth at ingestion and when approving anonymous entity data. */
    public static function sanitizePageSequence(mixed $value): ?int
    {
        return $value !== null && self::matchesType($value, 'integer') && $value >= 1 && $value <= self::PAGE_SEQUENCE_MAXIMUM
            ? (int) $value
            : null;
    }

    private static function matchesType(mixed $value, string $type): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_float($value) && !is_finite($value)) {
            return false;
        }

        return match ($type) {
            'scalar' => is_scalar($value),
            'string' => is_string($value),
            'boolean' => is_bool($value),
            'integer' => (is_int($value) || is_float($value))
                && abs($value) <= self::MAXIMUM_SAFE_INTEGER && floor($value) === (float) $value,
            'float', 'double' => is_int($value) || is_float($value),
            default => false,
        };
    }

    public static function isValidPropertyKey(mixed $key): bool
    {
        return is_string($key)
            && preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,63}$/D', $key) === 1
            && !in_array($key, ['constructor', 'prototype', '__proto__'], true);
    }

    public function validate(array $settings): array
    {
        if (array_diff(array_keys($settings), [self::PROPERTIES_KEY, self::MAPPINGS_KEY, self::PAGE_SEQUENCE_ENABLED_KEY, self::PAGE_SEQUENCE_METHOD_KEY]) !== []
            || !array_key_exists(self::PROPERTIES_KEY, $settings)
            || !array_key_exists(self::MAPPINGS_KEY, $settings)) {
            throw new \InvalidArgumentException('Provide custom_data_properties, query_parameter_mappings, and optional page_sequence_enabled and page_sequence_method only.');
        }
        $pageSequenceEnabled = array_key_exists(self::PAGE_SEQUENCE_ENABLED_KEY, $settings) ? $settings[self::PAGE_SEQUENCE_ENABLED_KEY] : false;
        if (!is_bool($pageSequenceEnabled)) {
            throw new \InvalidArgumentException('page_sequence_enabled must be a YAML boolean: true or false.');
        }
        $pageSequenceMethod = array_key_exists(self::PAGE_SEQUENCE_METHOD_KEY, $settings) ? $settings[self::PAGE_SEQUENCE_METHOD_KEY] : 'session_storage';
        if (!is_string($pageSequenceMethod) || !in_array($pageSequenceMethod, self::PAGE_SEQUENCE_METHODS, true)) {
            throw new \InvalidArgumentException('page_sequence_method must be session_storage or url_parameter.');
        }
        $properties = $settings[self::PROPERTIES_KEY];
        $mappings = $settings[self::MAPPINGS_KEY];
        if (!is_array($properties) || count($properties) > 50 || !is_array($mappings) || count($mappings) > 100) {
            throw new \InvalidArgumentException('The model supports up to 50 properties and 100 query-parameter mappings. Both must be YAML mappings.');
        }

        $markerName = $this->markerName();
        if ($pageSequenceEnabled && $markerName === self::PAGE_SEQUENCE_PROPERTY) {
            throw new \InvalidArgumentException('The organization marker name cannot be page_sequence while page sequence collection is enabled.');
        }
        $normalized = [];
        $columns = [];
        foreach ($properties as $key => $definition) {
            if (!is_array($definition) || array_diff(array_keys($definition), ['description', 'consent_required', 'column', 'type', 'numeric_column']) !== []) {
                throw new \InvalidArgumentException('Each property needs a valid JSON key and only description, consent_required, column, type, and numeric_column settings.');
            }
            $description = array_key_exists('description', $definition) ? $definition['description'] : '';
            $consentRequired = array_key_exists('consent_required', $definition) ? $definition['consent_required'] : true;
            $column = array_key_exists('column', $definition) ? $definition['column'] : '';
            $type = array_key_exists('type', $definition) ? $definition['type'] : 'scalar';
            $numericColumn = array_key_exists('numeric_column', $definition) ? $definition['numeric_column'] : '';
            if (!is_string($description) || strlen($description) > 1000 || !is_bool($consentRequired) || !is_string($column) || !is_string($numericColumn)) {
                throw new \InvalidArgumentException('Descriptions must be at most 1000 bytes, consent_required must be true or false, and columns must be strings.');
            }
            if (!is_string($type) || !in_array($type, self::TYPES, true)) {
                throw new \InvalidArgumentException('Property type must be scalar, string, integer, float, double, or boolean.');
            }
            // Preserve legacy definitions for historical reporting while the
            // counter is off, including a renamed organization marker.
            if ($pageSequenceEnabled && $key === self::PAGE_SEQUENCE_PROPERTY && $key !== $markerName && ($type !== 'integer' || $consentRequired)) {
                throw new \InvalidArgumentException('page_sequence is generated for both consent modes; its optional reporting definition requires type: integer and consent_required: false. Use page_sequence_enabled to control collection.');
            }
            // Legacy marker keys can outlive a marker rename. Permit their
            // projection without making them eligible for event properties.
            $legacyReportingKey = is_string($key) && preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $key) === 1
                && preg_match('/^-?[0-9]+$/D', $key) !== 1 && !in_array($key, ['__proto__', 'constructor', 'prototype'], true)
                && $column !== '' && $consentRequired;
            if (!self::isValidPropertyKey($key) && $key !== $markerName && !$legacyReportingKey) {
                throw new \InvalidArgumentException('Property names must start with a letter and use up to 64 letters, digits, underscores, dots, or hyphens. Legacy marker names can be retained as consent-required reporting columns.');
            }
            if (($key === $markerName || !self::isValidPropertyKey($key)) && !in_array($type, ['scalar', 'boolean'], true)) {
                throw new \InvalidArgumentException('Organization marker properties support only scalar or boolean type; their values remain controlled booleans.');
            }
            if ($numericColumn !== '' && !in_array($type, ['integer', 'float', 'double'], true)) {
                throw new \InvalidArgumentException('A numeric reporting column requires an explicit integer, float, or double property type.');
            }
            foreach ([$column, $numericColumn] as $alias) {
                if ($alias === '') {
                    continue;
                }
                if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $alias) !== 1
                    || in_array($alias, self::RESERVED_COLUMNS, true) || isset($columns[$alias])) {
                    throw new \InvalidArgumentException('Reporting columns must be unique lowercase SQL names (up to 63 characters) and cannot use a built-in column name.');
                }
                $columns[$alias] = true;
            }
            // The existing organization marker is always a coarse boolean,
            // collected separately from submitted properties in either mode.
            $normalized[$key] = ['description' => $description, 'consent_required' => $consentRequired, 'column' => $column];
            if (array_key_exists('type', $definition)) {
                $normalized[$key]['type'] = $type;
            }
            if (array_key_exists('numeric_column', $definition)) {
                $normalized[$key]['numeric_column'] = $numericColumn;
            }
        }

        foreach ($mappings as $parameter => $property) {
            if (!self::isValidPropertyKey($parameter) || !self::isValidPropertyKey($property) || !isset($normalized[$property]) || $property === $markerName || $property === self::PAGE_SEQUENCE_PROPERTY || $parameter === self::PAGE_SEQUENCE_QUERY_PARAMETER) {
                throw new \InvalidArgumentException('Each query parameter must map to a defined custom property. The organization marker, page_sequence, and reserved aggregate_page_sequence parameter cannot use query mappings.');
            }
        }

        return [
            self::PROPERTIES_KEY => $normalized,
            self::MAPPINGS_KEY => $mappings,
            self::PAGE_SEQUENCE_ENABLED_KEY => $pageSequenceEnabled,
            self::PAGE_SEQUENCE_METHOD_KEY => $pageSequenceMethod,
        ];
    }

    private function markerName(): string
    {
        return (new InternalTrafficSettings($this->config))->toBrowserConfig()['name'];
    }
}
