<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;

/**
 * `%env(project_dir:NAME)%` replaces the literal `%kernel.project_dir%` token
 * in an environment value with the application directory, so the documented
 * SQLite default `sqlite:///%kernel.project_dir%/var/data.db` works.
 *
 * Symfony's `resolve:` processor would also do this, but it treats every
 * `%...%` pair as a parameter reference and so breaks database passwords that
 * contain URL-encoded characters such as `%40`. Only this one token changes;
 * every other character is passed through untouched.
 */
final class ProjectDirEnvVarProcessor implements EnvVarProcessorInterface
{
    public const TOKEN = '%kernel.project_dir%';

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {}

    public function getEnv(string $prefix, string $name, \Closure $getEnv): mixed
    {
        $value = $getEnv($name);

        return is_string($value) ? str_replace(self::TOKEN, $this->projectDir, $value) : $value;
    }

    public static function getProvidedTypes(): array
    {
        return ['project_dir' => 'string'];
    }
}
