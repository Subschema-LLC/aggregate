<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Yaml\Yaml;

/** Registered websites own independent YAML script instances, never database rows. */
final class SiteScriptConfig
{
    public function __construct(
        private readonly WebsiteConfigManager $websites,
        private readonly string $projectDir,
        private readonly string $environment,
        private readonly ?AggregateConfigLoader $appConfig = null,
    ) {
    }

    public function defaultConsentEnabled(): bool
    {
        try {
            $appConfig = $this->appConfig ?? new AggregateConfigLoader($this->projectDir, $this->environment);
            if ($appConfig->has('consent_manager')) {
                $cm = $appConfig->get('consent_manager');
                if (is_array($cm) && array_key_exists('enabled', $cm) && is_bool($cm['enabled'])) {
                    return $cm['enabled'];
                }
            }
        } catch (\Throwable) {
        }

        return true;
    }

    public static function idForToken(string $token): string
    {
        return substr(hash('sha256', $token), 0, 24);
    }

    /** @return list<array{id:string,name:string,domain:string,token:string}> */
    public function sites(): array
    {
        $sites = [];
        foreach ($this->websites->getWebsites() as $website) {
            if (!is_string($website['token'] ?? null) || $website['token'] === '') {
                continue;
            }
            $sites[] = ['id' => self::idForToken($website['token']), 'name' => $website['name'], 'domain' => $website['domain'], 'token' => $website['token']];
        }

        return $sites;
    }

    public function site(string $id): array
    {
        if (preg_match('/^[a-f0-9]{24}$/D', $id) !== 1) {
            throw new \InvalidArgumentException('Choose a registered website.');
        }
        $matches = array_values(array_filter($this->sites(), static fn (array $site): bool => $site['id'] === $id));
        if (count($matches) !== 1) {
            throw new \InvalidArgumentException('Choose a registered website with a unique registration token.');
        }

        return $matches[0];
    }

    public function configuration(string $id): AggregateConfigLoader
    {
        $this->site($id);

        return new AggregateConfigLoader($this->projectDir, $this->environment, 'tag-manager/sites/'.$id);
    }

    /** @return array{enabled: bool, name: string, privacy_policy_url?: string, text?: array, theme?: array, buttons?: array} */
    public function consent(string $id): array
    {
        $site = $this->site($id);
        $config = $this->configuration($id);
        $config->assertHealthy();
        $values = $config->all();

        return self::validateConsent(
            array_key_exists('consent_manager', $values) ? $values['consent_manager'] : [],
            $site['name'],
            $this->defaultConsentEnabled(),
        );
    }

    /**
     * Changes the supplied consent_manager keys: each supplied key replaces its
     * saved value, null removes an optional key (restoring its default), and
     * omitted keys keep their saved values.
     */
    public function saveConsent(string $id, array $consent): void
    {
        $site = $this->site($id);
        $defaultEnabled = $this->defaultConsentEnabled();
        self::validateConsent(self::withoutRemovals($consent), $site['name'], $defaultEnabled);
        $this->ensureDirectory();
        $this->configuration($id)->updateMany(static function (array $current) use ($consent, $site, $defaultEnabled): array {
            TagManagerSettings::validate(array_key_exists('tag_manager', $current) ? $current['tag_manager'] : TagManagerSettings::DEFAULTS);

            return ['consent_manager' => self::mergeConsent($current, $consent, $site['name'], $defaultEnabled)];
        });
    }

    /**
     * Save the two related settings atomically without replacing unrelated YAML.
     * Consent keys missing from $consent keep their saved values.
     */
    public function save(string $id, array $tags, array $consent): void
    {
        $site = $this->site($id);
        $tags = TagManagerSettings::validate($tags);
        $defaultEnabled = $this->defaultConsentEnabled();
        self::validateConsent(self::withoutRemovals($consent), $site['name'], $defaultEnabled);
        $this->ensureDirectory();
        $this->configuration($id)->updateMany(static function (array $current) use ($tags, $consent, $site, $defaultEnabled): array {
            TagManagerSettings::validate(array_key_exists('tag_manager', $current) ? $current['tag_manager'] : TagManagerSettings::DEFAULTS);

            return ['tag_manager' => $tags, 'consent_manager' => self::mergeConsent($current, $consent, $site['name'], $defaultEnabled)];
        });
    }

