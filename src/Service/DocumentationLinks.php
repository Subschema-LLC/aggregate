<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Links from the application to the published documentation site.
 *
 * The site's address is the `documentation_url` setting (General settings, YAML,
 * or the DOCUMENTATION_URL environment variable), defaulting to the project's
 * public site. An empty value turns links to external documentation off, for
 * white-labeled or offline installations: url() then returns null, and
 * reference() names the documentation file shipped with the application instead.
 *
 * Callers name a topic rather than building URLs, so page moves are fixed here
 * once. DocumentationLinksTest checks every topic against the site's page list
 * (website/pages.mjs) and the headings of its source file.
 */
final class DocumentationLinks
{
    public const CONFIG_KEY = 'documentation_url';
    public const DEFAULT_URL = 'https://subschema-llc.github.io/aggregate/';
    public const MAX_LENGTH = 2048;

    /**
     * Topic => [site route, with an optional #heading anchor; documentation file in the application folder].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const TOPICS = [
        'home' => ['', 'README.md'],
        'install' => ['install/deployment', 'DEPLOYMENT.md'],
        'install.web-server' => ['install/deployment#web-server-configuration', 'DEPLOYMENT.md'],
        'install.worker' => ['install/deployment#worker-process-setup', 'DEPLOYMENT.md'],
        'install.manual-update' => ['install/deployment#manual-update-steps', 'DEPLOYMENT.md'],
        'tracking.setup' => ['tracking/setup', 'docs/SETUP.md'],
        'tracking.tag-manager' => ['tracking/tag-manager', 'docs/TAG-MANAGER.md'],
        'tracking.consent-manager' => ['tracking/consent-manager', 'docs/CONSENT-MANAGER.md'],
        'tracking.event-examples' => ['tracking/event-examples', 'docs/EVENT-EXAMPLES.md'],
        'tracking.strict' => ['tracking/tracker#strict-collection-profile', 'docs/TRACKING.md'],
        'tracking.attributes' => ['tracking/tracker#track-clicks-and-forms-with-data-attributes', 'docs/TRACKING.md'],
        'consent.regions' => ['tracking/consent-regions', 'docs/CONSENT-REGIONS.md'],
        'consent.standalone' => ['tracking/standalone-consent', 'micro-consent-dropins/README.md'],
        'privacy' => ['privacy/compliance', 'docs/PRIVACY-COMPLIANCE.md'],
        'privacy.measurement' => ['privacy/compliance#measurement-model', 'docs/PRIVACY-COMPLIANCE.md'],
        'privacy.strict' => ['privacy/compliance#strict-collection-profile', 'docs/PRIVACY-COMPLIANCE.md'],
        'privacy.enhanced' => ['privacy/compliance#enhanced-analytics-consent', 'docs/PRIVACY-COMPLIANCE.md'],
        'privacy.utm' => ['privacy/compliance#custom-properties-and-utm-consent', 'docs/PRIVACY-COMPLIANCE.md'],
        'privacy.controls' => ['privacy/compliance#administrative-controls', 'docs/PRIVACY-COMPLIANCE.md'],
        'privacy.organization-traffic' => ['privacy/compliance#organization-traffic', 'docs/PRIVACY-COMPLIANCE.md'],
        'privacy.suppression' => ['privacy/compliance#bi-exposure-and-suppression', 'docs/PRIVACY-COMPLIANCE.md'],
        'reporting.connect' => ['reporting/connect-bi', 'docs/BI-CONNECTION.md'],
        'reporting.custom-views' => ['reporting/connect-bi#custom-reporting-views', 'docs/BI-CONNECTION.md'],
        'reporting.checklist' => ['reporting/connect-bi#connection-checklist', 'docs/BI-CONNECTION.md'],
        'reporting.glossary' => ['reporting/bi-glossary', 'docs/BI-GLOSSARY.md'],
        'reporting.glossary-relationships' => ['reporting/bi-glossary#views-and-bi-relationships', 'docs/BI-GLOSSARY.md'],
        'configuration' => ['configure/configuration', 'docs/CONFIGURATION.md'],
        'configuration.websites' => ['configure/configuration#website-domains', 'docs/CONFIGURATION.md'],
        'configuration.application' => ['configure/configuration#application-settings', 'docs/CONFIGURATION.md'],
        'configuration.lifecycle' => ['configure/configuration#archiving-and-retention', 'docs/CONFIGURATION.md'],
        'data-model' => ['configure/data-model', 'docs/DATA-MODEL.md'],
        'feature-flags' => ['configure/feature-flags', 'docs/FEATURE-FLAGS.md'],
        'updates' => ['operate/updates', 'docs/UPDATES.md'],
        'updates.choose' => ['operate/updates#choose-an-update-method', 'docs/UPDATES.md'],
        'updates.repository' => ['operate/updates#update-from-the-repository-advanced', 'docs/UPDATES.md'],
        'updates.git-clone' => ['operate/updates#set-up-a-git-clone', 'docs/UPDATES.md'],
        'updates.switch' => ['operate/updates#switch-methods', 'docs/UPDATES.md'],
        'updates.deployment' => ['operate/updates#deploy-the-code-another-way', 'docs/UPDATES.md'],
        'releases.install' => ['operate/releases#install-or-deploy-a-verified-package', 'docs/RELEASES.md'],
    ];

    public function __construct(private readonly AggregateConfigLoader $config)
    {
    }

    /**
     * Validate and normalize a documentation site address for saving.
     *
     * Returns '' (links off) for an empty value, or an absolute http(s) URL ending
     * in '/', without credentials, query or fragment.
     *
     * @throws \InvalidArgumentException with a message suitable for the administrator
     */
    public static function normalize(mixed $value): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException('The documentation URL must be text.');
        }

        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (strlen($value) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException(sprintf('The documentation URL must be at most %d characters.', self::MAX_LENGTH));
        }

        $parts = preg_match('/[\x00-\x20\x7f\\\\]/', $value) === 1 ? false : parse_url($value);
        if ($parts === false
            || !in_array(strtolower($parts['scheme'] ?? ''), ['https', 'http'], true)
            || ($parts['host'] ?? '') === '') {
            throw new \InvalidArgumentException('The documentation URL must be a full web address starting with https:// (or http://), or empty to hide documentation links.');
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || str_contains($value, '?') || str_contains($value, '#')) {
            throw new \InvalidArgumentException('The documentation URL must not contain a user name, password, query string or # fragment.');
        }

        return str_ends_with($value, '/') ? $value : $value.'/';
    }

    /** The site's base URL ending in '/', or null when documentation links are off or the setting is invalid. */
    public function baseUrl(): ?string
    {
        try {
            $value = $this->config->getWithEnvFallback(self::CONFIG_KEY, self::DEFAULT_URL, allowEmpty: true);
            $normalized = self::normalize($value);
        } catch (\Throwable) {
            return null;
        }

        return $normalized === '' ? null : $normalized;
    }

    public function isEnabled(): bool
    {
        return $this->baseUrl() !== null;
    }

    /** Whether DOCUMENTATION_URL is set in the environment, which takes precedence over the saved value. */
    public function hasEnvironmentOverride(): bool
    {
        try {
            return $this->config->hasEnvironmentOverride(self::CONFIG_KEY, allowEmpty: true);
        } catch (\Throwable) {
            return false;
        }
    }

    /** The value to show in the settings form: the effective address, or '' when links are off. */
    public function configuredValue(): string
    {
        return $this->baseUrl() ?? '';
    }

    public static function hasTopic(mixed $topic): bool
    {
        return is_string($topic) && isset(self::TOPICS[$topic]);
    }

    /** The topic's page on the documentation site, or null when documentation links are off. */
    public function url(string $topic): ?string
    {
        $route = self::route($topic);
        $base = $this->baseUrl();

        return $base === null ? null : $base.$route;
    }

    /**
     * Where to read about a topic, for command-line and log messages: the site URL
     * when links are on, otherwise the documentation file in the application folder.
     */
    public function reference(string $topic): string
    {
        return $this->url($topic) ?? self::file($topic);
    }

    /** The topic's documentation file in the application folder, such as docs/UPDATES.md. */
    public static function file(string $topic): string
    {
        self::route($topic);

        return self::TOPICS[$topic][1];
    }

    private static function route(string $topic): string
    {
        if (!self::hasTopic($topic)) {
            throw new \InvalidArgumentException(sprintf('Unknown documentation topic "%s".', $topic));
        }

        return self::TOPICS[$topic][0];
    }
}
