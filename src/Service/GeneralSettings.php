<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The General settings page's application settings, for the page and
 * app:config alike: the installation's address, the collection address,
 * the tracker's namespace, the rate limit, the website activity thresholds
 * and the documentation address.
 */
final class GeneralSettings
{
    public const KEYS = [
        'app_host',
        CollectionAddress::CONFIG_KEY,
        'js_namespace',
        'rate_limit_per_minute',
        'website_activity_active_days',
        'website_activity_stale_days',
        DocumentationLinks::CONFIG_KEY,
    ];

    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly DocumentationLinks $documentation,
    ) {
    }

    /**
     * The values in effect, as the page shows them.
     *
     * @return array<string, mixed>
     */
    public function values(): array
    {
        return [
            'app_host' => $this->config->getWithEnvFallback('app_host', 'http://localhost:8000'),
            CollectionAddress::CONFIG_KEY => (new CollectionAddress($this->config))->configuredValue(),
            'js_namespace' => TrackerNamespace::of($this->config),
            'rate_limit_per_minute' => $this->config->getWithEnvFallback('rate_limit_per_minute', 100),
            'website_activity_active_days' => (int) $this->config->getWithEnvFallback('website_activity_active_days', 1),
            'website_activity_stale_days' => (int) $this->config->getWithEnvFallback('website_activity_stale_days', 3),
            DocumentationLinks::CONFIG_KEY => $this->documentation->configuredValue(),
        ];
    }

    /**
     * Settings whose environment variable takes precedence: the page disables
     * their fields, and their submitted values are not saved.
     *
     * @return list<string>
     */
    public function environmentOverrides(): array
    {
        return array_values(array_filter([
            (new CollectionAddress($this->config))->hasEnvironmentOverride() ? CollectionAddress::CONFIG_KEY : null,
            $this->documentation->hasEnvironmentOverride() ? DocumentationLinks::CONFIG_KEY : null,
        ]));
    }

    /**
     * The YAML values a submission saves, checked as the page checks them.
     * The installation's address, namespace, rate limit and thresholds are
     * always saved; the collection and documentation addresses only when
     * submitted, changed and not set by the environment, so saving does not
     * pin their defaults.
     *
     * @param array<string, mixed> $submitted
     *
     * @return array<string, mixed>
     */
    public function changes(array $submitted): array
    {
        $appHost = trim((string) ($submitted['app_host'] ?? ''));
        $jsNamespace = trim((string) ($submitted['js_namespace'] ?? 'Aggregate'));
        $rateLimit = $submitted['rate_limit_per_minute'] ?? $submitted['rate_limit'] ?? 100;
        $activeDays = max(1, min(365, (int) ($submitted['website_activity_active_days'] ?? 1)));
        $staleDays = max($activeDays, min(365, (int) ($submitted['website_activity_stale_days'] ?? 3)));

        if ($appHost === '') {
            throw new \InvalidArgumentException('App Host is required.');
        }
        if (!TrackerNamespace::valid($jsNamespace)) {
            throw new \InvalidArgumentException(TrackerNamespace::RULE);
        }
        // The field's minimum is 1: a limit of 0 would refuse every event.
        if (!is_int($rateLimit) && !(is_string($rateLimit) && preg_match('/^\s*[0-9]+\s*$/D', $rateLimit) === 1) || (int) $rateLimit < 1) {
            throw new \InvalidArgumentException('The rate limit must be a whole number of events per minute, at least 1.');
        }
        $rateLimit = (int) $rateLimit;

        $settings = [
            'app_host' => $appHost,
            'js_namespace' => $jsNamespace,
            'rate_limit_per_minute' => $rateLimit,
            'website_activity_active_days' => $activeDays,
            'website_activity_stale_days' => $staleDays,
        ];

        // COLLECTION_BASE_URL takes precedence and its field is disabled. Empty
        // means the application host, so clearing the field saves ''.
        $collection = new CollectionAddress($this->config);
        if (!$collection->hasEnvironmentOverride() && array_key_exists(CollectionAddress::CONFIG_KEY, $submitted)) {
            $collectionUrl = CollectionAddress::normalize($submitted[CollectionAddress::CONFIG_KEY]);
            if ($collectionUrl !== $collection->configuredValue()) {
                $settings[CollectionAddress::CONFIG_KEY] = $collectionUrl;
            }
        }

        // DOCUMENTATION_URL takes precedence and its field is disabled. Save only a
        // changed value, so saving other settings does not pin the default address.
        if (!$this->documentation->hasEnvironmentOverride() && array_key_exists(DocumentationLinks::CONFIG_KEY, $submitted)) {
            $documentationUrl = DocumentationLinks::normalize($submitted[DocumentationLinks::CONFIG_KEY]);
            if ($documentationUrl !== $this->documentation->configuredValue()) {
                $settings[DocumentationLinks::CONFIG_KEY] = $documentationUrl;
            }
        }

        return $settings;
    }

    /** @param array<string, mixed> $changes as changes() returns them */
    public function save(array $changes): void
    {
        $this->config->setMany($changes);
    }
}
