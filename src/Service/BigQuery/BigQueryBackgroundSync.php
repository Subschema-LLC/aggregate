<?php

declare(strict_types=1);

namespace App\Service\BigQuery;

use App\Service\Update\Toolchain;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Starts app:bigquery:sync --force from the admin page in a background
 * process, so a long upload does not hold a web request open. Its output
 * goes to var/bigquery/last-run.log; results appear in the status table.
 */
class BigQueryBackgroundSync
{
    public function __construct(
        private readonly Toolchain $toolchain,
        private readonly string $projectDir,
        private readonly string $environment,
        private readonly bool $debug,
    ) {
    }

    /** The crontab line that runs the scheduled sync every five minutes. */
    public function cronLine(): string
    {
        return '*/5 * * * * cd '.escapeshellarg($this->projectDir).' && php bin/console app:bigquery:sync --no-interaction';
    }

    /** Why a sync cannot start from the dashboard, or null when it can. */
    public function problem(): ?string
    {
        if (!function_exists('proc_open') || \DIRECTORY_SEPARATOR === '\\') {
            return 'Starting a sync from the dashboard needs proc_open on a Unix-like server. Run php bin/console app:bigquery:sync --force on the server instead.';
        }
        if ($this->toolchain->php() === null) {
            return 'The command-line PHP executable could not be found. Set PHP_PATH for the web server, or run php bin/console app:bigquery:sync --force on the server.';
        }

        return null;
    }

    /** @throws \RuntimeException with a message for the administrator */
    public function start(): void
    {
        $problem = $this->problem();
        if ($problem !== null) {
            throw new \RuntimeException($problem);
        }
        $directory = $this->projectDir.'/var/bigquery';
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('var/bigquery could not be created. Check that var is writable.');
        }
        $command = [...$this->toolchain->php(), $this->projectDir.'/bin/console', 'app:bigquery:sync', '--force', '--env='.$this->environment, '--no-interaction'];
        if (!$this->debug) {
            $command[] = '--no-debug';
        }
        $setsid = (new ExecutableFinder())->find('setsid');
        $line = ($setsid !== null ? escapeshellarg($setsid).' ' : '').'nohup '.implode(' ', array_map('escapeshellarg', $command))
            .' > '.escapeshellarg($directory.'/last-run.log').' 2>&1 < /dev/null &';
        $process = Process::fromShellCommandline($line, $this->projectDir, null, null, 30);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new \RuntimeException('The sync could not be started in the background. Run php bin/console app:bigquery:sync --force on the server.');
        }
    }
}
