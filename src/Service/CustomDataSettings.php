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
    /**
     * Whether mapped query values are kept for the rest of a browser tab's
     * session: off (the default, only the current page URL), consented (kept
     * only once enhanced analytics is granted) or always (consent-free
     * properties are also kept before a choice and after a rejection;
     * consent-required ones still only with consent).
     */
    public const QUERY_PERSISTENCE_KEY = 'query_parameter_persistence';
    public const QUERY_PERSISTENCE_MODES = ['off', 'consented', 'always'];
    /**
     * How the visitor arrived in the browser tab: the referrer channel of the
     * page that began the kept values. The tracker adds it while query
     * persistence is on; the server accepts only these codes.
     */
    public const ENTRY_CHANNEL_PROPERTY = 'entry_channel';
    public const ENTRY_CHANNELS = ['direct', 'internal', 'search', 'social', 'email', 'referral'];
    public const WEBSITE_GROUP_PROPERTY = 'website_group';
    public const UNGROUPED_WEBSITE_GROUP = '(ungrouped)';
    public const GOOGLE_CONSENT_MODE_KEY = 'google_consent_mode';
    /**
     * How the tracker follows Google Consent Mode analytics_storage signals:
     * all (default and update commands, the default), updates (only update
     * commands, which consent managers send for a visitor's choice) or off.
     */
    public const GOOGLE_CONSENT_MODES = ['all', 'updates', 'off'];
    public const UTM_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id'];
    public const TYPES = ['scalar', 'string', 'integer', 'float', 'double', 'boolean'];
    /** The collection payload object that carries an event's custom properties. */
    public const PAYLOAD_KEY = 'customData';
    /** The earlier name of that object, still accepted when customData is absent. */
    public const LEGACY_PAYLOAD_KEY = 'eventData';
    public const MAXIMUM_SAFE_INTEGER = 9_007_199_254_740_991;
    /**
     * Properties Aggregate records itself: the organization-traffic flag on
     * every event, page depth while it is on, and the entry channel while
     * query persistence is on. Their type is fixed and they need no consent
     * setting, so a definition only chooses the reporting column (`alias`:
     * text or numeric). All are in the default model.
     */
    public const BUILTIN_PROPERTIES = [
        InternalTrafficSettings::JSON_KEY => [
            'description' => 'Organization traffic: true for a marked browser.',
            'type' => 'boolean',
            'alias' => 'column',
        ],
        self::PAGE_SEQUENCE_PROPERTY => [
            'description' => 'Page depth; 20 means 20 or more.',
            'type' => 'integer',
            'alias' => 'numeric_column',
        ],
        self::ENTRY_CHANNEL_PROPERTY => [
            'description' => 'How the visitor arrived in this browser tab: direct, search, social, email, referral or internal.',
            'type' => 'string',
            'alias' => 'column',
        ],
        self::WEBSITE_GROUP_PROPERTY => [
            'description' => 'Website group display name from config/website-scopes.yaml, or (ungrouped) when the website is not in a group.',
            'type' => 'string',
            'alias' => 'column',
        ],
    ];
    public const RESERVED_COLUMNS = [
        'id', 'website_token', 'event_name', 'page_path', 'referrer_channel', 'privacy_mode',
        'device_class', 'viewport_bucket', 'geo_area', 'goal_event', 'created_at', 'archived_at',
        'url', 'referrer', 'screen_width', 'visitor_id', 'session_id', 'consent_state',
        'custom_data', 'generalized_user_agent', 'event_hour', 'event_day', 'event_count',
        'page_group', 'detail_level', 'view_name', 'coverage_band', 'geo_level',
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

        foreach (self::BUILTIN_PROPERTIES as $key => $builtin) {
            if ($key === self::WEBSITE_GROUP_PROPERTY) {
                continue;
            }
            $properties[$key] = self::builtinDefinition($key, $key);
        }

        return [
            self::PROPERTIES_KEY => $properties,
            self::MAPPINGS_KEY => array_combine(self::UTM_KEYS, self::UTM_KEYS),
            self::PAGE_SEQUENCE_ENABLED_KEY => false,
            self::PAGE_SEQUENCE_METHOD_KEY => 'session_storage',
            self::QUERY_PERSISTENCE_KEY => 'off',
        ];
    }

    /**
     * A built-in property's definition with its reporting column, or none
     * when $column is empty.
     *
     * @return array{description: string, consent_required: false, column: string, type: string, numeric_column?: string}
     */
    public static function builtinDefinition(string $key, string $column): array
    {
        $builtin = self::BUILTIN_PROPERTIES[$key] ?? throw new \InvalidArgumentException($key.' is not a built-in property.');
        $definition = ['description' => $builtin['description'], 'consent_required' => false, 'column' => '', 'type' => $builtin['type']];
        if ($column !== '') {
            $definition[$builtin['alias']] = $column;
        }

        return $definition;
    }

    public static function isBuiltin(mixed $key): bool
    {
        return is_string($key) && isset(self::BUILTIN_PROPERTIES[$key]);
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
        unset($defaultMappings[InternalTrafficSettings::JSON_KEY]);

        return $this->validate([
            self::PROPERTIES_KEY => $properties,
            self::MAPPINGS_KEY => array_key_exists(self::MAPPINGS_KEY, $raw) ? $raw[self::MAPPINGS_KEY] : $defaultMappings,
            self::PAGE_SEQUENCE_ENABLED_KEY => array_key_exists(self::PAGE_SEQUENCE_ENABLED_KEY, $raw) ? $raw[self::PAGE_SEQUENCE_ENABLED_KEY] : false,
            self::PAGE_SEQUENCE_METHOD_KEY => array_key_exists(self::PAGE_SEQUENCE_METHOD_KEY, $raw) ? $raw[self::PAGE_SEQUENCE_METHOD_KEY] : 'session_storage',
            self::QUERY_PERSISTENCE_KEY => array_key_exists(self::QUERY_PERSISTENCE_KEY, $raw) ? $raw[self::QUERY_PERSISTENCE_KEY] : 'off',
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
            // Likewise keep query persistence when a caller leaves it out.
            if (!array_key_exists(self::QUERY_PERSISTENCE_KEY, $settings)) {
                $settings[self::QUERY_PERSISTENCE_KEY] = array_key_exists(self::QUERY_PERSISTENCE_KEY, $current)
                    ? $current[self::QUERY_PERSISTENCE_KEY] : 'off';
            }
            return $this->validate($settings);
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

    /** The reporting column name for the website group dimension, or null when not enabled. */
    public function websiteGroupColumn(): ?string
    {
        $column = $this->properties()[self::WEBSITE_GROUP_PROPERTY]['column'] ?? '';

        return $column !== '' ? $column : null;
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

    /**
     * The effective Google Consent Mode setting: the top-level key, otherwise
     * the older consent_manager.google_consent_mode, otherwise all. YAML true and
     * false mean all and off; any other value fails closed to off.
     */
    public function googleConsentMode(): string
    {
        $value = $this->configuredGoogleConsentMode();

        return $value === null ? 'all' : (self::normalizeGoogleConsentMode($value) ?? 'off');
    }

    /** Whether a configured Google Consent Mode value is invalid, and so treated as off. */
    public function hasInvalidGoogleConsentMode(): bool
    {
        $value = $this->configuredGoogleConsentMode();

        return $value !== null && self::normalizeGoogleConsentMode($value) === null;
    }

    public static function normalizeGoogleConsentMode(mixed $value): ?string
    {
        return match (true) {
            $value === true => 'all',
            $value === false => 'off',
            is_string($value) && in_array($value, self::GOOGLE_CONSENT_MODES, true) => $value,
            default => null,
        };
    }

    /** Saves the top-level setting, which takes precedence over the older nested key. */
    public function saveGoogleConsentMode(mixed $mode): void
    {
        $normalized = self::normalizeGoogleConsentMode($mode);
        if ($normalized === null) {
            throw new \InvalidArgumentException('Google Consent Mode signals must be one of '.implode(', ', self::GOOGLE_CONSENT_MODES).'.');
        }
        $this->config->setMany([self::GOOGLE_CONSENT_MODE_KEY => $normalized]);
    }

    private function configuredGoogleConsentMode(): mixed
    {
        if ($this->config->has(self::GOOGLE_CONSENT_MODE_KEY)) {
            return $this->config->get(self::GOOGLE_CONSENT_MODE_KEY);
        }
        if ($this->config->has('consent_manager')) {
            $cm = $this->config->get('consent_manager');
            if (is_array($cm) && array_key_exists(self::GOOGLE_CONSENT_MODE_KEY, $cm)) {
                return $cm[self::GOOGLE_CONSENT_MODE_KEY];
            }
        }

        return null;
    }

    /** @return array{queryParameters: array<string, string>, consentFreeProperties: list<string>, pageSequenceEnabled: bool, pageSequenceMethod: string, queryParameterPersistence: string, googleConsentMode?: string, pageSequenceExcludedPaths?: list<string>, propertyTypes?: array<string, string>} */

    public function toBrowserConfig(): array
    {
        $settings = $this->toArray();
        $consentFree = [];
        $types = [];
        foreach ($settings[self::PROPERTIES_KEY] as $key => $definition) {
            // Generated page depth, entry channel and the reporting-only
            // website group cannot become ordinary properties through browser
            // allowlists or URL mappings.
            if ($key === self::PAGE_SEQUENCE_PROPERTY || $key === self::ENTRY_CHANNEL_PROPERTY || $key === self::WEBSITE_GROUP_PROPERTY) {
                continue;
            }
            if (!$definition['consent_required'] && $key !== InternalTrafficSettings::JSON_KEY) {
                $consentFree[] = $key;
            }
            if ($key !== InternalTrafficSettings::JSON_KEY && ($definition['type'] ?? 'scalar') !== 'scalar') {
                $types[$key] = $definition['type'];
            }
        }

        $privacyPolicy = new PrivacyPolicy($this->config);
        // The strict profile sends no custom data: no URL reads, page-depth
        // state or consent-free properties. The saved model is left unchanged.
        $strict = $privacyPolicy->isStrictCollection();
        if ($strict) {
            $consentFree = [];
        }
        $pageSequenceEnabled = !$strict && $settings[self::PAGE_SEQUENCE_ENABLED_KEY] && $privacyPolicy->isAnonymousTrackingEnabled();
        // The served value is a ceiling: page options can narrow it, never
        // widen it. Without anonymous collection nothing consent-free is
        // recorded, so nothing is kept before consent either.
        $queryPersistence = $strict ? 'off' : $settings[self::QUERY_PERSISTENCE_KEY];
        if ($queryPersistence === 'always' && !$privacyPolicy->isAnonymousTrackingEnabled()) {
            $queryPersistence = 'consented';
        }
        $browserConfig = [
            'queryParameters' => $strict ? [] : $settings[self::MAPPINGS_KEY],
            'consentFreeProperties' => $consentFree,
            'pageSequenceEnabled' => $pageSequenceEnabled,
            'pageSequenceMethod' => $settings[self::PAGE_SEQUENCE_METHOD_KEY],
            'queryParameterPersistence' => $queryPersistence,
        ];
        if ($pageSequenceEnabled) {
            $browserConfig['pageSequenceExcludedPaths'] = $privacyPolicy->excludedPaths();
        }
        if ($types !== []) {
            $browserConfig['propertyTypes'] = $types;
        }
        // A served tracker keeps a narrower setting; page options cannot widen it.
        $googleConsentMode = $this->googleConsentMode();
        if ($googleConsentMode !== 'all') {
            $browserConfig['googleConsentMode'] = $googleConsentMode;
        }

        return $browserConfig;
    }

    /** Whether the strict collection profile makes the server discard all custom data. */
    public function isStrictCollection(): bool
    {
        return (new PrivacyPolicy($this->config))->isStrictCollection();
    }

    /**
     * The custom properties a collection payload submits: its customData object,
     * or, only when that key is absent, the legacy eventData object. Both pass
     * the same filtering afterwards, so neither name can carry more.
     */
    public static function submittedProperties(array $payload): mixed
    {
        return array_key_exists(self::PAYLOAD_KEY, $payload) ? $payload[self::PAYLOAD_KEY] : ($payload[self::LEGACY_PAYLOAD_KEY] ?? null);
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
            if ($key === self::ENTRY_CHANNEL_PROPERTY) {
                // A fixed code in either mode, only while persistence is on.
                if ($settings[self::QUERY_PERSISTENCE_KEY] === 'off' || !self::isEntryChannel($item)) {
                    unset($clean[$key]);
                }
                continue;
            }
            if ($key === self::WEBSITE_GROUP_PROPERTY) {
                unset($clean[$key]);
                continue;
            }
            $type = $settings[self::PROPERTIES_KEY][$key]['type'] ?? 'scalar';
            if ($key === InternalTrafficSettings::JSON_KEY || !self::isValidPropertyKey($key)
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

    /** Whether a value is one of the entry channel codes. */
    public static function isEntryChannel(mixed $value): bool
    {
        return is_string($value) && in_array($value, self::ENTRY_CHANNELS, true);
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
        if (array_diff(array_keys($settings), [self::PROPERTIES_KEY, self::MAPPINGS_KEY, self::PAGE_SEQUENCE_ENABLED_KEY, self::PAGE_SEQUENCE_METHOD_KEY, self::QUERY_PERSISTENCE_KEY]) !== []
            || !array_key_exists(self::PROPERTIES_KEY, $settings)
            || !array_key_exists(self::MAPPINGS_KEY, $settings)) {
            throw new \InvalidArgumentException('Provide custom_data_properties, query_parameter_mappings, and optional page_sequence_enabled, page_sequence_method and query_parameter_persistence only.');
        }
        $pageSequenceEnabled = array_key_exists(self::PAGE_SEQUENCE_ENABLED_KEY, $settings) ? $settings[self::PAGE_SEQUENCE_ENABLED_KEY] : false;
        if (!is_bool($pageSequenceEnabled)) {
            throw new \InvalidArgumentException('page_sequence_enabled must be a YAML boolean: true or false.');
        }
        $pageSequenceMethod = array_key_exists(self::PAGE_SEQUENCE_METHOD_KEY, $settings) ? $settings[self::PAGE_SEQUENCE_METHOD_KEY] : 'session_storage';
        if (!is_string($pageSequenceMethod) || !in_array($pageSequenceMethod, self::PAGE_SEQUENCE_METHODS, true)) {
            throw new \InvalidArgumentException('page_sequence_method must be session_storage or url_parameter.');
        }
        $queryPersistence = array_key_exists(self::QUERY_PERSISTENCE_KEY, $settings) ? $settings[self::QUERY_PERSISTENCE_KEY] : 'off';
        if (!self::isQueryPersistenceMode($queryPersistence)) {
            throw new \InvalidArgumentException('query_parameter_persistence must be off, consented or always.');
        }
        $properties = $settings[self::PROPERTIES_KEY];
        $mappings = $settings[self::MAPPINGS_KEY];
        if (!is_array($properties) || count($properties) > 50 || !is_array($mappings) || count($mappings) > 100) {
            throw new \InvalidArgumentException('The model supports up to 50 properties and 100 query-parameter mappings. Both must be YAML mappings.');
        }

        $normalized = [];
        $columns = [];
        foreach ($properties as $key => $definition) {
            if (!is_array($definition) || array_diff(array_keys($definition), ['description', 'consent_required', 'column', 'type', 'numeric_column']) !== []) {
                throw new \InvalidArgumentException('Each property needs a valid JSON key and only description, consent_required, column, type, and numeric_column settings.');
            }
            $builtin = self::isBuiltin($key);
            $description = array_key_exists('description', $definition) ? $definition['description'] : '';
            $consentRequired = array_key_exists('consent_required', $definition) ? $definition['consent_required'] : !$builtin;
            $column = array_key_exists('column', $definition) ? $definition['column'] : '';
            // A built-in property's type is known, so a definition may leave it out.
            $type = array_key_exists('type', $definition) ? $definition['type'] : ($builtin ? self::BUILTIN_PROPERTIES[$key]['type'] : 'scalar');
            $numericColumn = array_key_exists('numeric_column', $definition) ? $definition['numeric_column'] : '';
            if (!is_string($description) || strlen($description) > 1000 || !is_bool($consentRequired) || !is_string($column) || !is_string($numericColumn)) {
                throw new \InvalidArgumentException('Descriptions must be at most 1000 bytes, consent_required must be true or false, and columns must be strings.');
            }
            if (!is_string($type) || !in_array($type, self::TYPES, true)) {
                throw new \InvalidArgumentException('Property type must be scalar, string, integer, float, double, or boolean.');
            }
            // Preserve a page_sequence definition of another type for
            // historical reporting while the counter is off.
            if ($pageSequenceEnabled && $key === self::PAGE_SEQUENCE_PROPERTY && $type !== 'integer') {
                throw new \InvalidArgumentException('page_sequence is page depth, a whole number from 1 to 20: leave its type out or use type: integer. page_sequence_enabled controls whether it is collected.');
            }
            if (!self::isValidPropertyKey($key)) {
                throw new \InvalidArgumentException('Property names must start with a letter and use up to 64 letters, digits, underscores, dots, or hyphens.');
            }
            if ($key === self::ENTRY_CHANNEL_PROPERTY && !in_array($type, ['scalar', 'string'], true)) {
                throw new \InvalidArgumentException('entry_channel is a text code such as search or email: leave its type out or use type: string.');
            }
            if ($key === self::WEBSITE_GROUP_PROPERTY && !in_array($type, ['scalar', 'string'], true)) {
                throw new \InvalidArgumentException('website_group is a text group name: leave its type out or use type: string.');
            }
            if ($key === InternalTrafficSettings::JSON_KEY && !in_array($type, ['scalar', 'boolean'], true)) {
                throw new \InvalidArgumentException('org_internal_traffic supports only the scalar or boolean type; its value is always the true or false organization-traffic flag.');
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
            // org_internal_traffic is always the coarse boolean flag, recorded
            // separately from submitted properties in either mode, and page
            // depth has its own switch: consent never applies to either.
            $normalized[$key] = ['description' => $description, 'consent_required' => $builtin ? false : $consentRequired, 'column' => $column];
            if (array_key_exists('type', $definition) || $builtin) {
                $normalized[$key]['type'] = $type;
            }
            if (array_key_exists('numeric_column', $definition)) {
                $normalized[$key]['numeric_column'] = $numericColumn;
            }
        }

        foreach ($mappings as $parameter => $property) {
            if (!self::isValidPropertyKey($parameter) || !self::isValidPropertyKey($property) || !isset($normalized[$property]) || $property === InternalTrafficSettings::JSON_KEY || $property === self::PAGE_SEQUENCE_PROPERTY || $property === self::ENTRY_CHANNEL_PROPERTY || $property === self::WEBSITE_GROUP_PROPERTY || $parameter === self::PAGE_SEQUENCE_QUERY_PARAMETER) {
                throw new \InvalidArgumentException('Each query parameter must map to a defined custom property. The organization marker, page_sequence, entry_channel, website_group, and reserved aggregate_page_sequence parameter cannot use query mappings.');
            }
        }

        return [
            self::PROPERTIES_KEY => $normalized,
            self::MAPPINGS_KEY => $mappings,
            self::PAGE_SEQUENCE_ENABLED_KEY => $pageSequenceEnabled,
            self::PAGE_SEQUENCE_METHOD_KEY => $pageSequenceMethod,
            self::QUERY_PERSISTENCE_KEY => $queryPersistence,
        ];
    }

    public static function isQueryPersistenceMode(mixed $value): bool
    {
        return is_string($value) && in_array($value, self::QUERY_PERSISTENCE_MODES, true);
    }
}
