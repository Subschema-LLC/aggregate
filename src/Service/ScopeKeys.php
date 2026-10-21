<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Settings a website or website group may override.
 *
 * Deployment YAML, including its environment variables, remains the base. A
 * group file replaces listed keys for its websites, and a website file replaces
 * those keys for that website. Branding, retention, reporting views, users and
 * the dashboard stay on the deployment.
 */
final class ScopeKeys
{
    /** @var array<string, list<string>> */
    private const COLLECTION_GROUPS = [
        'profile' => [CollectionProfile::KEY],
        'tracking' => ['anonymous_tracking_enabled', 'anonymous_excluded_paths'],
        'geography' => ['anonymous_geo_enabled', 'anonymous_geo_level', 'anonymous_geo_database_path'],
        'consent' => [CustomDataSettings::GOOGLE_CONSENT_MODE_KEY],
        'bots' => [BotTrafficSettings::KEY, BotTrafficSettings::USER_AGENTS_KEY],
    ];

    /** @var list<string> */
    private const MODEL_KEYS = [
        CustomDataSettings::PROPERTIES_KEY,
        CustomDataSettings::MAPPINGS_KEY,
        CustomDataSettings::PAGE_SEQUENCE_ENABLED_KEY,
        CustomDataSettings::PAGE_SEQUENCE_METHOD_KEY,
        CustomDataSettings::QUERY_PERSISTENCE_KEY,
    ];

    /**
     * @return array<string, list<string>>
     */
    public static function collectionGroups(): array
    {
        return self::COLLECTION_GROUPS;
    }

    /**
     * @return list<string>
     */
    public static function modelKeys(): array
    {
        return self::MODEL_KEYS;
    }

    /**
     * @return list<string>
     */
    public static function allowed(): array
    {
        $keys = self::MODEL_KEYS;
        foreach (self::COLLECTION_GROUPS as $group) {
            array_push($keys, ...$group);
        }

        return $keys;
    }

    /**
     * @param array<mixed> $values a parsed setting file, which may not be a mapping
     */
    public static function errors(array $values): ?string
    {
        if ($values !== [] && array_is_list($values)) {
            return 'A website or group setting file must be a mapping.';
        }
        $unknown = array_diff(array_keys($values), self::allowed());
        if ($unknown !== []) {
            return 'A website or group setting file contains a setting that cannot vary by website.';
        }
        if (array_key_exists(CollectionProfile::KEY, $values) && !CollectionProfile::isValid($values[CollectionProfile::KEY])) {
            return 'A website or group collection profile must be standard or strict.';
        }
        if (array_key_exists('anonymous_tracking_enabled', $values) && !is_bool($values['anonymous_tracking_enabled'])) {
            return 'A website or group collection switch must be true or false.';
        }
        if (array_key_exists('anonymous_excluded_paths', $values) && self::excludedPathsError($values['anonymous_excluded_paths']) !== null) {
            return self::excludedPathsError($values['anonymous_excluded_paths']);
        }
        if (array_key_exists('anonymous_geo_enabled', $values) && !is_bool($values['anonymous_geo_enabled'])) {
            return 'A website or group geography switch must be true or false.';
        }
        if (array_key_exists('anonymous_geo_level', $values) && !in_array($values['anonymous_geo_level'], CollectionControlsForm::GEO_LEVELS, true)) {
            return 'A website or group geography level must be macro_region or country.';
        }
        if (array_key_exists('anonymous_geo_database_path', $values)) {
            if (!is_string($values['anonymous_geo_database_path'])) {
                return 'A website or group geography database path must be a string.';
            }
            try {
                CollectionControlsForm::assertLocalGeoDatabasePath($values['anonymous_geo_database_path']);
            } catch (\InvalidArgumentException $error) {
                return $error->getMessage();
            }
        }
        if (array_key_exists(CustomDataSettings::GOOGLE_CONSENT_MODE_KEY, $values)
            && CustomDataSettings::normalizeGoogleConsentMode($values[CustomDataSettings::GOOGLE_CONSENT_MODE_KEY]) === null) {
            return 'A website or group Google Consent Mode setting is not valid.';
        }
        if (array_key_exists(BotTrafficSettings::KEY, $values) && BotTrafficSettings::normalizeMode($values[BotTrafficSettings::KEY]) === null) {
            return 'A website or group bot-traffic setting is not valid.';
        }
        if (array_key_exists(BotTrafficSettings::USER_AGENTS_KEY, $values)
            && BotTrafficSettings::normalizeUserAgents($values[BotTrafficSettings::USER_AGENTS_KEY]) === null) {
            return 'A website or group bot phrase list is not valid.';
        }
        foreach (self::MODEL_KEYS as $key) {
            if (!array_key_exists($key, $values)) {
                continue;
            }
            $value = $values[$key];
            if ($key === CustomDataSettings::PAGE_SEQUENCE_ENABLED_KEY && !is_bool($value)) {
                return 'page_sequence_enabled must be true or false in a website or group file.';
            }
            if ($key === CustomDataSettings::PAGE_SEQUENCE_METHOD_KEY
                && (!is_string($value) || !in_array($value, CustomDataSettings::PAGE_SEQUENCE_METHODS, true))) {
                return 'page_sequence_method must be session_storage or url_parameter in a website or group file.';
            }
            if ($key === CustomDataSettings::QUERY_PERSISTENCE_KEY && !CustomDataSettings::isQueryPersistenceMode($value)) {
                return 'query_parameter_persistence must be off, consented or always in a website or group file.';
            }
            if (in_array($key, [CustomDataSettings::PROPERTIES_KEY, CustomDataSettings::MAPPINGS_KEY], true)
                && (!is_array($value) || ($value !== [] && array_is_list($value)))) {
                return 'A website or group data model must use YAML mappings for properties and query parameters.';
            }
        }

        return null;
    }

    private static function excludedPathsError(mixed $paths): ?string
    {
        if (!is_array($paths) || ($paths !== [] && !array_is_list($paths)) || count($paths) > CollectionControlsForm::MAX_EXCLUDED_PATHS) {
            return 'A website or group excluded-path list must contain at most 100 paths.';
        }
        foreach ($paths as $path) {
            if (!is_string($path) || $path === '' || strlen($path) > 512 || !str_starts_with($path, '/')
                || str_contains($path, '?') || str_contains($path, '#')) {
                return 'A website or group excluded path must begin with / and omit query strings and fragments.';
            }
        }

        return null;
    }
}
