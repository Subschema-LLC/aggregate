<?php

declare(strict_types=1);

namespace App\Service\Update;

use App\Service\InstalledRelease;
use App\Service\Operations\AdminActions;
use App\Service\ReleasePackageVerifier;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * What the dashboard does before an update runs: keep and verify an uploaded
 * release, check that the command line sees the same server as the web
 * request, and start app:updates:apply in the background. None of this runs
 * in the update process itself, so ApplicationUpdater::preload() need not
 * list it.
 */
class BackgroundUpdate
{
    public function __construct(
        private readonly ApplicationUpdater $updater,
        private readonly string $projectDir,
        private readonly string $environment,
        private readonly UpdateJournal $journal,
        private readonly ReleasePackageInstaller $installer,
        private readonly ReleasePackageVerifier $verifier,
        private readonly InstalledRelease $installed,
        private readonly Toolchain $toolchain,
    ) {
    }

    /**
     * Keep a release ZIP, manifest and signature uploaded on the dashboard in
     * var/updates/uploads and verify them before anything else happens.
     *
     * @param array<string, string> $files Temporary upload paths keyed by original file name
     * @return array{package: string, manifest: string, signature: string, version: string}
     */
    public function stageUpload(array $files): array
    {
        $type = $this->updater->installationType();
        if ($type === 'git') {
            throw new \RuntimeException('This installation updates directly from the repository, so it does not install release ZIPs. To use release ZIPs, choose that update method first (Updates page, app:updates:method release or updates_method in config/aggregate.yaml).');
        }
        if ($type === 'deployment') {
            throw new \RuntimeException('This installation is deployed another way, so it does not install release ZIPs. Deploy the new version with that tool, or choose release ZIP updates first.');
        }
        $roles = [];
        foreach ($files as $name => $path) {
            // Browsers may rename repeated downloads, so only the extension counts.
            $role = match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
                'zip' => 'package',
                'json' => 'manifest',
                'sig' => 'signature',
                default => throw new \RuntimeException('Unexpected file '.$name.'. Upload aggregate-YYYY.MM.NN.zip, aggregate-release.json and aggregate-release.json.sig from the release page.'),
            };
            if (isset($roles[$role])) {
                throw new \RuntimeException('Upload one release ZIP with its own manifest and signature.');
            }
            $roles[$role] = [$name, $path];
        }
        foreach (['package' => 'the release ZIP', 'manifest' => 'aggregate-release.json', 'signature' => 'aggregate-release.json.sig'] as $role => $label) {
            if (!isset($roles[$role])) {
                throw new \RuntimeException('Missing '.$label.'. Upload all three files from the release page.');
            }
        }
        $directory = $this->journal->ensureDirectory('uploads').'/'.bin2hex(random_bytes(8));
        if (!@mkdir($directory, 0775) && !is_dir($directory)) {
            throw new \RuntimeException('The upload could not be saved: '.FileOwner::explain($directory, $this->projectDir));
        }
        $paths = [];
        $canonical = ['package' => 'aggregate-package.zip', 'manifest' => 'aggregate-release.json', 'signature' => 'aggregate-release.json.sig'];
        foreach ($roles as $role => [, $path]) {
            $target = $directory.'/'.$canonical[$role];
            if (!@rename($path, $target) && !@copy($path, $target)) {
                $this->installer->removeTree($directory);
                throw new \RuntimeException('The upload could not be saved. Check free disk space in var/updates.');
            }
            $paths[$role] = $target;
        }
        try {
            $manifest = $this->verifier->verify($paths['package'], $paths['manifest'], $paths['signature']);
            $installed = $this->installed->read();
            if ($installed !== null && version_compare($manifest['version'], $installed['version'], '<=')) {
                throw new \RuntimeException('Release '.$manifest['version'].' is not newer than the installed version '.$installed['version'].'. Downgrades are not supported.');
            }
        } catch (\Throwable $e) {
            $this->installer->removeTree($directory);
            throw new \RuntimeException($e->getMessage(), previous: $e);
        }

        return $paths + ['version' => $manifest['version']];
    }

    /**
     * Start the update in a separate background process for the dashboard.
     *
     * @param list<string> $arguments Additional app:updates:apply options
     */
    public function start(array $arguments): void
    {
        if (!function_exists('proc_open') || \DIRECTORY_SEPARATOR === '\\') {
            throw new \RuntimeException('Background updates need proc_open on a Unix-like server. Run the update from the command line.');
        }
        $command = $this->updater->consoleCommand(['app:updates:apply', '--yes', ...$arguments]);
        $log = $this->journal->ensureDirectory().'/last-run.log';
        $setsid = (new ExecutableFinder())->find('setsid');
        $line = ($setsid !== null ? escapeshellarg($setsid).' ' : '').'nohup '.implode(' ', array_map('escapeshellarg', $command))
            .' > '.escapeshellarg($log).' 2>&1 < /dev/null &';
        // The update records itself in the audit trail when the dashboard starts it.
        $process = Process::fromShellCommandline($line, $this->projectDir, [AdminActions::CHILD_PROCESS => '1'], null, 30);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new \RuntimeException('The update could not be started in the background. Run it from the command line.');
        }
    }

    /**
     * Run the command-line preflight exactly as the background update would, and
     * confirm it sees the same environment and database as this web request.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        if (!function_exists('proc_open') || \DIRECTORY_SEPARATOR === '\\') {
            return ['Starting updates from the dashboard needs proc_open on a Unix-like server.'];
        }
        $php = $this->toolchain->php();
        if ($php === null) {
            return [$this->toolchain->phpProblem() ?? 'The command-line PHP executable could not be found.'];
        }
        $command = $this->updater->consoleCommand(['app:updates:apply', '--preflight', '--json']);
        $result = null;
        $failure = 'it could not be started';
        try {
            $process = new Process($command, $this->projectDir, null, null, 120);
            $process->run();
            // PHP notices printed before the JSON line do not hide the result.
            foreach (array_reverse(preg_split('/\R/', trim($process->getOutput())) ?: []) as $line) {
                if (str_starts_with($line, '{')) {
                    $result = json_decode($line, true);
                    break;
                }
            }
            $detail = self::firstLine($process->getErrorOutput()) ?? self::firstLine($process->getOutput());
            $failure = 'it exited with code '.$process->getExitCode().($detail === null ? '' : ': '.$detail);
        } catch (\Throwable $e) {
            $failure .= ': '.(self::firstLine($e->getMessage()) ?? $e::class);
        }
        if (!is_array($result) || !is_array($result['problems'] ?? null)) {
            return ['The update check could not run on the command line with '.$php[0].', because '.$failure.' Run '.$php[0].' bin/console app:updates:apply --preflight on the server, as the user that owns the files, for details.'];
        }
        $problems = array_values(array_filter($result['problems'], 'is_string'));
        if (($result['environment'] ?? null) !== $this->environment || ($result['database'] ?? null) !== $this->updater->databaseFingerprint()) {
            $problems[] = 'The command-line console uses a different environment or database than the web server. Move web-server-only settings into .env.local, or run the update from the command line.';
        }

        return $problems;
    }

    /** The first non-empty line of a command's output, without escape codes, at most 300 characters. */
    private static function firstLine(string $text): ?string
    {
        foreach (preg_split('/\R/', (string) preg_replace('/\e\[[0-9;]*[A-Za-z]/', '', $text)) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                return mb_strlen($line) > 300 ? mb_substr($line, 0, 299).'…' : $line;
            }
        }

        return null;
    }
}
