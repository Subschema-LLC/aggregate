<?php

namespace App\Service;

use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\TableNotFoundException;

class InstallationChecker
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly AggregateConfigLoader $config,
    ) {}

    public function isInstalled(): bool
    {
        // A broken configuration must not reopen the public setup flow.
        $this->config->assertHealthy();

        if ($this->config->get('installed') === true) {
            return true;
        }

        try {
            return $this->userRepository->count([]) > 0;
        } catch (TableNotFoundException) {
            // A fresh database has no users table until migrations run. Other
            // failures (connectivity, permissions, etc.) must stop setup.
            return false;
        }
    }

    /**
     * Config is valid when DATABASE_URL is available as a standard env var.
     * Doctrine will fail to boot if it is missing, so this is the right gate.
     */
    public function isConfigValid(): bool
    {
        return !empty($_ENV['DATABASE_URL'] ?? $_SERVER['DATABASE_URL'] ?? '') &&
               !empty($_ENV['APP_SECRET'] ?? $_SERVER['APP_SECRET'] ?? '');
    }

    public function getConfigErrors(): array
    {
        $errors = [];
        if (empty($_ENV['DATABASE_URL'] ?? $_SERVER['DATABASE_URL'] ?? '')) {
            $errors[] = 'DATABASE_URL is not set. Add it to your .env file.';
        }
        if (empty($_ENV['APP_SECRET'] ?? $_SERVER['APP_SECRET'] ?? '')) {
            $errors[] = 'APP_SECRET is not set. Generate one with "openssl rand -hex 32" and add it to your .env file.';
        }
        return $errors;
    }
}
