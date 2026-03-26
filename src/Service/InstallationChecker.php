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

    /**
     * Config is valid when DATABASE_URL is available as a standard env var.
     * Doctrine will fail to boot if it is missing, so this is the right gate.
     */
    public function isConfigValid(): bool
    {
        return !empty($_ENV['DATABASE_URL'] ?? $_SERVER['DATABASE_URL'] ?? '');
    }

    public function getConfigErrors(): array
    {
        $errors = [];
        if (empty($_ENV['DATABASE_URL'] ?? $_SERVER['DATABASE_URL'] ?? '')) {
            $errors[] = 'DATABASE_URL is not set. Add it to your .env file (see .env.dev for an example).';
        }
        return $errors;
    }
}
