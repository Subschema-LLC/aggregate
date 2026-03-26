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
            return $this->userRepository->count([]) > 0;
        } catch (\Exception) {
            return false;
        }
    }

    public function isConfigValid(): bool
    {
        try {
            return !empty($this->config->getWithEnvFallback('database_url', null));
        } catch (\Exception) {
            return false;
        }
    }

    public function getConfigErrors(): array
    {
        $errors = [];
        try {
            if (empty($this->config->getWithEnvFallback('database_url', null))) {
                $errors[] = 'database_url is not configured in config/aggregate.yaml';
            }
        } catch (\Exception) {
            $errors[] = 'config/aggregate.yaml file is missing or invalid';
        }
        return $errors;
    }
}
