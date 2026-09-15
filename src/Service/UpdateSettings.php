<?php

declare(strict_types=1);

namespace App\Service;

/** Deployment settings shared by source and packaged-release update checks. */
final class UpdateSettings
{
    public const DEFAULT_BRANCH = 'master';

    public function __construct(private readonly AggregateConfigLoader $config)
    {
    }

    public function branch(): string
    {
        $this->config->assertHealthy();
        $values = $this->config->all();
        // An explicitly empty/null value is an error, not an implicit opt-in to master.
        $branch = array_key_exists('updates_branch', $values) ? $values['updates_branch'] : self::DEFAULT_BRANCH;

        return self::validateBranch($branch);
    }

    /** Validate a branch without requiring Git on archive installations. */
    public static function validateBranch(mixed $branch): string
    {
        if (!is_string($branch) || $branch === '' || strlen($branch) > 255
            || in_array($branch, ['HEAD', '@'], true)
            || str_starts_with($branch, '-') || str_ends_with($branch, '.')
            || preg_match('/[\x00-\x20\x7F~^:?*\[\\\\]/', $branch) === 1
            || str_contains($branch, '..') || str_contains($branch, '@{')) {
            throw new \InvalidArgumentException('updates_branch must be a valid Git branch name of 1–255 bytes, such as master or releases/stable.');
        }

        foreach (explode('/', $branch) as $component) {
            if ($component === '' || str_starts_with($component, '.') || str_ends_with($component, '.lock')) {
                throw new \InvalidArgumentException('updates_branch must be a valid Git branch name of 1–255 bytes, such as master or releases/stable.');
            }
        }

        return $branch;
    }
}
