<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The address websites send collection requests to: `collection_base_url`
 * in YAML, or the COLLECTION_BASE_URL environment variable.
 *
 * Generated snippets, the tracker's endpoint, the consent and tag-manager
 * script addresses and the organization-traffic pages use it. Empty, the
 * default, means the installation's own address (`app_host`), as before. Set
 * it when collection runs on another hostname, such as a tracking subdomain
 * or the balancer in front of several collection servers. Everything else
 * (sign-in links, alerts, the dashboard) keeps using `app_host`.
 */
final class CollectionAddress
{
    public const CONFIG_KEY = 'collection_base_url';
    public const RULE = 'The collection address must be a full http or https URL without a user name, query or fragment, such as https://collect.example.com, or empty to use the application host.';

    public function __construct(private readonly AggregateConfigLoader $config)
    {
    }

    /** The saved or environment value, '' when it is not set. */
    public function configuredValue(): string
    {
        $value = $this->config->getWithEnvFallback(self::CONFIG_KEY, '');

        return is_string($value) ? trim($value) : '';
    }

    public function hasEnvironmentOverride(): bool
    {
        return $this->config->hasEnvironmentOverride(self::CONFIG_KEY);
    }

    /**
     * The base URL without a trailing slash: the collection address when it
     * is set, otherwise `app_host` (or $appHostDefault when that is not set).
     * Null when the value in use is not a plain http(s) URL. A collection
     * address that is set but invalid never falls back to `app_host`, so a
     * typo cannot send snippets to the administration hostname.
     */
    public function baseUrl(string $appHostDefault = ''): ?string
    {
        $configured = $this->configuredValue();
        if ($configured !== '') {
            return DropInScripts::normalizeAppHost($configured);
        }

        return DropInScripts::normalizeAppHost($this->config->getWithEnvFallback('app_host', $appHostDefault));
    }

    /** The value to save: '' for empty, else the URL without a trailing slash. */
    public static function normalize(mixed $value): string
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '') {
            return '';
        }

        return DropInScripts::normalizeAppHost($value) ?? throw new \InvalidArgumentException(self::RULE);
    }
}
