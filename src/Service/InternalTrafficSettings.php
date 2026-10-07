<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The organization-traffic marker: a cookie or local-storage entry that a
 * team member's browser holds on each tracked website. The tracker reports it
 * on every event as org_internal_traffic (JSON_KEY), true or false; the
 * browser-side name and value are configured separately. The marker is a
 * reporting hint, never an authentication credential.
 */
final class InternalTrafficSettings
{
    /** The fixed key in the tracker's payload and in events.custom_data. */
    public const JSON_KEY = 'org_internal_traffic';

    public const DEFAULTS = [
        'internal_traffic_storage' => 'cookie',
        'internal_traffic_name' => 'orgInternalTraffic',
        'internal_traffic_value' => 'true',
        'internal_traffic_cookie_domain' => '',
    ];
    public const TOKEN_KEY = 'internal_traffic_share_token';

    public function __construct(private readonly AggregateConfigLoader $config)
    {
    }

    /** @return array{storage: string, name: string, value: string, cookieDomain: string} */
    public function toBrowserConfig(): array
    {
        $settings = $this->markerSettings();

        return [
            'storage' => $settings['internal_traffic_storage'],
            'name' => $settings['internal_traffic_name'],
            'value' => $settings['internal_traffic_value'],
            'cookieDomain' => $settings['internal_traffic_cookie_domain'],
        ];
    }

    /** @return array<string, string> */
    public function markerSettings(): array
    {
        $this->config->assertHealthy();
        $values = [];
        foreach (self::DEFAULTS as $key => $default) {
            $values[$key] = $this->config->getWithEnvFallback($key, $default, true);
        }

        return self::validateMarker($values);
    }

    /** @return array<string, bool> */
    public function getEnvironmentOverrides(): array
    {
        $overrides = [];
        foreach ([...array_keys(self::DEFAULTS), self::TOKEN_KEY] as $key) {
            $overrides[$key] = $this->config->hasEnvironmentOverride($key, true);
        }

        return $overrides;
    }

    /** @param array<string, mixed> $submitted */
    public function saveMarker(array $submitted): void
    {
        if (array_diff_key($submitted, self::DEFAULTS) !== []) {
            throw new \InvalidArgumentException('The form contained an unexpected marker setting. Nothing was saved.');
        }

        $candidate = $this->markerSettings();
        $overrides = $this->getEnvironmentOverrides();
        foreach (self::DEFAULTS as $key => $default) {
            if ($overrides[$key]) {
                continue;
            }
            if (!array_key_exists($key, $submitted)) {
                throw new \InvalidArgumentException('Every marker setting not controlled by the environment must be submitted.');
            }
            $candidate[$key] = $submitted[$key];
        }

        $validated = self::validateMarker($candidate);
        $updates = array_filter(
            $validated,
            static fn (string $key): bool => !$overrides[$key],
            ARRAY_FILTER_USE_KEY,
        );
        $this->config->setMany($updates);
    }

    public function getShareToken(): string
    {
        $this->config->assertHealthy();
        $token = $this->config->getWithEnvFallback(self::TOKEN_KEY, '', true);
        if (!is_string($token) || ($token !== '' && preg_match('/^[A-Za-z0-9_-]{32,128}$/D', $token) !== 1)) {
            throw new \InvalidArgumentException('internal_traffic_share_token must be empty or contain 32–128 URL-safe letters, digits, underscores or hyphens. Generate it with the share-link button or a cryptographically secure random generator.');
        }

        return $token;
    }

    public function matchesShareToken(string $provided): bool
    {
        try {
            $token = $this->getShareToken();
        } catch (\Throwable) {
            return false;
        }

        return $token !== '' && hash_equals($token, $provided);
    }

    public function rotateShareToken(): string
    {
        $this->assertTokenWritable();
        $token = bin2hex(random_bytes(32));
        $this->config->set(self::TOKEN_KEY, $token);

        return $token;
    }

    /** Called only during initial installation; explicit environment values are preserved. */
    public function ensureShareToken(): string
    {
        $token = $this->getShareToken();
        if ($token !== '' || $this->config->hasEnvironmentOverride(self::TOKEN_KEY, true)) {
            return $token;
        }

        return $this->rotateShareToken();
    }

    public function revokeShareToken(): void
    {
        $this->assertTokenWritable();
        $this->config->set(self::TOKEN_KEY, '');
    }

    private function assertTokenWritable(): void
    {
        if ($this->config->hasEnvironmentOverride(self::TOKEN_KEY, true)) {
            throw new \InvalidArgumentException('The share token is controlled by INTERNAL_TRAFFIC_SHARE_TOKEN. Change or remove that environment variable to rotate or revoke the link.');
        }
    }

    /** @param array<string, mixed> $values
     *  @return array<string, string>
     */
    public static function validateMarker(array $values): array
    {
        $values = array_replace(self::DEFAULTS, $values);
        $storage = $values['internal_traffic_storage'];
        if (!in_array($storage, ['cookie', 'local_storage'], true)) {
            throw new \InvalidArgumentException('internal_traffic_storage must be cookie or local_storage.');
        }

        $name = $values['internal_traffic_name'];
        if (!is_string($name) || preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $name) !== 1
            || preg_match('/^-?[0-9]+$/D', $name) === 1
            || in_array($name, ['aggregate_session', 'aggregate_visitor_id', 'aggregate_session_id'], true)) {
            throw new \InvalidArgumentException('The marker name must contain 1–128 letters, digits, underscores or hyphens, must not be numeric, and must not use an Aggregate identifier name.');
        }

        $value = $values['internal_traffic_value'];
        if (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        }
        if (!is_string($value) || $value === '' || strlen($value) > 256
            || preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new \InvalidArgumentException('The marker value must be a non-empty string of at most 256 bytes without control characters.');
        }

        $domain = $values['internal_traffic_cookie_domain'];
        if (!is_string($domain) || strlen($domain) > 253
            || ($domain !== '' && preg_match('/^\.?(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)*[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/D', $domain) !== 1)) {
            throw new \InvalidArgumentException('The cookie domain must be empty or a hostname, optionally beginning with a dot; omit its scheme, port and path.');
        }
        if ($storage === 'cookie' && str_starts_with($name, '__Host-') && $domain !== '') {
            throw new \InvalidArgumentException('A __Host- cookie cannot have a cookie domain.');
        }

        return [
            'internal_traffic_storage' => $storage,
            'internal_traffic_name' => $name,
            'internal_traffic_value' => $value,
            'internal_traffic_cookie_domain' => strtolower($domain),
        ];
    }
}
