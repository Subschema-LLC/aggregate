<?php

namespace App\Service;

use Symfony\Component\Yaml\Yaml;

/**
 * Manages website configurations in config/websites.yaml.
 * Part of the "fallback to YAML" strategy for a simpler headless solution.
 */
class WebsiteConfigManager
{
    private string $configPath;

    public function __construct(string $projectDir)
    {
        $this->configPath = $projectDir . '/config/websites.yaml';
    }

    public function getWebsites(): array
    {
        if (!file_exists($this->configPath)) {
            return [];
        }

        try {
            $config = Yaml::parseFile($this->configPath);
            $websites = $config['websites'] ?? [];

            if (!is_array($websites)) {
                return [];
            }

            return array_map(function ($site) {
                return [
                    'name' => $site['name'] ?? 'Unnamed',
                    'domain' => $site['domain'] ?? '',
                    'token' => $site['token'] ?? ($site['public_token'] ?? ''),
                ];
            }, $websites);
        } catch (\Exception $e) {
            return [];
        }
    }

    public function findOneByToken(string $token): ?array
    {
        foreach ($this->getWebsites() as $website) {
            if ($website['token'] === $token) {
                return $website;
            }
        }
        return null;
    }

    public function addWebsite(string $name, string $domain, ?string $token = null): bool
    {
        try {
            $websites = $this->getWebsites();

            if (empty($token)) {
                $token = bin2hex(random_bytes(16));
            }

            $websites[] = [
                'name' => $name,
                'domain' => $domain,
                'token' => $token,
            ];

            return $this->save($websites);
        } catch (\Exception $e) {
            return false;
        }
    }

    public function removeWebsite(string $token): bool
    {
        $websites = $this->getWebsites();
        $filtered = array_filter($websites, fn($site) => ($site['token'] ?? '') !== $token);

        if (count($filtered) === count($websites)) {
            return false;
        }

        return $this->save(array_values($filtered));
    }

    private function save(array $websites): bool
    {
        try {
            $config = ['websites' => $websites];
            $yaml = Yaml::dump($config, 4, 2);
            file_put_contents($this->configPath, $yaml);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
