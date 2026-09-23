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
    ) {
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

    /** @return array{enabled:bool,name:string} */
    public function consent(string $id): array
    {
        $site = $this->site($id);
        $config = $this->configuration($id);
        $config->assertHealthy();
        $values = $config->all();

        return self::validateConsent(array_key_exists('consent_manager', $values) ? $values['consent_manager'] : [], $site['name']);
    }

    public function saveConsent(string $id, array $consent): void
    {
        $site = $this->site($id);
        $consent = self::validateConsent($consent, $site['name']);
        $this->ensureDirectory();
        $this->configuration($id)->updateMany(static function (array $current) use ($consent, $site): array {
            TagManagerSettings::validate(array_key_exists('tag_manager', $current) ? $current['tag_manager'] : TagManagerSettings::DEFAULTS);
            self::validateConsent(array_key_exists('consent_manager', $current) ? $current['consent_manager'] : [], $site['name']);

            return ['consent_manager' => $consent];
        });
    }

    /** Save the two related settings atomically without replacing unrelated YAML. */
    public function save(string $id, array $tags, array $consent): void
    {
        $site = $this->site($id);
        $tags = TagManagerSettings::validate($tags);
        $consent = self::validateConsent($consent, $site['name']);
        $this->ensureDirectory();
        $this->configuration($id)->updateMany(static function (array $current) use ($tags, $consent, $site): array {
            TagManagerSettings::validate(array_key_exists('tag_manager', $current) ? $current['tag_manager'] : TagManagerSettings::DEFAULTS);
            self::validateConsent(array_key_exists('consent_manager', $current) ? $current['consent_manager'] : [], $site['name']);

            return ['tag_manager' => $tags, 'consent_manager' => $consent];
        });
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
            'consent_manager' => self::validateConsent(array_key_exists('consent_manager', $values) ? $values['consent_manager'] : [], $site['name']),
        ], 10, 2);
    }

    private static function validateConsent(mixed $settings, string $defaultName): array
    {
        if (!is_array($settings) || array_diff(array_keys($settings), ['enabled', 'name']) !== []) {
            throw new \InvalidArgumentException('Consent manager settings support only enabled and name.');
        }
        $enabled = array_key_exists('enabled', $settings) ? $settings['enabled'] : true;
        $name = array_key_exists('name', $settings) ? $settings['name'] : $defaultName;
        if (!is_bool($enabled) || !is_string($name) || trim($name) === '' || strlen($name) > 120
            || preg_match('//u', $name) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $name) === 1) {
            throw new \InvalidArgumentException('Consent controls need a YAML boolean enabled setting and a name of 1–120 UTF-8 bytes without control characters.');
        }

        return ['enabled' => $enabled, 'name' => trim($name)];
    }
}
