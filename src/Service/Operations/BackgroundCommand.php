<?php

declare(strict_types=1);

namespace App\Service\Operations;

use App\Service\Update\Toolchain;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Starts a console command in a background process from a web request, so a
 * long job does not hold the request open: the dashboard's Sync now and Run
 * now buttons. Output goes to a log file under var/. The command line is
 * fixed by the caller; only the administrator's username is passed through.
 */
class BackgroundCommand
{
    public function __construct(
        private readonly Toolchain $toolchain,
        private readonly string $projectDir,
        private readonly string $environment,
        private readonly bool $debug,
    ) {
    }

    /** Why the command cannot start from the dashboard, or null when it can. */
    public function problem(string $command): ?string
    {
        if (!function_exists('proc_open') || \DIRECTORY_SEPARATOR === '\\') {
            return 'Starting this from the dashboard needs proc_open on a Unix-like server. Run php bin/console '.$command.' on the server instead.';
        }
        if ($this->toolchain->php() === null) {
            return 'The command-line PHP executable could not be found. Set PHP_PATH for the web server, or run php bin/console '.$command.' on the server.';
        }

        return null;
    }

    /**
     * @param list<string> $arguments options after the command name
     * @param string       $logFile   path relative to the application folder, under var/
     *
     * @throws \RuntimeException with a message for the administrator
     */
    public function start(string $command, array $arguments, string $logFile): void
    {
        $problem = $this->problem($command);
        if ($problem !== null) {
            throw new \RuntimeException($problem);
        }
        $directory = dirname($this->projectDir.'/'.$logFile);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException(dirname($logFile).' could not be created. Check that var is writable.');
        }
        $line = [...$this->toolchain->php(), $this->projectDir.'/bin/console', $command, ...$arguments, '--env='.$this->environment, '--no-interaction'];
        if (!$this->debug) {
            $line[] = '--no-debug';
        }
        $setsid = (new ExecutableFinder())->find('setsid');
        $shell = ($setsid !== null ? escapeshellarg($setsid).' ' : '').'nohup '.implode(' ', array_map('escapeshellarg', $line))
            .' > '.escapeshellarg($this->projectDir.'/'.$logFile).' 2>&1 < /dev/null &';
        $process = Process::fromShellCommandline($shell, $this->projectDir, null, null, 30);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new \RuntimeException('The command could not be started in the background. Run php bin/console '.$command.' on the server.');
        }
    }
}
