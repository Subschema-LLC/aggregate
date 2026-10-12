<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\ParameterBag;

/**
 * The Collection controls page's form: validates a submission and returns the
 * YAML values it saves. Each error is an InvalidArgumentException whose
 * message the page shows; nothing is saved when one is thrown.
 */
final class CollectionControlsForm
{
    public const GEO_LEVELS = ['macro_region', 'country'];
    public const MAX_EXCLUDED_PATHS = 100;

    /**
     * @return array{settings: array<string, mixed>, retry: array<string, mixed>, collection_profile: string}
     *   settings for config/aggregate.yaml, retry for TrackingFailureSettings::save(),
     *   and the submitted collection profile
     */
    public static function fromSubmission(ParameterBag $values, CustomDataSettings $current, ?BotTrafficSettings $bots = null): array
    {
        $enabled = $values->getBoolean('anonymous_tracking_enabled');
        $rawPaths = (string) $values->get('anonymous_excluded_paths', '');
        $geoEnabled = $values->getBoolean('anonymous_geo_enabled');
        $geoLevel = trim((string) $values->get('anonymous_geo_level', 'macro_region'));
        $geoDatabasePath = trim((string) $values->get('anonymous_geo_database_path', ''));
        $trackingRetryEnabled = $values->has(TrackingFailureSettings::KEY_ENABLED)
            ? $values->getBoolean(TrackingFailureSettings::KEY_ENABLED)
            : null;
        $trackingRetryBatchSize = $values->get(TrackingFailureSettings::KEY_BATCH_SIZE);
        $collectionProfile = CollectionProfile::normalize($values->get(CollectionProfile::KEY, CollectionProfile::STANDARD));

        if ($collectionProfile === null) {
            throw new \InvalidArgumentException('Collection profile must be standard or strict.');
        }

        // Absent from older forms: the saved value is kept.
        $googleConsentMode = null;
        if ($values->has(CustomDataSettings::GOOGLE_CONSENT_MODE_KEY)) {
            $googleConsentMode = CustomDataSettings::normalizeGoogleConsentMode($values->get(CustomDataSettings::GOOGLE_CONSENT_MODE_KEY));
            if ($googleConsentMode === null) {
                throw new \InvalidArgumentException('Google Consent Mode signals must be one of '.implode(', ', CustomDataSettings::GOOGLE_CONSENT_MODES).'.');
            }
            // An unchanged value is not written, so the default is not pinned.
            if ($googleConsentMode === $current->googleConsentMode() && !$current->hasInvalidGoogleConsentMode()) {
                $googleConsentMode = null;
            }
        }

        if (!in_array($geoLevel, self::GEO_LEVELS, true)) {
            throw new \InvalidArgumentException('Geography level must be macro-region or country.');
        }

        $botSettings = self::botSettings($values, $bots);

        self::assertLocalGeoDatabasePath($geoDatabasePath);
        $paths = self::excludedPaths($rawPaths);

        return [
            'settings' => [
                CollectionProfile::KEY => $collectionProfile,
                'anonymous_tracking_enabled' => $enabled,
                'anonymous_excluded_paths' => $paths,
                'anonymous_geo_enabled' => $geoEnabled,
                'anonymous_geo_level' => $geoLevel,
                'anonymous_geo_database_path' => $geoDatabasePath,
            ] + ($googleConsentMode === null ? [] : [CustomDataSettings::GOOGLE_CONSENT_MODE_KEY => $googleConsentMode]) + $botSettings,
            'retry' => array_filter([
                TrackingFailureSettings::KEY_ENABLED => $trackingRetryEnabled,
                TrackingFailureSettings::KEY_BATCH_SIZE => $trackingRetryBatchSize,
            ], static fn (mixed $value): bool => $value !== null),
            'collection_profile' => $collectionProfile,
        ];
    }

