<?php

declare(strict_types=1);

namespace App\Service\BigQuery;

use App\Service\AggregateConfigLoader;

/**
 * BigQuery sync settings: one source for the admin page, YAML, environment
 * variables and the sync command. Uppercase environment variables take
 * precedence and lock the matching admin fields; lists in the environment are
 * comma-separated. Secrets (key files, the Google sign-in token) live in
 * config/secrets, not in these settings.
 */
final class BigQuerySettings
{
    public const KEY_ENABLED = 'bigquery_enabled';
    public const KEY_PROJECT = 'bigquery_project_id';
    public const KEY_DATASET = 'bigquery_dataset';
    public const KEY_LOCATION = 'bigquery_location';
    public const KEY_AUTH = 'bigquery_auth';
    public const KEY_CREDENTIALS_FILE = 'bigquery_credentials_file';
    public const KEY_OAUTH_CLIENT_ID = 'bigquery_oauth_client_id';
    public const KEY_VIEWS = 'bigquery_views';
    public const KEY_PRIVATE_VIEWS = 'bigquery_private_views';
    public const KEY_INTERVAL = 'bigquery_interval_minutes';

    public const AUTH_SERVICE_ACCOUNT = 'service_account';
    public const AUTH_GOOGLE_CLOUD = 'google_cloud';
    public const AUTH_GOOGLE_SIGN_IN = 'google_sign_in';
    public const AUTH_METHODS = [
        self::AUTH_SERVICE_ACCOUNT => 'Service account key',
        self::AUTH_GOOGLE_CLOUD => 'Automatic on Google Cloud',
        self::AUTH_GOOGLE_SIGN_IN => 'Sign in with Google',
    ];

