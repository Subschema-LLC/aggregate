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
        return WebsiteConfigManager::idForToken($token);
    }

    /** @return list<array{id:string,name:string,domain:string,token:string}> */
    public function sites(): array
    {
        return $this->websites->sites();
    }

    /** @return array{id:string,name:string,domain:string,token:string} */
    public function site(string $id): array
    {
        if (preg_match('/^[a-f0-9]{24}$/D', $id) !== 1) {
            throw new \InvalidArgumentException('Choose a registered website.');
        }
        $website = $this->websites->findOneById($id)
            ?? throw new \InvalidArgumentException('Choose a registered website with a unique registration token.');

        return ['id' => $id, 'name' => $website['name'], 'domain' => $website['domain'], 'token' => $website['token']];
    }

    public function configuration(string $id): AggregateConfigLoader
    {
        $this->site($id);

        return new AggregateConfigLoader($this->projectDir, $this->environment, 'tag-manager/sites/'.$id);
    }

    /**
     * The website's validated consent_manager settings. Optional keys appear
     * only when configured; respect_gpc only when turned off.
     *
     * @return array{enabled: bool, name: string, privacy_policy_url?: string, precheck_categories?: list<string>, categories?: list<string>, respect_gpc?: false, text?: array, theme?: array<string, string>, buttons?: array{show?: list<string>, reopen?: string}, layout?: string, external_tags?: list<array{id: string, name: string, category: string}>, consent_type?: string, close_button?: string, default_granted?: list<string>, regions?: list<array>, default_region?: string}
     */
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
     * The website's consent_manager settings with $changes applied, checked
     * exactly as saveConsent() checks them, without saving anything.
     */
    public function previewConsent(string $id, array $changes): array
    {
        $site = $this->site($id);
        $defaultEnabled = $this->defaultConsentEnabled();
        self::validateConsent(self::withoutRemovals($changes), $site['name'], $defaultEnabled);
        $config = $this->configuration($id);
        $config->assertHealthy();

        return self::mergeConsent($config->all(), $changes, $site['name'], $defaultEnabled);
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
        foreach (['privacy_policy_url', 'precheck_categories', 'respect_gpc', 'categories', ...ConsentAppearance::KEYS] as $key) {
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
        return \App\Entities\ConsentManager\ConsentManager::validate($settings, $defaultName, $defaultEnabled);
    }
}
