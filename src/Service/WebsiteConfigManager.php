<?php

namespace App\Service;

use Symfony\Component\Yaml\Yaml;

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

            // Ensure each website has required fields
            return array_map(function ($site) {
                return [
                    'name' => $site['name'] ?? 'Unnamed',
                    'domain' => $site['domain'] ?? '',
                    'token' => $site['token'] ?? '',
                ];
            }, $websites);
        } catch (\Exception $e) {
            return [];
        }
    }

    public function addWebsite(string $name, string $domain, ?string $token = null): bool
    {
        try {
            // Load existing config or create new
            $config = file_exists($this->configPath)
                ? Yaml::parseFile($this->configPath)
                : ['websites' => []];

            if (!isset($config['websites'])) {
                $config['websites'] = [];
            }

            // Generate token if not provided
            if (empty($token)) {
                $token = bin2hex(random_bytes(16));
            }

            // Add new website
            $config['websites'][] = [
                'name' => $name,
                'domain' => $domain,
                'token' => $token,
            ];

            // Write back to file
            $yaml = Yaml::dump($config, 4, 2);
            file_put_contents($this->configPath, $yaml);

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function removeWebsite(string $token): bool
    {
        if (!file_exists($this->configPath)) {
            return false;
        }

        try {
            $config = Yaml::parseFile($this->configPath);

            if (!isset($config['websites'])) {
                return false;
            }

            $websites = $config['websites'];
            $filtered = array_filter($websites, fn($site) => ($site['token'] ?? '') !== $token);

            if (count($filtered) === count($websites)) {
                return false; // Website not found
            }

            $config['websites'] = array_values($filtered);

            // Write back to file
            $yaml = Yaml::dump($config, 4, 2);
            file_put_contents($this->configPath, $yaml);

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