    public const DEFAULT_CREDENTIALS_FILE = 'config/secrets/bigquery-service-account.json';
    public const INTERVALS = [15, 30, 60, 120, 180, 360, 720, 1440];

    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly string $projectDir,
    ) {
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            self::KEY_ENABLED => false,
            self::KEY_PROJECT => '',
            self::KEY_DATASET => 'aggregate',
            self::KEY_LOCATION => 'US',
            self::KEY_AUTH => self::AUTH_SERVICE_ACCOUNT,
            self::KEY_CREDENTIALS_FILE => self::DEFAULT_CREDENTIALS_FILE,
            self::KEY_OAUTH_CLIENT_ID => '',
            self::KEY_VIEWS => array_keys(BigQueryViewCatalog::APPROVED),
            self::KEY_PRIVATE_VIEWS => [],
            self::KEY_INTERVAL => 60,
        ];
    }

    /**
     * The effective, validated settings.
     *
     * @return array{bigquery_enabled: bool, bigquery_project_id: string, bigquery_dataset: string, bigquery_location: string, bigquery_auth: string, bigquery_credentials_file: string, bigquery_oauth_client_id: string, bigquery_views: list<string>, bigquery_private_views: list<string>, bigquery_interval_minutes: int}
     */
    public function toArray(): array
    {
        $this->config->assertHealthy();
        $values = [];
        foreach (self::defaults() as $key => $default) {
            $values[$key] = $this->config->getWithEnvFallback($key, $default, in_array($key, [self::KEY_PROJECT, self::KEY_OAUTH_CLIENT_ID, self::KEY_PRIVATE_VIEWS], true));
        }

        return self::validate($values);
    }

    /** @return array<string, bool> */
    public function getEnvironmentOverrides(): array
    {
        $overrides = [];
        foreach (array_keys(self::defaults()) as $key) {
            $overrides[$key] = $this->config->hasEnvironmentOverride($key, in_array($key, [self::KEY_PROJECT, self::KEY_OAUTH_CLIENT_ID, self::KEY_PRIVATE_VIEWS], true));
        }

        return $overrides;
    }

    /**
     * Validates a complete candidate and writes the values the environment does
     * not control. Nothing is written when any value is invalid.
     *
     * @param array<string, mixed> $candidate
     */
    public function save(array $candidate): void
    {
        if (array_diff_key($candidate, self::defaults()) !== []) {
            throw new \InvalidArgumentException('The form contained an unexpected BigQuery setting. Nothing was saved.');
        }
        $current = $this->toArray();
        $overrides = $this->getEnvironmentOverrides();
        foreach ($overrides as $key => $overridden) {
            if ($overridden) {
                $candidate[$key] = $current[$key];
            }
        }
        $validated = self::validate([...$current, ...$candidate]);
        $values = array_filter($validated, static fn (string $key): bool => !$overrides[$key], ARRAY_FILTER_USE_KEY);
        if ($values !== []) {
            $this->config->setMany($values);
        }
    }

    /** The key file's absolute path; relative paths start at the application folder. */
    public function credentialsPath(?array $settings = null): string
    {
        $file = ($settings ?? $this->toArray())[self::KEY_CREDENTIALS_FILE];

        return str_starts_with($file, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $file) === 1
            ? $file
            : $this->projectDir.'/'.$file;
    }

    /** Whether the admin page may write the key file: only at the default path it manages. */
    public function canStoreKeyFile(): bool
    {
        try {
            return !$this->getEnvironmentOverrides()[self::KEY_CREDENTIALS_FILE]
                && $this->toArray()[self::KEY_CREDENTIALS_FILE] === self::DEFAULT_CREDENTIALS_FILE;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array{bigquery_enabled: bool, bigquery_project_id: string, bigquery_dataset: string, bigquery_location: string, bigquery_auth: string, bigquery_credentials_file: string, bigquery_oauth_client_id: string, bigquery_views: list<string>, bigquery_private_views: list<string>, bigquery_interval_minutes: int}
     */
    public static function validate(array $values): array
    {
        $values = array_replace(self::defaults(), $values);

        $enabled = self::boolean($values[self::KEY_ENABLED]);
        if ($enabled === null) {
            throw new \InvalidArgumentException('bigquery_enabled must be true or false.');
        }

        $project = self::text($values[self::KEY_PROJECT]);
        if ($project === null || ($project !== '' && preg_match('/^(?:[a-z0-9.-]{1,63}:)?[a-z][a-z0-9-]{4,28}[a-z0-9]$/D', $project) !== 1)) {
            throw new \InvalidArgumentException('The Google Cloud project ID must be 6–30 lowercase letters, digits or hyphens, starting with a letter (for example my-analytics-123). Leave it empty to use the project of the key or of the Google Cloud server.');
        }

        $dataset = self::text($values[self::KEY_DATASET]);
        if ($dataset === null || preg_match('/^[A-Za-z0-9_]{1,1024}$/D', $dataset) !== 1) {
            throw new \InvalidArgumentException('The BigQuery dataset name must use letters, digits and underscores, for example aggregate_analytics.');
        }

        $location = self::text($values[self::KEY_LOCATION]);
        if ($location !== null && in_array(strtoupper($location), ['US', 'EU'], true)) {
            $location = strtoupper($location);
        } elseif ($location !== null) {
            $location = strtolower($location);
        }
        if ($location === null || ($location !== 'US' && $location !== 'EU' && preg_match('/^[a-z]+-[a-z]+[0-9]{1,2}$/D', $location) !== 1)) {
            throw new \InvalidArgumentException('The BigQuery location must be US, EU or a region such as europe-west2 or us-central1.');
        }

        $auth = $values[self::KEY_AUTH];
        if (!is_string($auth) || !isset(self::AUTH_METHODS[$auth])) {
            throw new \InvalidArgumentException('bigquery_auth must be service_account, google_cloud or google_sign_in.');
        }

        $credentialsFile = self::text($values[self::KEY_CREDENTIALS_FILE]);
        if ($credentialsFile === null || $credentialsFile === '' || strlen($credentialsFile) > 1024
            || preg_match('/[\x00-\x1F\x7F]/', $credentialsFile) === 1) {
            throw new \InvalidArgumentException('bigquery_credentials_file must be the path of a service account key file.');
        }

        $clientId = self::text($values[self::KEY_OAUTH_CLIENT_ID]);
        if ($clientId === null || ($clientId !== '' && preg_match('/^[A-Za-z0-9-]{1,200}\.apps\.googleusercontent\.com$/D', $clientId) !== 1)) {
            throw new \InvalidArgumentException('The OAuth client ID must end in .apps.googleusercontent.com. Copy it from the Google Cloud console.');
        }

        $views = self::viewList($values[self::KEY_VIEWS], self::KEY_VIEWS);
        foreach ($views as $view) {
            if (BigQueryViewCatalog::isPrivate($view)) {
                throw new \InvalidArgumentException($view.' holds row-level or unsuppressed data. List it under bigquery_private_views to confirm that it may be copied to BigQuery.');
            }
            if (!BigQueryViewCatalog::isApproved($view)) {
                throw new \InvalidArgumentException($view.' is not a reporting view that can be synced to BigQuery.');
            }
        }
        $privateViews = self::viewList($values[self::KEY_PRIVATE_VIEWS], self::KEY_PRIVATE_VIEWS);
        foreach ($privateViews as $view) {
            if (!BigQueryViewCatalog::isPrivate($view)) {
                throw new \InvalidArgumentException($view.' is not a private reporting view. List approved views under bigquery_views.');
            }
        }
        if ($enabled && $views === [] && $privateViews === []) {
            throw new \InvalidArgumentException('Choose at least one view to sync, or turn BigQuery sync off.');
        }

        $interval = $values[self::KEY_INTERVAL];
        if (is_string($interval) && preg_match('/^[0-9]{1,5}$/D', $interval) === 1) {
            $interval = (int) $interval;
        }
        if (!is_int($interval) || !in_array($interval, self::INTERVALS, true)) {
            throw new \InvalidArgumentException('The sync interval must be one of '.implode(', ', self::INTERVALS).' minutes.');
        }

        return [
            self::KEY_ENABLED => $enabled,
            self::KEY_PROJECT => $project,
            self::KEY_DATASET => $dataset,
            self::KEY_LOCATION => $location,
            self::KEY_AUTH => $auth,
            self::KEY_CREDENTIALS_FILE => $credentialsFile,
            self::KEY_OAUTH_CLIENT_ID => $clientId,
            self::KEY_VIEWS => self::inCatalogOrder($views, BigQueryViewCatalog::APPROVED),
            self::KEY_PRIVATE_VIEWS => self::inCatalogOrder($privateViews, BigQueryViewCatalog::PRIVATE),
            self::KEY_INTERVAL => $interval,
        ];
    }

    /** @param array{bigquery_views: list<string>, bigquery_private_views: list<string>} $settings @return list<string> */
    public static function selectedViews(array $settings): array
    {
        return [...$settings[self::KEY_VIEWS], ...$settings[self::KEY_PRIVATE_VIEWS]];
    }

    private static function boolean(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }
        if (is_string($value)) {
            return match (strtolower(trim($value))) {
                '1', 'true', 'yes', 'on' => true,
                '0', 'false', 'no', 'off' => false,
                default => null,
            };
        }

        return null;
    }

    private static function text(mixed $value): ?string
    {
        if ($value === null) {
            return '';
        }

        return is_string($value) ? trim($value) : null;
    }

    /** @return list<string> */
    private static function viewList(mixed $value, string $key): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        if (!is_array($value) || ($value !== [] && !array_is_list($value))) {
            throw new \InvalidArgumentException($key.' must be a list of view names.');
        }
        $views = [];
        foreach ($value as $view) {
            if (!is_string($view)) {
                throw new \InvalidArgumentException($key.' must be a list of view names.');
            }
            $view = trim($view);
            if ($view !== '') {
                $views[$view] = true;
            }
        }

        return array_keys($views);
    }

    /** @param list<string> $views @param array<string, string> $catalog @return list<string> */
    private static function inCatalogOrder(array $views, array $catalog): array
    {
        return array_values(array_filter(array_keys($catalog), static fn (string $view): bool => in_array($view, $views, true)));
    }
}
