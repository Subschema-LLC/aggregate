<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Yaml\Yaml;

/** Independent per-website settings for the downloadable consent banner. */
class StandaloneConsentSettings
{
    public const DEFAULTS = [
        'name' => 'Privacy choices',
        'privacy_policy_url' => '',
        'formspree_endpoint' => '',
        'categories' => ['analytics', 'functional', 'marketing'],
        'respect_gpc' => true,
        'consent_lifetime_days' => 180,
        'revision' => '1',
    ];

    public function __construct(private readonly SiteScriptConfig $sites)
    {
    }

    public function get(string $siteId): array
    {
        $site = $this->sites->site($siteId);
        $configuration = $this->sites->configuration($siteId);
        $configuration->assertHealthy();
        $values = $configuration->all();

        return self::validate(array_key_exists('standalone_consent', $values) ? $values['standalone_consent'] : [], $site['name']);
    }

    public function save(string $siteId, mixed $submitted): array
    {
        $site = $this->sites->site($siteId);
        $normalized = self::validate($submitted, $site['name']);
        $this->sites->ensureDirectory();
        $this->sites->configuration($siteId)->updateMany(static function (array $current) use ($normalized, $site): array {
            // Only this mapping belongs to the standalone banner. Built-in CMP
            // and tag settings remain independent, including their validation.
            self::validate(array_key_exists('standalone_consent', $current) ? $current['standalone_consent'] : [], $site['name']);

            return ['standalone_consent' => $normalized];
        });

        return $normalized;
    }

    public function exportYaml(string $siteId): string
    {
        return Yaml::dump(['standalone_consent' => $this->get($siteId)], 6, 2);
    }

    public function browserConfig(string $siteId): array
    {
        $settings = $this->get($siteId);

        return [
            'name' => $settings['name'],
            'privacyPolicyUrl' => $settings['privacy_policy_url'],
            'formspreeEndpoint' => $settings['formspree_endpoint'],
            'categories' => $settings['categories'],
            'respectGpc' => $settings['respect_gpc'],
            'consentLifetimeDays' => $settings['consent_lifetime_days'],
            'revision' => $settings['revision'],
            'storageKey' => 'micro_consent_v2:'.$siteId,
        ] + ConsentAppearance::browser($settings);
    }

    public static function validate(mixed $submitted, string $defaultName = 'Privacy choices'): array
    {
        if (!is_array($submitted) || ($submitted !== [] && array_is_list($submitted))) {
            throw new \InvalidArgumentException('standalone_consent must be a mapping.');
        }
        foreach (array_diff(array_keys($submitted), [...array_keys(self::DEFAULTS), ...ConsentAppearance::KEYS]) as $key) {
            throw new \InvalidArgumentException('standalone_consent.'.$key.' is not a supported option.');
        }
        $appearance = ConsentAppearance::validate($submitted, ConsentAppearance::STANDALONE, 'standalone_consent');
        $settings = array_diff_key(array_replace(self::DEFAULTS, ['name' => $defaultName], $submitted), array_flip(ConsentAppearance::KEYS));
        foreach (['name' => 120, 'revision' => 64] as $field => $maximum) {
            $settings[$field] = self::text($settings[$field], $field, $maximum, false);
        }
        foreach (['privacy_policy_url', 'formspree_endpoint'] as $field) {
            $settings[$field] = self::text($settings[$field], $field, 2048, true);
        }
        $policy = $settings['privacy_policy_url'];
        if ($policy !== '' && !self::isHttpsUrl($policy)) {
            throw new \InvalidArgumentException('standalone_consent.privacy_policy_url must be empty or an absolute HTTPS URL without credentials.');
        }
        $endpoint = $settings['formspree_endpoint'];
        if ($endpoint !== '' && preg_match('~^https://formspree\.io/f/[A-Za-z0-9]+$~D', $endpoint) !== 1) {
            throw new \InvalidArgumentException('standalone_consent.formspree_endpoint must be empty or https://formspree.io/f/ followed by an alphanumeric form ID, without a query or fragment.');
        }
        if (!is_bool($settings['respect_gpc'])) {
            throw new \InvalidArgumentException('standalone_consent.respect_gpc must be a YAML boolean (true or false).');
        }
        if (!is_int($settings['consent_lifetime_days']) || $settings['consent_lifetime_days'] < 1 || $settings['consent_lifetime_days'] > 365) {
            throw new \InvalidArgumentException('standalone_consent.consent_lifetime_days must be an integer from 1 to 365.');
        }
        $categories = $settings['categories'];
        if (!is_array($categories) || !array_is_list($categories) || count($categories) < 1 || count($categories) > 10) {
            throw new \InvalidArgumentException('standalone_consent.categories must be a list of 1 to 10 categories, including analytics.');
        }
        $seen = [];
        foreach ($categories as $index => $category) {
            if (!is_string($category) || preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $category) !== 1
                || in_array($category, ['none', 'gpc', '__proto__', 'prototype', 'constructor'], true)
                || isset($seen[$category])) {
                throw new \InvalidArgumentException('standalone_consent.categories.'.$index.' must be a unique category of 1–32 lowercase letters, digits, underscores or hyphens, beginning with a letter; none, gpc and prototype keys are reserved.');
            }
            $seen[$category] = true;
        }
        if (!isset($seen['analytics'])) {
            throw new \InvalidArgumentException('standalone_consent.categories must include analytics.');
        }

        return $settings + $appearance;
    }

    private static function text(mixed $value, string $field, int $maximum, bool $allowEmpty): string
    {
        if (!is_string($value) || preg_match('//u', $value) !== 1 || preg_match('/\p{Cc}/u', $value) !== 0
            || strlen(trim($value)) > $maximum || (!$allowEmpty && trim($value) === '')) {
            throw new \InvalidArgumentException('standalone_consent.'.$field.' must be '.($allowEmpty ? 'UTF-8 text' : 'non-empty UTF-8 text').' of at most '.$maximum.' bytes without control characters.');
        }

        return trim($value);
    }

    private static function isHttpsUrl(string $url): bool
    {
        if (str_contains($url, '\\') || preg_match('/\s/u', $url) === 1 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($url);

        return is_array($parts) && strtolower($parts['scheme'] ?? '') === 'https'
            && ($parts['host'] ?? '') !== '' && !isset($parts['user']) && !isset($parts['pass']);
    }
}
