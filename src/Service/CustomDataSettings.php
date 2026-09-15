<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Yaml\Yaml;

/** The deployment's shareable contract for custom properties and URL attribution. */
class CustomDataSettings
{
    public const PROPERTIES_KEY = 'custom_data_properties';
    public const MAPPINGS_KEY = 'query_parameter_mappings';
    public const UTM_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id'];
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

        return [self::PROPERTIES_KEY => $properties, self::MAPPINGS_KEY => array_combine(self::UTM_KEYS, self::UTM_KEYS)];
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
        ]);
    }

    public function save(array $settings): void
    {
        $this->config->setMany($this->validate($settings));
    }

    public function exportYaml(): string
    {
        return "# Aggregate custom data model. Merge into the active aggregate YAML configuration.\n"
            ."# Collection rules apply to future events; regenerate reporting views after column changes.\n"
            .Yaml::dump($this->toArray(), 5, 2);
    }

    /** @return array<string, array{description: string, consent_required: bool, column: string}> */
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

    /** @return array{queryParameters: array<string, string>, consentFreeProperties: list<string>} */
    public function toBrowserConfig(): array
    {
        $settings = $this->toArray();
        $markerName = $this->markerName();
        $consentFree = [];
        foreach ($settings[self::PROPERTIES_KEY] as $key => $definition) {
            if (!$definition['consent_required'] && $key !== $markerName) {
                $consentFree[] = $key;
            }
        }

        return ['queryParameters' => $settings[self::MAPPINGS_KEY], 'consentFreeProperties' => $consentFree];
    }

    /** Server enforcement is independent of any browser-side override. */
    public function filterEventData(mixed $value, bool $enhancedConsent): ?array
    {
        $settings = $this->toArray();
        $clean = (new PrivacySanitizer())->sanitizeEventData($value) ?? [];
        $markerName = $this->markerName();
        foreach ($clean as $key => $_) {
            if ($key === $markerName || !self::isValidPropertyKey($key)
                || (!$enhancedConsent && ($settings[self::PROPERTIES_KEY][$key]['consent_required'] ?? true))) {
                unset($clean[$key]);
            }
        }

        return $clean ?: null;
    }

    public static function isValidPropertyKey(mixed $key): bool
    {
        return is_string($key)
            && preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,63}$/D', $key) === 1
            && !in_array($key, ['constructor', 'prototype', '__proto__'], true);
    }

    public function validate(array $settings): array
    {
        if (array_diff(array_keys($settings), [self::PROPERTIES_KEY, self::MAPPINGS_KEY]) !== []
            || !array_key_exists(self::PROPERTIES_KEY, $settings)
            || !array_key_exists(self::MAPPINGS_KEY, $settings)) {
            throw new \InvalidArgumentException('Provide custom_data_properties and query_parameter_mappings only.');
        }
        $properties = $settings[self::PROPERTIES_KEY];
        $mappings = $settings[self::MAPPINGS_KEY];
        if (!is_array($properties) || count($properties) > 50 || !is_array($mappings) || count($mappings) > 100) {
            throw new \InvalidArgumentException('The model supports up to 50 properties and 100 query-parameter mappings. Both must be YAML mappings.');
        }

        $markerName = $this->markerName();
        $normalized = [];
        $columns = [];
        foreach ($properties as $key => $definition) {
            if (!is_array($definition) || array_diff(array_keys($definition), ['description', 'consent_required', 'column']) !== []) {
                throw new \InvalidArgumentException('Each property needs a valid JSON key and only description, consent_required, and column settings.');
            }
            $description = array_key_exists('description', $definition) ? $definition['description'] : '';
            $consentRequired = array_key_exists('consent_required', $definition) ? $definition['consent_required'] : true;
            $column = array_key_exists('column', $definition) ? $definition['column'] : '';
            if (!is_string($description) || strlen($description) > 1000 || !is_bool($consentRequired) || !is_string($column)) {
                throw new \InvalidArgumentException('Descriptions must be at most 1000 bytes, consent_required must be true or false, and columns must be strings.');
            }
            // Legacy marker keys can outlive a marker rename. Permit their
            // projection without making them eligible for event properties.
            $legacyReportingKey = is_string($key) && preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $key) === 1
                && preg_match('/^-?[0-9]+$/D', $key) !== 1 && !in_array($key, ['__proto__', 'constructor', 'prototype'], true)
                && $column !== '' && $consentRequired;
            if (!self::isValidPropertyKey($key) && $key !== $markerName && !$legacyReportingKey) {
                throw new \InvalidArgumentException('Property names must start with a letter and use up to 64 letters, digits, underscores, dots, or hyphens. Legacy marker names can be retained as consent-required reporting columns.');
            }
            if ($column !== '') {
                if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $column) !== 1
                    || in_array($column, self::RESERVED_COLUMNS, true) || isset($columns[$column])) {
                    throw new \InvalidArgumentException('Reporting columns must be unique lowercase SQL names (up to 63 characters) and cannot use a built-in column name.');
                }
                $columns[$column] = true;
            }
            // The existing organization marker is always a coarse boolean,
            // collected separately from submitted properties in either mode.
            $normalized[$key] = ['description' => $description, 'consent_required' => $consentRequired, 'column' => $column];
        }

        foreach ($mappings as $parameter => $property) {
            if (!self::isValidPropertyKey($parameter) || !self::isValidPropertyKey($property) || !isset($normalized[$property]) || $property === $markerName) {
                throw new \InvalidArgumentException('Each query parameter must map to a defined custom property. The organization marker cannot be set from URL parameters.');
            }
        }

        return [self::PROPERTIES_KEY => $normalized, self::MAPPINGS_KEY => $mappings];
    }

    private function markerName(): string
    {
        return (new InternalTrafficSettings($this->config))->toBrowserConfig()['name'];
    }
}