    /**
     * bot_traffic and bot_user_agents. A field the form did not send, or a value
     * equal to the saved one, is not written, so saving does not pin defaults.
     *
     * @return array<string, mixed>
     */
    private static function botSettings(ParameterBag $values, ?BotTrafficSettings $bots): array
    {
        $settings = [];
        if ($values->has(BotTrafficSettings::KEY)) {
            $mode = BotTrafficSettings::normalizeMode($values->get(BotTrafficSettings::KEY));
            if ($mode === null) {
                throw new \InvalidArgumentException('Bot traffic must be label or drop.');
            }
            if ($bots === null || $bots->isInvalid() || $mode !== $bots->mode()) {
                $settings[BotTrafficSettings::KEY] = $mode;
            }
        }
        if ($values->has(BotTrafficSettings::USER_AGENTS_KEY)) {
            $raw = (string) $values->get(BotTrafficSettings::USER_AGENTS_KEY, '');
            if (strlen($raw) > 16_384) {
                throw new \InvalidArgumentException('Bot User-Agent phrases are too long.');
            }
            $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $raw) ?: []), static fn (string $line): bool => $line !== ''));
            $phrases = BotTrafficSettings::normalizeUserAgents($lines);
            if ($phrases === null) {
                throw new \InvalidArgumentException(sprintf('Enter at most %d bot User-Agent phrases, one per line, each %d to %d characters without control characters.',
                    BotTrafficSettings::MAX_USER_AGENTS, BotTrafficSettings::MIN_PHRASE_LENGTH, BotTrafficSettings::MAX_PHRASE_LENGTH));
            }
            if ($bots === null ? $phrases !== [] : ($bots->isInvalid() || $phrases !== $bots->userAgents())) {
                $settings[BotTrafficSettings::USER_AGENTS_KEY] = $phrases;
            }
        }

        return $settings;
    }

    /** Empty, or a local filesystem path ending in .mmdb: no URI, stream wrapper, network share or project-root escape. */
    public static function assertLocalGeoDatabasePath(string $geoDatabasePath): void
    {
        if (strlen($geoDatabasePath) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $geoDatabasePath) === 1) {
            throw new \InvalidArgumentException('The GeoIP database path is invalid or too long.');
        }

        if ($geoDatabasePath !== '') {
            $isWindowsDrivePath = preg_match('/^[A-Za-z]:[\\\\\/]/D', $geoDatabasePath) === 1;
            $isAbsolutePath = str_starts_with($geoDatabasePath, '/')
                || $isWindowsDrivePath;
            $pathSegments = preg_split('#[\\\\/]#', $geoDatabasePath) ?: [];
            if ((!$isWindowsDrivePath && preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/D', $geoDatabasePath) === 1)
                || str_contains($geoDatabasePath, '://')
                || str_starts_with($geoDatabasePath, '\\\\')
                || str_starts_with($geoDatabasePath, '//')
                || !str_ends_with(strtolower($geoDatabasePath), '.mmdb')
                || (!$isAbsolutePath && in_array('..', $pathSegments, true))) {
                throw new \InvalidArgumentException('Use a local filesystem path ending in .mmdb; URI, stream-wrapper, UNC/network, and project-root escape paths are not allowed.');
            }
        }
    }

    /**
     * One path or glob per line, trimmed and deduplicated.
     *
     * @return list<string>
     */
    public static function excludedPaths(string $rawPaths): array
    {
        if (strlen($rawPaths) > 25_600) {
            throw new \InvalidArgumentException('Excluded paths are too long.');
        }

        $paths = preg_split('/\R/', $rawPaths) ?: [];
        $paths = array_values(array_unique(array_filter(array_map('trim', $paths), static fn (string $path): bool => $path !== '')));

        if (count($paths) > self::MAX_EXCLUDED_PATHS) {
            throw new \InvalidArgumentException('Use no more than 100 anonymous tracking exclusions.');
        }

        foreach ($paths as $path) {
            if (strlen($path) > 512 || !str_starts_with($path, '/') || str_contains($path, '?') || str_contains($path, '#')) {
                throw new \InvalidArgumentException(sprintf('Invalid excluded path "%s". Use a path beginning with /, omit query strings/fragments, and keep it at most 512 characters.', $path));
            }
        }

        return $paths;
    }
}