    /** The saved consent_manager with $changes applied, validated as a whole. */
    private static function mergeConsent(array $current, array $changes, string $defaultName, bool $defaultEnabled = true): array
    {
        $saved = self::validateConsent(array_key_exists('consent_manager', $current) ? $current['consent_manager'] : [], $defaultName, $defaultEnabled);

        return self::validateConsent(self::withoutRemovals(array_replace($saved, $changes)), $defaultName, $defaultEnabled);
    }

    /** Drops optional keys set to null; a null enabled or name stays invalid. */
    private static function withoutRemovals(array $consent): array
    {
        foreach (['privacy_policy_url', 'precheck_categories', ...ConsentAppearance::KEYS] as $key) {
            if (array_key_exists($key, $consent) && $consent[$key] === null) {
                unset($consent[$key]);
            }
        }

        return $consent;
    }

    public function ensureDirectory(): void
    {
        $directory = $this->projectDir.'/config/tag-manager/sites';
        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException('The website script configuration directory is not writable.');
        }
    }

    /** Export only this site's active public script settings. */
    public function export(string $id): string
    {
        $site = $this->site($id);
        $config = $this->configuration($id);
        $config->assertHealthy();
        $values = $config->all();

        return Yaml::dump([
            'tag_manager' => TagManagerSettings::validate(array_key_exists('tag_manager', $values) ? $values['tag_manager'] : TagManagerSettings::DEFAULTS),
            'consent_manager' => self::validateConsent(array_key_exists('consent_manager', $values) ? $values['consent_manager'] : [], $site['name'], $this->defaultConsentEnabled()),
        ], 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    }

    private static function validateConsent(mixed $settings, string $defaultName, bool $defaultEnabled = true): array
    {
        $supported = ['enabled', 'name', 'privacy_policy_url', 'precheck_categories', ...ConsentAppearance::KEYS];
        if (!is_array($settings) || ($settings !== [] && array_is_list($settings)) || array_diff(array_keys($settings), $supported) !== []) {
            throw new \InvalidArgumentException('Consent manager settings support only '.implode(', ', $supported).'.');
        }
        $enabled = array_key_exists('enabled', $settings) ? $settings['enabled'] : $defaultEnabled;
        $name = array_key_exists('name', $settings) ? $settings['name'] : $defaultName;
        if (!is_bool($enabled) || !is_string($name) || trim($name) === '' || strlen($name) > 120
            || preg_match('//u', $name) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $name) === 1) {
            throw new \InvalidArgumentException('Consent controls need a YAML boolean enabled setting and a name of 1–120 UTF-8 bytes without control characters.');
        }

        $result = ['enabled' => $enabled, 'name' => trim($name)];
        if (array_key_exists('privacy_policy_url', $settings)) {
            $result['privacy_policy_url'] = ConsentAppearance::privacyPolicyUrl($settings['privacy_policy_url'], 'consent_manager.privacy_policy_url');
        }
        if (array_key_exists('precheck_categories', $settings)) {
            $precheck = $settings['precheck_categories'];
            if (!is_array($precheck) || ($precheck !== [] && !array_is_list($precheck))) {
                throw new \InvalidArgumentException('consent_manager.precheck_categories must be a list of category names.');
            }
            $clean = [];
            foreach ($precheck as $cat) {
                if (!is_string($cat) || preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $cat) !== 1 || in_array($cat, ['none', 'gpc'], true)) {
                    throw new \InvalidArgumentException('consent_manager.precheck_categories entries must be category names.');
                }
                if (!in_array($cat, $clean, true)) {
                    $clean[] = $cat;
                }
            }
            if ($clean !== []) {
                $result['precheck_categories'] = $clean;
            }
        }

        return $result + ConsentAppearance::validate($settings, ConsentAppearance::BUILTIN, 'consent_manager');
    }
}
