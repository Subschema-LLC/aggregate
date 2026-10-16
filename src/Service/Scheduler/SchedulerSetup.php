<?php

declare(strict_types=1);

namespace App\Service\Scheduler;

use App\Service\Update\Toolchain;

/**
 * How to make this server run app:scheduler:run every five minutes, written
 * for the server Aggregate runs on: the fields of a Plesk scheduled task when
 * the application lives in a Plesk subscription, and a crontab line with this
 * PHP version's command-line binary otherwise.
 *
 * Plesk runs "Run a command" tasks in a chroot where the subscription folder
 * is the root and PHP is missing, so a cd into the full path fails there;
 * "Run a PHP script" tasks use Plesk's PHP with a path inside the
 * subscription instead.
 */
class SchedulerSetup
{
    public const COMMAND = 'app:scheduler:run';
    public const SCHEDULE = '*/5 * * * *';
    private const PLESK_VHOSTS = '/var/www/vhosts/';

    public function __construct(
        private readonly Toolchain $toolchain,
        private readonly string $projectDir,
    ) {
    }

    /**
     * The fields of a Plesk scheduled task, or null when the application is
     * not in a Plesk subscription folder.
     *
     * @return array{subscription: string, script_path: string, arguments: string, php_version: string, schedule: string}|null
     */
    public function plesk(): ?array
    {
        if (preg_match('#^'.preg_quote(self::PLESK_VHOSTS, '#').'([^/]+)/(.+)$#', rtrim($this->projectDir, '/'), $match) !== 1) {
            return null;
        }

        return [
            'subscription' => $match[1],
            'script_path' => $match[2].'/bin/console',
            'arguments' => self::COMMAND,
            'php_version' => PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION,
            'schedule' => self::SCHEDULE,
        ];
    }

    /** The command-line PHP binary for this PHP version, or "php" when it cannot be found. */
    public function phpBinary(): string
    {
        return $this->toolchain->php()[0] ?? 'php';
    }

    /** A crontab line for the user that runs Aggregate. */
    public function cronLine(): string
    {
        return self::SCHEDULE.' cd '.escapeshellarg($this->projectDir).' && '.escapeshellarg($this->phpBinary()).' bin/console '.self::COMMAND.' --no-interaction';
    }

    /** The command to run by hand, from the application folder. */
    public function manualCommand(): string
    {
        return 'php bin/console '.self::COMMAND;
    }
}
