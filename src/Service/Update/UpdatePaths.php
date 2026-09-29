<?php

declare(strict_types=1);

namespace App\Service\Update;

/**
 * The single list of which installation paths belong to the operator and which
 * belong to a release. Release packaging (scripts/build-release.py) refuses the
 * same PROTECTED patterns; a release test keeps both lists identical.
 *
 * Patterns are relative to the project root. `*` matches within one path
 * segment and a trailing `/**` matches everything below a directory.
 */
final class UpdatePaths
{
    /** Never packaged, replaced, or deleted by an update. */
    public const PROTECTED = [
        '.env.local', '.env.local.php', '.env.*.local', '.env.prod',
        'config/aggregate.yaml', 'config/aggregate_*.yaml', 'config/websites.yaml',
        'config/*.local.yaml', 'config/tag-manager/sites/**', 'config/secrets/**',
        'var/**',
    ];

    /** Release-owned paths inside a protected tree. */
    public const RELEASE_OWNED_EXCEPTIONS = ['var/browser/**'];

    /**
     * Shipped defaults that operators customize through config/NAME.local.yaml.
     * Local edits to the default file move to the override before it is replaced.
     */
    public const CUSTOMIZABLE_CONFIG = [
        'config/goals.yaml' => 'config/goals.local.yaml',
        'config/navigation.yaml' => 'config/navigation.local.yaml',
        'config/quick_search.yaml' => 'config/quick_search.local.yaml',
    ];

    /** Shipped files kept as installed when the operator has changed them. */
    public const KEEP_WHEN_MODIFIED = ['public/.htaccess', 'public/robots.txt'];

    /** The release's trusted key never replaces the installation's trusted key. */
    public const TRUST_ANCHOR = 'config/release-signing.pub';

    /** Environment defaults: existing values are kept and new keys are appended. */
    public const ENVIRONMENT_DEFAULTS = '.env';

    /** Release metadata written last, after every other file is in place. */
    public const RELEASE_METADATA = 'release.json';
    public const INVENTORY = 'release-files.json';

    /**
     * Directories wholly owned by a release. Files in them that a newer release
     * no longer ships are removed (after backup) so stale code cannot load.
     */
    public const OWNED_TREES = [
        'src', 'templates', 'translations', 'migrations', 'assets', 'micro-consent-dropins',
        'scripts', 'vendor', 'public/assets', 'public/bundles', 'config/packages', 'config/routes',
        'var/browser',
    ];

    public const WORK_DIRECTORY = 'var/updates';
    public const MAINTENANCE_FILE = 'var/maintenance.json';

    public static function isProtected(string $relative): bool
    {
        foreach (self::RELEASE_OWNED_EXCEPTIONS as $pattern) {
            if (self::matches($pattern, $relative)) {
                return false;
            }
        }
        foreach (self::PROTECTED as $pattern) {
            if (self::matches($pattern, $relative)) {
                return true;
            }
        }

        return false;
    }

    public static function isInOwnedTree(string $relative): bool
    {
        foreach (self::OWNED_TREES as $tree) {
            if (str_starts_with($relative, $tree.'/')) {
                return true;
            }
        }

        return false;
    }

    public static function matches(string $pattern, string $relative): bool
    {
        if (str_ends_with($pattern, '/**')) {
            return str_starts_with($relative, substr($pattern, 0, -2));
        }
        $expression = implode('[^/]*', array_map(static fn (string $part): string => preg_quote($part, '~'), explode('*', $pattern)));

        return preg_match('~^'.$expression.'$~D', $relative) === 1;
    }
}
