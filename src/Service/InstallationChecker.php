<?php

namespace App\Service;

use App\Repository\UserRepository;

class InstallationChecker
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly AggregateConfigLoader $config,
    ) {}

    public function isInstalled(): bool
    {
        try {
            // Check if any users exist in the database
            $userCount = $this->userRepository->count([]);
            return $userCount > 0;
        } catch (\Exception $e) {
            // If database is not set up, consider it not installed
            return false;
        }
    }

    public function isConfigValid(): bool
    {
        try {
            // Check if required config values exist
            $adminUsername = $this->config->getWithEnvFallback('admin_username', null);
            $adminPassword = $this->config->getWithEnvFallback('admin_password', null);
            $dailySalt = $this->config->getWithEnvFallback('daily_salt_secret', null);

            return !empty($adminUsername) && !empty($adminPassword) && !empty($dailySalt);
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getConfigErrors(): array
    {
        $errors = [];

        try {
            if (empty($this->config->getWithEnvFallback('admin_username', null))) {
                $errors[] = 'admin_username is not configured in config/aggregate.yaml';
            }
            if (empty($this->config->getWithEnvFallback('admin_password', null))) {
                $errors[] = 'admin_password is not configured in config/aggregate.yaml';
            }
            if (empty($this->config->getWithEnvFallback('daily_salt_secret', null))) {
                $errors[] = 'daily_salt_secret is not configured in config/aggregate.yaml';
            }
        } catch (\Exception $e) {
            $errors[] = 'config/aggregate.yaml file is missing or invalid';
        }

        return $errors;
    }
}
