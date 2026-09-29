<?php

declare(strict_types=1);

namespace App\Service;

/** Deployment settings shared by source and packaged-release update checks. */
final class UpdateSettings
{
    public const DEFAULT_BRANCH = 'master';
    public const DEFAULT_REPOSITORY = 'Subschema-LLC/aggregate';

    /** Signed release ZIPs: the recommended method for every installation. */
    public const METHOD_RELEASE = 'release';
    /** Fast-forward pulls of a Git clone: the advanced method. */
    public const METHOD_REPOSITORY = 'repository';
    public const METHODS = [self::METHOD_RELEASE, self::METHOD_REPOSITORY];

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

    /**
     * The GitHub repository updates come from, as owner/name. It is YAML-only:
     * changing where application code comes from is a deployment decision, so
     * the dashboard shows it but cannot change it.
     */
    public function repository(): string
    {
        $this->config->assertHealthy();
        $values = $this->config->all();

        return self::validateRepository(array_key_exists('updates_repository', $values) ? $values['updates_repository'] : self::DEFAULT_REPOSITORY);
    }

    public function repositoryUrl(): string
    {
        return 'https://github.com/'.$this->repository();
    }

    public function isOfficialRepository(): bool
    {
        return strcasecmp($this->repository(), self::DEFAULT_REPOSITORY) === 0;
    }

    /**
     * The update method an administrator chose (updates_method), or null when
     * none has been chosen yet and the installation layout decides. An invalid
     * value is an error rather than a silent fallback.
     */
    public function method(): ?string
    {
        $this->config->assertHealthy();
        $values = $this->config->all();

        return array_key_exists('updates_method', $values) ? self::validateMethod($values['updates_method']) : null;
    }

    /** Save the update method chosen on the Updates page or with app:updates:method. */
    public function saveMethod(string $method): void
    {
        $this->config->setMany(['updates_method' => self::validateMethod($method)]);
    }

    /** Save the branch chosen on the Updates page to the active YAML. */
    public function saveBranch(string $branch): void
    {
        $this->config->setMany(['updates_branch' => self::validateBranch($branch)]);
    }

    public static function validateMethod(mixed $method): string
    {
        if (!is_string($method) || !in_array($method, self::METHODS, true)) {
            throw new \InvalidArgumentException('updates_method must be release (release ZIPs, recommended) or repository (a Git clone that pulls from the repository; advanced).');
        }

        return $method;
    }

    public static function validateRepository(mixed $repository): string
    {
        if (!is_string($repository)
            || preg_match('~^[A-Za-z0-9](?:[A-Za-z0-9]|-(?=[A-Za-z0-9])){0,38}/[A-Za-z0-9._-]{1,100}$~D', $repository) !== 1
            || str_ends_with(strtolower($repository), '.git') || str_contains($repository, '..')
            || in_array(explode('/', $repository)[1], ['.', '..'], true)) {
            throw new \InvalidArgumentException('updates_repository must be a GitHub repository in owner/name form, such as Subschema-LLC/aggregate.');
        }

        return $repository;
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
