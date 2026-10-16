<?php

declare(strict_types=1);

namespace App\Service\Update;

/**
 * Who owns a file and who this process runs as, for messages that explain why
 * a folder cannot be written: typically one a command run as root created.
 */
final class FileOwner
{
    /** The user name that owns $path, or null when the platform cannot tell. */
    public static function of(string $path): ?string
    {
        $uid = @fileowner($path);
        if (!is_int($uid) || !function_exists('posix_getpwuid')) {
            return null;
        }
        $owner = posix_getpwuid($uid);

        return is_array($owner) && is_string($owner['name'] ?? null) ? $owner['name'] : (string) $uid;
    }

    /** The user this process runs as. */
    public static function current(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $user = posix_getpwuid(posix_geteuid());
            if (is_array($user) && is_string($user['name'] ?? null)) {
                return $user['name'];
            }
        }

        return get_current_user() ?: 'this user';
    }

    /** $path relative to $projectDir, for messages. */
    public static function relative(string $path, string $projectDir): string
    {
        return str_starts_with($path, rtrim($projectDir, '/').'/') ? substr($path, strlen(rtrim($projectDir, '/')) + 1) : $path;
    }

    /**
     * Why $path cannot be created or written: the nearest existing folder,
     * and, when it belongs to someone else, its owner and how to give it back.
     */
    public static function explain(string $path, string $projectDir): string
    {
        $blocking = $path;
        while (!file_exists($blocking) && dirname($blocking) !== $blocking) {
            $blocking = dirname($blocking);
        }
        $folder = self::relative($blocking, $projectDir);
        $user = self::current();
        $owner = self::of($blocking);
        if ($owner !== null && $owner !== $user) {
            return $folder.' belongs to '.$owner.', but this runs as '.$user.'. A command run as '.$owner.' usually created it. As root, give it back with chown -R '.$user.' '.$folder.', or delete it so it is created again.';
        }

        return $user.' cannot write '.$folder.'. Check its permissions.';
    }
}
