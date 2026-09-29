<?php

declare(strict_types=1);

namespace App\Service;

/** Public installation artifacts; never export the entire application configuration. */
final class DropInScripts
{
    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly WebsiteConfigManager $websites,
        private readonly AppBranding $branding,
        private readonly string $projectDir = __DIR__.'/../..',
        private readonly ?TagManagerSettings $tags = null,
        private readonly ?SiteScriptConfig $sites = null,
        private readonly ?StandaloneConsentSettings $standalone = null,
    ) {
    }

    public function websites(): array
    {
        return array_values(array_filter($this->websites->getWebsites(), static fn (array $site): bool =>
            is_string($site['token'] ?? null) && $site['token'] !== ''
            && is_string($site['name'] ?? null) && is_string($site['domain'] ?? null)));
    }

    public function snippet(string $token, bool $withTags = false, string $format = 'window', string $consentOption = 'builtin'): string
    {
        $this->assertWebsite($token);
        if (!in_array($format, ['window', 'query'], true)) {
            throw new \InvalidArgumentException('Choose window configuration or query parameters.');
        }
        if (!in_array($consentOption, ['builtin', 'standalone', 'external'], true)) {
            throw new \InvalidArgumentException('Choose built-in, standalone, or external consent controls.');
        }
        $host = $this->host();
        $script = '';
        if (!$withTags && $format === 'window') {
            $namespace = $this->json($this->namespace());
            $configuration = $this->json(['endpoint' => $host.'/api/receive', 'websiteToken' => $token, 'consent' => false]);
            $script = "<script>\n  window[".$namespace."] = ".$configuration.";\n</script>\n";
        }
        $siteId = SiteScriptConfig::idForToken($token);
        $urls = [];
        if ($consentOption === 'standalone') {
            $urls[] = $host.'/standalone-cmp/sites/'.$siteId.'/consent.js?min=1';
        } elseif ($consentOption === 'builtin' && ($this->sites === null || $this->sites->consent($siteId)['enabled'])) {
            $urls[] = $host.'/cmp-lite/sites/'.$siteId.'/consent.js?min=1';
        }
        if ($withTags) {
            $urls[] = $host.'/tms-lite/sites/'.$siteId.'/lib.js?min=1';
        } else {
            $urls[] = $format === 'query' ? $this->trackerUrl($token) : $host.'/aggregate.js?min=1';
        }
        foreach ($urls as $url) {
            $url = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $script .= '<script src="'.$url.'" defer referrerpolicy="no-referrer"></script>'."\n";
        }

        return $script;
    }

    /** Configured SDK URL, also usable as a script tag's src in tag-manager YAML. */
    public function trackerUrl(string $token): string
    {
        $this->assertWebsite($token);
        $host = $this->host();

        return $host.'/aggregate.js?'.http_build_query([
            'min' => '1', 'endpoint' => $host.'/api/receive', 'token' => $token, 'consent' => '0',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    private function assertWebsite(string $token): void
    {
        $this->config->assertHealthy();
        if ($this->websites->findOneByToken($token) === null) {
            throw new \InvalidArgumentException('Choose a registered website.');
        }
    }

    /** A loader keeps centrally edited tags current when the installer is hosted elsewhere. */
    public function tagLoader(?string $siteId = null): string
    {
        $this->config->assertHealthy();
        if ($siteId !== null) {
            $this->sites?->site($siteId) ?? throw new \InvalidArgumentException('Choose a registered website.');
        }
        $path = $siteId === null ? '/lib.js' : '/tms-lite/sites/'.$siteId.'/lib.js';
        return "/*! SPDX-License-Identifier: AGPL-3.0-only; see LICENSE in the source repository. */\n"
            ."(function () {\n  'use strict';\n  var current = document.currentScript;\n  var script = document.createElement('script');\n"
            ."  if (current && current.nonce) script.nonce = current.nonce;\n"
            .'  script.src = '.$this->json($this->host().$path.'?min=1').";\n"
            ."  script.referrerPolicy = 'no-referrer';\n  script.async = true;\n  (document.head || document.documentElement).appendChild(script);\n})();\n";
    }

    /** @return array{content: string, minified: bool} */
    public function consentScript(bool $minified = false, ?string $siteId = null): array
    {
        $this->config->assertHealthy();
        $consent = $siteId === null ? ['enabled' => true, 'name' => $this->branding->getName()]
            : ($this->sites?->consent($siteId) ?? throw new \InvalidArgumentException('Choose a registered website.'));
        if (!$consent['enabled']) {
            return ['content' => '/* Built-in consent controls are disabled for this website. */', 'minified' => false];
        }
        $source = @file_get_contents($this->projectDir.'/public/consent.js');
        $styles = @file_get_contents($this->projectDir.'/public/consent.css');
        if (!is_string($source) || !is_string($styles) || trim($styles) === '') {
            throw new \RuntimeException('Consent script source is unavailable.');
        }
        $declaration = "var consentConfig = {namespace: 'Aggregate', name: 'Analytics'};";
        $stylesDeclaration = 'var consentStyles = null;';
        if (substr_count($source, $declaration) !== 1 || substr_count($source, $stylesDeclaration) !== 1) {
            throw new \RuntimeException('Consent script configuration declaration is invalid.');
        }
        $configuration = $this->json([
            'namespace' => $this->namespace(), 'name' => $consent['name'],
            'categories' => $this->tags?->consentCategories($siteId) ?? ['analytics'],
        ] + ($siteId === null ? [] : ['siteId' => $siteId]));
        if ($minified) {
            $directory = $this->projectDir.'/var/browser';
            try {
                $manifest = json_decode((string) @file_get_contents($directory.'/consent-manifest.json'), true, flags: JSON_THROW_ON_ERROR);
                $template = @file_get_contents($directory.'/consent.template.min.js');
                if (is_array($manifest) && ($manifest['format'] ?? null) === 1 && is_string($template)
                    && ($manifest['sourceSha256'] ?? null) === hash('sha256', $source)
                    && ($manifest['stylesheetSha256'] ?? null) === hash('sha256', $styles)
                    && ($manifest['templateSha256'] ?? null) === hash('sha256', $template)
                    && str_contains($template, '__AGGREGATE_CONSENT_CONFIG__')
                    && str_contains($template, '__AGGREGATE_CONSENT_STYLES__')) {
                    return ['content' => strtr($template, [
                        '__AGGREGATE_CONSENT_CONFIG__' => $configuration,
                        '__AGGREGATE_CONSENT_STYLES__' => $this->json($styles),
                    ]), 'minified' => true];
                }
            } catch (\Throwable) {
                // Optional builds fall back to the current configured source.
            }
        }

        return ['content' => strtr($source, [
            $declaration => 'var consentConfig = '.$configuration.';',
            $stylesDeclaration => 'var consentStyles = '.$this->json($styles).';',
        ]), 'minified' => false];
    }

    /** Independent runtime, embedded styles and an explicit Aggregate integration adapter.
     * @return array{content: string, minified: bool}
     */
    public function standaloneConsentScript(bool $minified, string $siteId): array
    {
        $this->config->assertHealthy();
        $configuration = $this->standalone?->browserConfig($siteId)
            ?? throw new \RuntimeException('Standalone consent settings are unavailable.');
        $configuration['aggregateNamespace'] = $this->namespace();
        $directory = $this->projectDir.'/micro-consent-dropins';
        $source = @file_get_contents($directory.'/js/consent-ui.js');
        $adapter = @file_get_contents($directory.'/js/aggregate-consent.js');
        $styles = @file_get_contents($directory.'/css/consent-ui.css');
        $declaration = 'var microConsentStyles = null;';
        if (!is_string($source) || substr_count($source, $declaration) !== 1
            || !is_string($adapter) || trim($adapter) === '' || !is_string($styles) || trim($styles) === '') {
            throw new \RuntimeException('Standalone consent sources are unavailable.');
        }
        $json = $this->json($configuration);
        if ($minified) {
            $build = $this->projectDir.'/var/browser';
            try {
                $manifest = json_decode((string) @file_get_contents($build.'/standalone-consent-manifest.json'), true, flags: JSON_THROW_ON_ERROR);
                $template = @file_get_contents($build.'/standalone-consent.template.min.js');
                if (is_array($manifest) && ($manifest['format'] ?? null) === 1 && is_string($template)
                    && ($manifest['sourceSha256'] ?? null) === hash('sha256', $source)
                    && ($manifest['adapterSha256'] ?? null) === hash('sha256', $adapter)
                    && ($manifest['stylesheetSha256'] ?? null) === hash('sha256', $styles)
                    && ($manifest['templateSha256'] ?? null) === hash('sha256', $template)
                    && str_contains($template, '__MICRO_CONSENT_CONFIG__') && str_contains($template, '__MICRO_CONSENT_STYLES__')) {
                    return ['content' => strtr($template, [
                        '__MICRO_CONSENT_CONFIG__' => $json,
                        '__MICRO_CONSENT_STYLES__' => $this->json($styles),
                    ]), 'minified' => true];
                }
            } catch (\Throwable) {
                // A build is optional; stale or incomplete builds use current source.
            }
        }

        return ['content' => 'window.MicroConsentConfig = '.$json.";\n"
            .str_replace($declaration, 'var microConsentStyles = '.$this->json($styles).';', $source)
            ."\n".$adapter, 'minified' => false];
    }

    private function namespace(): string
    {
        $namespace = $this->config->getWithEnvFallback('js_namespace', 'Aggregate');
        if (!is_string($namespace) || $namespace === '') {
            throw new \RuntimeException('The tracker namespace is invalid.');
        }

        return $namespace;
    }

    private function host(): string
    {
        $host = $this->config->getWithEnvFallback('app_host', 'http://localhost');
        $parts = is_string($host) ? parse_url($host) : false;
        if (!is_array($parts) || !in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || preg_match('/[\x00-\x20\x7f]/', $host)) {
            throw new \RuntimeException('Set a valid application host URL in general settings.');
        }

        return rtrim($host, '/');
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
