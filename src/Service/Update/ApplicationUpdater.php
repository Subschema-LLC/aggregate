<?php

declare(strict_types=1);

namespace App\Service\Update;

use App\Service\ApplicationUpdateService;
use App\Service\FeatureFlags;
use App\Service\InstalledRelease;
use App\Service\ReleasePackageVerifier;
use App\Service\ReleaseUpdateService;
use Doctrine\DBAL\Connection;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Installs an update from a signed release ZIP or a fast-forward Git pull, then
 * completes the deployment steps: database migrations, glossary sync, cache
 * rebuild and worker restart. With the deployment method the code is deployed
 * another way, and app:updates:deployed runs only those steps here.
 *
 * Progress lives in UpdateJournal so an interrupted update can be resumed or
 * rolled back, and a lock prevents concurrent updates. Web requests get a 503
 * maintenance page while files change. After files are replaced this process
 * hands off to a fresh `app:updates:apply --resume` process, so the remaining
 * steps run entirely with the new code.
 *
 * File recovery and database restoration are separate: rolling back restores
 * application files, and restores a database only from an SQLite snapshot and
 * only when asked.
 */
class ApplicationUpdater
{
    public const RELEASE_STEPS = ['download', 'verify', 'stage', 'maintenance', 'database_backup', 'apply_files', 'handoff', 'migrations', 'glossary', 'cache', 'workers', 'finish'];
    public const GIT_STEPS = ['apply_files', 'dependencies', 'handoff', 'migrations', 'glossary', 'assets', 'cache', 'workers', 'finish'];
    public const DEPLOYMENT_STEPS = ['maintenance', 'database_backup', 'dependencies', 'handoff', 'migrations', 'glossary', 'assets', 'cache', 'workers', 'finish'];
    private const KEEP_BACKUPS = 3;

    /** @var (callable(string, string): void)|null */
    private $output = null;

    public function __construct(
        private readonly string $projectDir,
        private readonly string $environment,
        private readonly bool $debug,
        private readonly UpdateJournal $journal,
        private readonly MaintenanceMode $maintenance,
        private readonly ReleasePackageInstaller $installer,
        private readonly ReleasePackageVerifier $verifier,
        private readonly ReleaseUpdateService $releases,
        private readonly ApplicationUpdateService $updates,
        private readonly InstalledRelease $installed,
        private readonly FeatureFlags $features,
        private readonly Connection $connection,
        private readonly SystemCheck $systemCheck,
        private readonly Toolchain $toolchain,
        private readonly ?DeploymentState $deployment = null,
    ) {
    }

    /** release, git or deployment */
    public function installationType(): string
    {
        return $this->updates->installationType();
    }

    /** @return list<string> */
    public static function stepsFor(string $type): array
    {
        return match ($type) {
            'git' => self::GIT_STEPS,
            'deployment' => self::DEPLOYMENT_STEPS,
            default => self::RELEASE_STEPS,
        };
    }

    /** @return array<string, mixed>|null The last recorded update, with whether it is still running */
    public function status(): ?array
    {
        $state = $this->journal->read();
        if ($state !== null) {
            $state['running'] = $this->journal->isLocked();
        }

        return $state;
    }

    /**
     * Why the update method cannot change now, or null. Switching during an
     * update would leave a resume or rollback using the other method.
     */
    public function methodChangeProblem(): ?string
    {
        $state = $this->status();
        if ($state !== null && ($state['running'] || in_array($state['status'] ?? null, ['running', 'needs_attention'], true))) {
            return 'The update method cannot change while an update is running or needs attention. Finish, resume or roll back that update first.';
        }

        return null;
    }

    /**
     * The system check for the current user, plus what the dashboard compares
     * with the command line before starting a background update.
     *
     * @return array{problems: list<string>, warnings: list<string>, checks: list<array<string, string>>, installation_type: string, environment: string, database: string, php_version: string}
     */
    public function preflight(): array
    {
        $checks = $this->systemCheck->run();
        try {
            $type = $this->installationType();
        } catch (\RuntimeException) {
            $type = 'unknown';
        }

        return [
            'problems' => $this->systemCheck->problems(checks: $checks),
            'warnings' => $this->systemCheck->warnings($checks),
            'checks' => $checks,
            'installation_type' => $type,
            'environment' => $this->environment,
            'database' => $this->databaseFingerprint(),
            'php_version' => PHP_VERSION,
        ];
    }

    public function isSqlite(): bool
    {
        return $this->systemCheck->isSqlite();
    }

    /** Identifies the database without revealing connection details or credentials. */
    public function databaseFingerprint(): string
    {
        $params = $this->connection->getParams();
        $path = is_string($params['path'] ?? null) ? (realpath($params['path']) ?: $params['path']) : null;

        return substr(hash('sha256', json_encode([
            $params['driver'] ?? null, $params['host'] ?? null, $params['port'] ?? null,
            $params['dbname'] ?? null, $path, $params['user'] ?? null,
        ], JSON_THROW_ON_ERROR)), 0, 16);
    }

    /**
     * Start a new update. With the deployment method, app:updates:deployed
     * ($options['deployed']) runs the post-deployment steps for code deployed
     * another way: the commit comes from $options['commit'], the Git repository
     * in $options['repository'], or the repository found next to the site, and
     * files the steps already ran for are skipped unless $options['force'] is set.
     *
     * @param array{version?: ?string, package?: ?string, manifest?: ?string, signature?: ?string, database_backup_confirmed?: bool, deployed?: bool, commit?: ?string, repository?: ?string, force?: bool} $options
     * @param callable(string, string): void $output
     * @return array<string, mixed> The final journal state, or a no-op result
     */
    public function start(array $options, callable $output): array
    {
        $this->output = $output;
        $this->preload();
        $this->journal->acquire();
        try {
            $type = $this->installationType();
            $last = $this->journal->read();
            $superseded = null;
            if (in_array($last['status'] ?? null, ['running', 'needs_attention'], true)) {
                if ($type !== 'deployment' || ($last['type'] ?? null) !== 'deployment') {
                    throw new \RuntimeException('The previous update stopped at "'.($last['step'] ?? 'unknown').'". Resume it with app:updates:apply --resume or restore files with app:updates:rollback.');
                }
                // Running app:updates:deployed again is how a deployment retries: every step repeats.
                $superseded = $last;
            }
            if ($type !== 'deployment' && ($options['deployed'] ?? false)) {
                throw new \RuntimeException('Deployments are only recorded when the code is deployed another way. Choose that update method first (Updates page, app:updates:method deployment or updates_method: deployment in config/aggregate.yaml).');
            }
            if ($type === 'deployment' && !($options['deployed'] ?? false)) {
                throw new \RuntimeException('This installation is deployed another way (such as a hosting panel\'s Git deployment or CI/CD), so this application does not install updates. Deploy with that tool, then run php bin/console app:updates:deployed.');
            }
            // This process holds the lock, and the journal state was checked above.
            $problems = $this->systemCheck->problems(ignore: ['last_update']);
            if ($problems !== []) {
                throw new \RuntimeException(implode(' ', $problems));
            }
            if (!$this->isSqlite() && !($options['database_backup_confirmed'] ?? false)) {
                throw new \RuntimeException('Back up the database before updating, then confirm it with --database-backup-confirmed. Database migrations cannot be reversed automatically.');
            }

            if ($type === 'git' && array_filter([$options['package'] ?? null, $options['manifest'] ?? null, $options['signature'] ?? null]) !== []) {
                throw new \RuntimeException('This installation updates directly from the repository, so it does not install release ZIPs. To use release ZIPs, choose that update method first (Updates page, app:updates:method release or updates_method in config/aggregate.yaml).');
            }
            $id = gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(3));
            $state = [
                'id' => $id,
                'type' => $type,
                'status' => 'running',
                'step' => null,
                'completed' => [],
                'environment' => $this->environment,
                'from' => [],
                'to' => [],
                'files' => [],
                'backup' => UpdatePaths::WORK_DIRECTORY.'/backups/'.$id,
                'database_backup' => null,
                'report' => [],
                'error' => null,
                'log' => [],
                'started_at' => time(),
            ];

            $prepared = null;
            if ($type === 'release') {
                // Without release.json the installed version is unknown; the first
                // release installed records it (for example after files were copied without .git).
                $installed = $this->installed->read();
                $state['from'] = ['version' => $installed['version'] ?? null, 'commit' => $installed['commit'] ?? null];
                $local = array_filter([
                    'package' => $options['package'] ?? null,
                    'manifest' => $options['manifest'] ?? null,
                    'signature' => $options['signature'] ?? null,
                ]);
                if ($local !== [] && count($local) !== 3) {
                    throw new \RuntimeException('Provide the package, manifest and signature together, or none of them to download the release from GitHub.');
                }
                foreach ($local as $key => $path) {
                    $real = realpath($path);
                    if ($real === false || !is_file($real)) {
                        throw new \RuntimeException('The '.$key.' file was not found: '.$path);
                    }
                    $state['files'][$key] = $real;
                }
                if ($local === []) {
                    $check = $this->releases->check(true);
                    if ($options['version'] ?? null) {
                        $state['to']['version'] = $options['version'];
                    } elseif ($check['state'] === 'up_to_date' || $check['state'] === 'ahead') {
                        return ['status' => 'up_to_date', 'message' => $check['message']];
                    } elseif ($check['state'] !== 'available') {
                        throw new \RuntimeException($check['message'] ?: 'No installable release is available.');
                    } else {
                        $state['to']['version'] = $check['latest_version'];
                    }
                }
            } elseif ($type === 'deployment') {
                // After a stopped run, the files match an earlier record but the steps did not finish.
                $prepared = $this->prepareDeployment($superseded !== null ? ['force' => true] + $options : $options);
                if ($prepared['done'] !== null) {
                    return ['status' => 'up_to_date', 'message' => $prepared['done']];
                }
                $state['from'] = $prepared['from'];
                $state['to'] = $prepared['to'];
            } else {
                $check = $this->updates->check(true);
                if ($check['state'] === 'up_to_date') {
                    return ['status' => 'up_to_date', 'message' => $check['message']];
                }
                if ($check['state'] !== 'available') {
                    throw new \RuntimeException($check['message'] ?: 'No fast-forward update is available.');
                }
                $state['from'] = ['commit' => $check['current_commit'], 'branch' => $check['installed_branch']];
                $state['to'] = ['commit' => $check['latest_commit']];
            }

            if ($superseded !== null) {
                $state['superseded'] = $superseded['id'] ?? true;
            }
            $state = $this->journal->write($state);
            if ($superseded !== null) {
                $state = $this->say($state, 'Replacing deployment '.($superseded['id'] ?? '').', which stopped at "'.($superseded['step'] ?? 'unknown').'". Every step runs again.', 'warning');
            }
            foreach ($prepared['warnings'] ?? [] as $warning) {
                $state = $this->say($state, $warning, 'warning');
            }
            $state = $this->say($state, match ($type) {
                'release' => 'Starting release update '.$id.'.',
                'deployment' => 'Running the post-deployment steps ('.$id.')'.($state['to']['commit'] !== null ? ' for commit '.$state['to']['commit'] : '').'.',
                default => 'Starting Git update '.$id.'.',
            });

            return $this->run($state);
        } finally {
            $this->journal->release();
        }
    }

    /**
     * Continue an interrupted update from its recorded step.
     *
     * @param callable(string, string): void $output
     * @return array<string, mixed>
     */
    public function resume(callable $output): array
    {
        $this->output = $output;
        $this->preload();
        $this->journal->acquire();
        try {
            $state = $this->journal->read();
            if ($state === null || !in_array($state['status'] ?? null, ['running', 'needs_attention'], true)) {
                throw new \RuntimeException('There is no interrupted update to resume.');
            }
            if (($state['environment'] ?? $this->environment) !== $this->environment) {
                throw new \RuntimeException('Resume the update in the "'.$state['environment'].'" environment it started in (add --env='.$state['environment'].').');
            }
            if ($state['step'] === 'rollback') {
                throw new \RuntimeException('A rollback of this update stopped before finishing. Run php bin/console app:updates:rollback again.');
            }
            $state['status'] = 'running';
            $state['error'] = null;
            if ($state['step'] === 'handoff') {
                // This fresh process already runs the new code: continue after the handoff.
                $state['completed'][] = 'handoff';
                $steps = self::stepsFor((string) $state['type']);
                $state['step'] = $steps[array_search('handoff', $steps, true) + 1];
            }
            if ($state['step'] !== null && $this->stepIndex($state, $state['step']) > $this->stepIndex($state, $this->pausedAfter($state))) {
                $this->maintenance->enable('update');
            }
            $state = $this->say($state, 'Resuming update '.$state['id'].' at "'.($state['step'] ?? 'start').'".');

            return $this->run($state);
        } finally {
            $this->journal->release();
        }
    }

    /**
     * Restore the application files from before the last update.
     *
     * @param callable(string, string): void $output
     * @return array<string, mixed>
     */
    public function rollback(bool $restoreDatabase, callable $output): array
    {
        $this->output = $output;
        $this->preload();
        $this->journal->acquire();
        try {
            $state = $this->journal->read() ?? throw new \RuntimeException('No update has been recorded, so there is nothing to roll back.');
            if (($state['status'] ?? null) === 'rolled_back') {
                throw new \RuntimeException('The last update was already rolled back.');
            }
            if ($state['step'] === 'rollback') {
                // Repeating a rollback that stopped: reuse what the first attempt found.
                $filesChanged = (bool) ($state['rollback']['files'] ?? true);
                $migrationsStarted = (bool) ($state['rollback']['migrations'] ?? true);
            } elseif (($state['type'] ?? null) === 'deployment') {
                // The deployment tool changed the files, so there is no file backup here.
                $filesChanged = false;
                $migrationsStarted = in_array('migrations', $state['completed'], true) || in_array($state['step'], ['migrations', 'glossary', 'assets', 'cache', 'workers', 'finish'], true);
            } else {
                $filesChanged = in_array('apply_files', $state['completed'], true) || $state['step'] === 'apply_files'
                    || $this->stepIndex($state, (string) $state['step']) > $this->stepIndex($state, 'apply_files');
                $migrationsStarted = in_array('migrations', $state['completed'], true) || in_array($state['step'], ['migrations', 'glossary', 'assets', 'cache', 'workers', 'finish'], true);
            }
            $state['rollback'] = ['files' => $filesChanged, 'migrations' => $migrationsStarted];
            if ($restoreDatabase && !is_string($state['database_backup']['path'] ?? null)) {
                throw new \RuntimeException('This update has no automatic database snapshot (only SQLite databases get one). Restore your own database backup instead.');
            }

            $state['step'] = 'rollback';
            $state['status'] = 'running';
            $state = $this->say($state, 'Rolling back update '.$state['id'].'.');
            $this->maintenance->enable('rollback');
            if ($filesChanged) {
                if ($state['type'] === 'release') {
                    $result = (new FileTransaction($this->projectDir, $this->projectDir.'/'.$state['backup']))->rollback();
                    $state = $this->say($state, sprintf('Restored %d files and removed %d files added by the update.', $result['restored'], $result['removed']));
                } else {
                    $this->updates->resetTo($state['from']['commit']);
                    $state = $this->say($state, 'Returned the checkout to commit '.$state['from']['commit'].'.');
                    $this->clearCacheDirectory($state['id']);
                    if (($state['flags']['composer'] ?? false) && $this->toolchain->composer() !== null) {
                        $state = $this->composerInstall($state);
                    }
                }
                $this->clearCacheDirectory($state['id']);
            }
            if (($state['type'] ?? null) === 'deployment') {
                $state = $this->say($state, 'These files were deployed another way, so they were left as they are.'
                    .(DeploymentState::isCommit($state['from']['commit'] ?? null)
                        ? ' To return to the previous version, deploy commit '.$state['from']['commit'].' with that tool.'
                        : ' To return to the previous version, deploy it again with that tool.'), 'warning');
            }
            if ($restoreDatabase) {
                $this->restoreSqlite($this->projectDir.'/'.$state['database_backup']['path']);
                $state = $this->say($state, 'Restored the SQLite database snapshot taken before the update.');
            } elseif ($migrationsStarted) {
                $state = $this->say($state, 'Database migrations from the update were not reversed. If the previous version reports database errors, restore the database backup taken before '.gmdate('Y-m-d H:i', (int) $state['started_at']).' UTC.', 'warning');
            }
            if ($filesChanged) {
                $this->console($state, ['cache:warmup'], 900);
                if ($state['type'] === 'git' && $this->shouldCompileAssets()) {
                    $this->console($state, ['asset-map:compile'], 900);
                }
                $state = $this->stopWorkers($state);
            }
            $this->maintenance->disable();
            $state['status'] = 'rolled_back';
            $state['step'] = null;
            $state['files_restored'] = $filesChanged;

            return $this->say($state, 'Rollback complete. The site is out of maintenance mode.', 'success');
        } catch (\Throwable $e) {
            if (isset($state)) {
                $state['status'] = 'needs_attention';
                $state['error'] = $e->getMessage();
                $this->maintenance->hold('rollback');
                $this->say($state, 'Rollback stopped: '.$e->getMessage().' The site stays in maintenance mode; fix the cause and run the rollback again.', 'error');
            }
            throw $e;
        } finally {
            $this->journal->release();
        }
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
        $type = $this->installationType();
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
            throw new \RuntimeException('The upload could not be saved. Check write access to var/updates.');
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
    public function startInBackground(array $arguments): void
    {
        if (!function_exists('proc_open') || \DIRECTORY_SEPARATOR === '\\') {
            throw new \RuntimeException('Background updates need proc_open on a Unix-like server. Run the update from the command line.');
        }
        $command = $this->consoleCommand(['app:updates:apply', '--yes', ...$arguments]);
        $log = $this->journal->ensureDirectory().'/last-run.log';
        $setsid = (new ExecutableFinder())->find('setsid');
        $line = ($setsid !== null ? escapeshellarg($setsid).' ' : '').'nohup '.implode(' ', array_map('escapeshellarg', $command))
            .' > '.escapeshellarg($log).' 2>&1 < /dev/null &';
        $process = Process::fromShellCommandline($line, $this->projectDir, null, null, 30);
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
    public function backgroundProblems(): array
    {
        if (!function_exists('proc_open') || \DIRECTORY_SEPARATOR === '\\') {
            return ['Starting updates from the dashboard needs proc_open on a Unix-like server.'];
        }
        if ($this->toolchain->php() === null) {
            return ['The command-line PHP executable could not be found. Set PHP_PATH for the web server or run the update from the command line.'];
        }
        try {
            $process = new Process($this->consoleCommand(['app:updates:apply', '--preflight', '--json']), $this->projectDir, null, null, 120);
            $process->run();
            $result = json_decode($process->getOutput(), true);
        } catch (\Throwable) {
            $result = null;
        }
        if (!is_array($result) || !is_array($result['problems'] ?? null)) {
            return ['The command-line console could not run the update preflight. Run php bin/console app:updates:apply --preflight on the server for details.'];
        }
        $problems = array_values(array_filter($result['problems'], 'is_string'));
        if (($result['environment'] ?? null) !== $this->environment || ($result['database'] ?? null) !== $this->databaseFingerprint()) {
            $problems[] = 'The command-line console uses a different environment or database than the web server. Move web-server-only settings into .env.local, or run the update from the command line.';
        }

        return $problems;
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    private function run(array $state): array
    {
        $steps = self::stepsFor((string) $state['type']);
        $start = $state['step'] === null ? 0 : max(0, (int) array_search($state['step'], $steps, true));
        for ($index = $start; $index < count($steps); ++$index) {
            $step = $steps[$index];
            $state['step'] = $step;
            $state = $this->journal->write($state);
            $this->maintenance->refresh();
            try {
                $state = $this->{'step'.str_replace('_', '', ucwords($step, '_'))}($state);
            } catch (\Throwable $e) {
                return $this->fail($state, $e);
            }
            if ($step === 'handoff') {
                // The fresh process ran the remaining steps with the new code.
                return $state;
            }
            $state['completed'][] = $step;
            $state = $this->journal->write($state);
        }

        return $state;
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    private function stepDownload(array $state): array
    {
        if (($state['files']['package'] ?? null) !== null && is_file($state['files']['package'])) {
            return $state;
        }
        $state = $this->say($state, 'Downloading release '.($state['to']['version'] ?? 'package').' from GitHub.');
        $downloaded = $this->releases->download($this->journal->ensureDirectory('downloads/'.$state['id']), $state['to']['version'] ?? null);
        $state['files'] = ['package' => $downloaded['package'], 'manifest' => $downloaded['manifest'], 'signature' => $downloaded['signature']];
        $state['to']['version'] = $downloaded['version'];

        return $state;
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    private function stepVerify(array $state): array
    {
        $manifest = $this->verifier->verify($state['files']['package'], $state['files']['manifest'], $state['files']['signature']);
        if ($state['from']['version'] !== null && version_compare($manifest['version'], (string) $state['from']['version'], '<=')) {
            throw new \RuntimeException('Release '.$manifest['version'].' is not newer than the installed version '.$state['from']['version'].'. Downgrades are not supported.');
        }
        $state['to'] = ['version' => $manifest['version'], 'commit' => $manifest['commit']];

        return $this->say($state, 'Verified the signature and checksum of release '.$manifest['version'].'.');
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    private function stepStage(array $state): array
    {
        $staging = $this->journal->ensureDirectory('staging').'/'.$state['id'];
        $this->installer->stage($state['files']['package'], $staging);
        $plan = $this->installer->plan($staging);
        if ($plan['unwritable'] !== []) {
            throw new \RuntimeException('This user cannot write '.implode(', ', array_slice($plan['unwritable'], 0, 5)).(count($plan['unwritable']) > 5 ? ' and others' : '').'. Run the update as the user that owns the application files.');
        }
        $summary = $this->installer->summary($plan);
        $state['report']['preview'] = [
            'files_written' => $summary['files_written'],
            'files_removed' => $summary['files_removed'],
            'overrides_created' => $summary['overrides_created'],
        ];

        return $this->say($state, sprintf('Prepared %d changed files and %d removals.', $summary['files_written'], $summary['files_removed']));
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    private function stepMaintenance(array $state): array
    {
        $this->maintenance->enable('update');
        $state = $this->say($state, 'Maintenance mode is on. Web requests receive a 503 page until the update finishes.');
        $state = $this->stopWorkers($state);

        return $state;
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    private function stepDatabaseBackup(array $state): array
    {
        if (!$this->isSqlite()) {
            $state['database_backup'] = ['confirmed_by_operator' => true];

            return $this->say($state, 'Using the database backup you confirmed. Database migrations cannot be reversed automatically.');
        }
        $path = $this->connection->getParams()['path'] ?? null;
        if (!is_string($path) || !is_file($path)) {
            $state['database_backup'] = ['confirmed_by_operator' => false];

            return $this->say($state, 'The SQLite database file does not exist yet; no snapshot was needed.');
        }
        $directory = $this->projectDir.'/'.$state['backup'];
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('The backup directory could not be created. Check write access to var/updates.');
        }
        $target = $directory.'/database.sqlite';
        if (!is_file($target)) {
            try {
                $this->connection->executeStatement('VACUUM INTO '.$this->connection->quote($target));
            } catch (\Throwable) {
                @unlink($target);
                if (!@copy($path, $target)) {
                    throw new \RuntimeException('The SQLite database could not be copied into the update backup. Check free disk space.');
                }
            }
        }
        $state['database_backup'] = ['path' => $state['backup'].'/database.sqlite'];

        return $this->say($state, 'Saved an SQLite snapshot to '.$state['backup'].'/database.sqlite.');
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    private function stepApplyFiles(array $state): array
    {
        if ($state['type'] === 'release') {
            $staging = $this->journal->ensureDirectory('staging').'/'.$state['id'];
            $plan = $this->installer->plan($staging);
            $files = new FileTransaction($this->projectDir, $this->projectDir.'/'.$state['backup']);
            $state['report']['files'] = $this->installer->apply($plan, $staging, $files, function (string $message) use (&$state): void {
                $this->maintenance->refresh();
                $state = $this->say($state, $message);
            });

            return $this->say($state, sprintf('Installed release %s files: %d written, %d removed.', $state['to']['version'], $state['report']['files']['files_written'], $state['report']['files']['files_removed']));
        }

        if ($this->updates->headCommit() === ($state['to']['commit'] ?? null)) {
            // Resumed after the merge had already completed.
            return $state;
        }
        $result = $this->updates->pull(function (string $latest, array $changed) use (&$state): void {
            $state['to']['commit'] = $latest;
            $state['flags'] = [
                'composer' => (bool) array_intersect($changed, ['composer.json', 'composer.lock']) || !is_file($this->projectDir.'/vendor/autoload.php'),
                'importmap' => in_array('importmap.php', $changed, true),
            ];
            if (($state['flags']['composer'] || $state['flags']['importmap']) && $this->toolchain->composer() === null) {
                throw new \RuntimeException('This update changes Composer or importmap dependencies, but Composer was not found. Install Composer for this user, then retry. Nothing was changed.');
            }
            $state = $this->stepMaintenance($state);
            $state = $this->stepDatabaseBackup($state);
            $state = $this->journal->write($state);
        });
        $state['report']['files'] = [
            'overrides_created' => $result['overrides_created'],
            'files_changed' => count($result['changed_paths']),
        ];
        foreach ($result['overrides_created'] as $override) {
            $state = $this->say($state, 'Moved your edits to '.$override.'. Compare it with the shipped default after the update.', 'warning');
        }

        return $this->say($state, 'Fast-forwarded the checkout to '.$result['current_commit'].'.');
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    private function stepDependencies(array $state): array
    {
        $this->clearCacheDirectory($state['id']);
        if ($state['type'] === 'deployment') {
            // Checked now rather than at the start, so a resumed run sees what the operator fixed.
            if ($this->deploymentState()->dependenciesOutOfDate($this->environment !== 'prod')) {
                return $this->composerInstall($state);
            }

            return $this->say($state, 'Composer dependencies already match composer.lock.');
        }
        if ($state['flags']['composer'] ?? false) {
            return $this->composerInstall($state);
        }
        if ($state['flags']['importmap'] ?? false) {
            $this->console($state, ['importmap:install'], 900);
        }

        return $state;
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    private function stepHandoff(array $state): array
    {
        $this->clearCacheDirectory($state['id']);
        $state = $this->say($state, 'Continuing with the updated application code.');
        $this->journal->release();
        $process = new Process($this->consoleCommand(['app:updates:apply', '--resume']), $this->projectDir, $this->childEnvironment(), null, null);
        $exit = $process->run(function (string $type, string $buffer): void {
            if ($this->output !== null) {
                ($this->output)(rtrim($buffer, "\n"), 'raw');
            }
        });
        $this->journal->acquire();
        $final = $this->journal->read() ?? $state;
        if (($final['status'] ?? null) === 'running') {
            $final['status'] = 'needs_attention';
            $final['error'] = 'The updated application could not continue the update (exit code '.$exit.').';
            $this->maintenance->hold('update');
            $final = $this->say($final, $final['error'].' The site stays in maintenance mode. Run php bin/console app:updates:apply --resume, or restore files with php scripts/restore-update-files.php.', 'error');
        }
        // The child process already reported the outcome.
        $final['handed_off'] = true;

        return $final;
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    private function stepMigrations(array $state): array
    {
        $this->console($state, ['doctrine:migrations:migrate', '--allow-no-migration'], 3600);

        return $this->say($state, 'Database migrations are complete.');
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    private function stepGlossary(array $state): array
    {
        $this->console($state, ['app:analytics:glossary:sync'], 900);

        return $state;
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    private function stepAssets(array $state): array
    {
        if ($state['type'] === 'deployment') {
            // Deployment tools copy only what the repository holds, without the
            // downloaded importmap packages or bundle assets. Both commands
            // only add what is missing.
            $this->console($state, ['importmap:install'], 900);
            $this->console($state, ['assets:install', 'public'], 900);
        }
        if ($this->shouldCompileAssets()) {
            $this->console($state, ['asset-map:compile'], 900);
        }

        return $state;
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    private function stepCache(array $state): array
    {
        // The cache directory was emptied before the handoff and rebuilt by this
        // process with the new code. Warm it rather than clearing it: deleting
        // it would pull compiled services out from under running processes.
        $this->console($state, ['cache:warmup'], 900);

        return $state;
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    private function stepWorkers(array $state): array
    {
        return $this->stopWorkers($state);
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    private function stepFinish(array $state): array
    {
        $this->maintenance->disable();
        $state['status'] = 'completed';
        $state['finished_at'] = time();
        $this->installer->removeTree($this->journal->directory().'/staging/'.$state['id']);
        $this->installer->removeTree($this->journal->directory().'/downloads/'.$state['id']);
        $uploads = $this->journal->directory().'/uploads/';
        if (is_string($state['files']['package'] ?? null) && str_starts_with($state['files']['package'], $uploads)) {
            $this->installer->removeTree(dirname($state['files']['package']));
        }
        $this->pruneBackups();
        if ($state['type'] === 'deployment') {
            return $this->finishDeployment($state);
        }
        $version = $state['type'] === 'release' ? 'release '.($state['to']['version'] ?? '') : 'commit '.($state['to']['commit'] ?? '');

        return $this->say($state, 'Update complete: '.$version.'. Maintenance mode is off. If PHP OPcache does not revalidate files on your host, reload PHP (for example PHP-FPM or the web server) now.', 'success');
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    private function fail(array $state, \Throwable $error): array
    {
        $step = (string) $state['step'];
        $message = $error->getMessage() !== '' ? $error->getMessage() : $error::class;
        $state['error'] = $message;
        if ($state['type'] === 'deployment') {
            // A run that replaced a stopped one keeps the site paused: that run may have left migrations half done.
            if ($this->stepIndex($state, $step) < $this->stepIndex($state, 'dependencies') && !isset($state['superseded'])) {
                try {
                    $this->maintenance->disable();
                } catch (\Throwable) {
                }
                $state['status'] = 'failed';

                return $this->say($state, 'The post-deployment steps did not run: '.$message.' Nothing was changed, and the site is out of maintenance mode. Fix the cause, then run php bin/console app:updates:deployed again.', 'error');
            }
            $this->maintenance->hold('update');
            $state['status'] = 'needs_attention';

            return $this->say($state, 'The post-deployment steps stopped at "'.$step.'": '.$message.' The site stays in maintenance mode. Fix the cause, then run php bin/console app:updates:apply --resume or php bin/console app:updates:deployed again.', 'error');
        }
        $beforeFiles = $this->stepIndex($state, $step) < $this->stepIndex($state, 'apply_files');
        try {
            if ($beforeFiles) {
                $this->maintenance->disable();
                $state['status'] = 'failed';

                return $this->say($state, 'Update stopped before any application file changed: '.$message, 'error');
            }
            if ($step === 'apply_files') {
                if ($state['type'] === 'release') {
                    (new FileTransaction($this->projectDir, $this->projectDir.'/'.$state['backup']))->rollback();
                    $restored = 'Application files were restored.';
                } elseif ($this->updates->headCommit() !== $state['from']['commit']) {
                    $this->updates->resetTo($state['from']['commit']);
                    $restored = 'The checkout was returned to its previous commit.';
                } else {
                    $restored = 'No application file changed.';
                }
                $this->clearCacheDirectory($state['id']);
                $this->maintenance->disable();
                $state['status'] = 'failed';

                return $this->say($state, 'Update stopped while installing files: '.$message.' '.$restored, 'error');
            }
        } catch (\Throwable $recovery) {
            $message .= ' Automatic file recovery also failed: '.$recovery->getMessage();
            $state['error'] = $message;
        }

        $this->maintenance->hold('update');
        $state['status'] = 'needs_attention';

        return $this->say($state, 'Update stopped at "'.$step.'": '.$message.' The site stays in maintenance mode. Fix the cause and run php bin/console app:updates:apply --resume, or restore the previous files with php bin/console app:updates:rollback.', 'error');
    }

    /**
     * What a deployment run starts from and records: the commit the deployment
     * tool deployed, and a fingerprint of the deployed files. "done" explains
     * why nothing needs to run.
     *
     * @param array{commit?: ?string, repository?: ?string, force?: bool} $options
     * @return array{done: ?string, from: array<string, mixed>, to: array<string, mixed>, warnings: list<string>}
     */
    private function prepareDeployment(array $options): array
    {
        $deployment = $this->deploymentState();
        $branch = $this->updates->branch();
        $warnings = [];
        $commit = null;
        $deployedBranch = $branch;
        if (is_string($options['commit'] ?? null) && $options['commit'] !== '') {
            $commit = strtolower(trim($options['commit']));
            if (!DeploymentState::isCommit($commit)) {
                throw new \RuntimeException('--commit needs the full 40-character Git commit hash, for example from git rev-parse HEAD.');
            }
        } else {
            $explicit = is_string($options['repository'] ?? null) && $options['repository'] !== '' ? $options['repository'] : null;
            $repository = $explicit ?? $this->updates->deploymentRepository();
            if ($repository === null) {
                $warnings[] = 'The deployed commit was not recorded: no Git repository was found next to the site, and no --commit or --git-dir was given. Update checks cannot tell how far behind this installation is until one is.';
            } else {
                try {
                    $read = $deployment->readRepository($repository, $branch);
                    $commit = $read['commit'];
                    if ($read['branch'] !== null && $read['branch'] !== $branch) {
                        $warnings[] = 'The deployment repository has '.$read['branch'].' checked out, but updates_branch is '.$branch.', so update checks compare with '.$branch.'. Set updates_branch to the branch you deploy.';
                    }
                    $deployedBranch = $read['branch'] ?? $branch;
                } catch (\RuntimeException $e) {
                    if ($explicit !== null) {
                        throw $e;
                    }
                    $warnings[] = $e->getMessage().' The deployed commit was not recorded.';
                }
            }
        }

        $fingerprint = $deployment->fingerprint();
        $record = $deployment->record();
        $from = ['commit' => $record['commit'] ?? null, 'branch' => $record['branch'] ?? null, 'finished_at' => $record['finished_at'] ?? null];
        $to = ['commit' => $commit, 'branch' => $deployedBranch, 'fingerprint' => $fingerprint];
        $done = null;
        if (!($options['force'] ?? false) && $record !== null && hash_equals($record['fingerprint'], $fingerprint)
            && !$deployment->dependenciesOutOfDate($this->environment !== 'prod')) {
            if ($commit === null || $commit === $record['commit']) {
                $done = 'The post-deployment steps already ran for these files. Add --force to run them again.';
            } elseif ($record['commit'] === null) {
                // Only the commit was missing: record it without pausing the site.
                $deployment->save($commit, $deployedBranch, $fingerprint, (string) ($record['update'] ?? ''));
                $done = 'The post-deployment steps already ran for these files. Recorded their commit '.$commit.'.';
            }
        }

        return ['done' => $done, 'from' => $from, 'to' => $to, 'warnings' => $warnings];
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    private function finishDeployment(array $state): array
    {
        $deployment = $this->deploymentState();
        $fingerprint = (string) ($state['to']['fingerprint'] ?? '');
        if ($fingerprint === '') {
            $fingerprint = $deployment->fingerprint();
        } elseif (!hash_equals($fingerprint, $deployment->fingerprint())) {
            $state = $this->say($state, 'Files changed while the post-deployment steps ran, so they may need another run: run php bin/console app:updates:deployed again.', 'warning');
        }
        try {
            $deployment->save($state['to']['commit'] ?? null, $state['to']['branch'] ?? null, $fingerprint, (string) $state['id']);
        } catch (\RuntimeException $e) {
            // The deployment itself is complete; only the status shown on the Updates page is missing.
            $state = $this->say($state, $e->getMessage().' The Updates page will still show the post-deployment steps as pending.', 'warning');
        }
        $commit = $state['to']['commit'] ?? null;

        return $this->say($state, 'Post-deployment steps complete'.(is_string($commit) ? ' for commit '.$commit : '').'. Maintenance mode is off. If PHP OPcache does not revalidate files on your host, reload PHP (for example PHP-FPM or the web server) now.', 'success');
    }

    private function deploymentState(): DeploymentState
    {
        return $this->deployment ?? new DeploymentState($this->projectDir);
    }

    /** The step after which the site is paused: files change at apply_files, or a deployment pauses first. */
    private function pausedAfter(array $state): string
    {
        return ($state['type'] ?? null) === 'deployment' ? 'maintenance' : 'apply_files';
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    private function composerInstall(array $state): array
    {
        $composer = $this->toolchain->composer() ?? throw new \RuntimeException('Composer was not found. Install it for this user, then run php bin/console app:updates:apply --resume.');
        $arguments = ['install', '--no-interaction', '--no-progress', '--optimize-autoloader'];
        if ($this->environment === 'prod') {
            $arguments[] = '--no-dev';
        }
        $state = $this->say($state, 'Installing Composer dependencies.');
        $environment = $this->childEnvironment() + ['APP_ENV' => $this->environment, 'APP_DEBUG' => $this->debug ? '1' : '0'];
        if (getenv('HOME') === false && getenv('COMPOSER_HOME') === false) {
            $environment['COMPOSER_HOME'] = $this->journal->ensureDirectory('composer-home');
        }
        $this->runProcess($state, 'composer '.implode(' ', $arguments), [...$composer, ...$arguments], 1800, $environment);

        return $state;
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    private function stopWorkers(array $state): array
    {
        try {
            $this->console($state, ['messenger:stop-workers'], 120);
        } catch (\Throwable) {
            $state = $this->say($state, 'Background workers could not be signalled. Restart them with your process manager.', 'warning');
        }

        return $state;
    }

    /** @param array<string, mixed> $state @param list<string> $arguments */
    private function console(array &$state, array $arguments, int $timeout): void
    {
        $this->runProcess($state, 'php bin/console '.implode(' ', $arguments), $this->consoleCommand($arguments), $timeout, $this->childEnvironment());
    }

    /**
     * @param array<string, mixed> $state
     * @param list<string> $command
     * @param array<string, string|false> $environment
     */
    private function runProcess(array &$state, string $label, array $command, int $timeout, array $environment): void
    {
        $state = $this->say($state, '$ '.$label);
        $process = new Process($command, $this->projectDir, $environment, null, $timeout);
        $process->run(function (string $type, string $buffer): void {
            if ($this->output !== null) {
                ($this->output)(rtrim($buffer, "\n"), 'raw');
            }
        });
        if (!$process->isSuccessful()) {
            throw new \RuntimeException('"'.$label.'" failed with exit code '.$process->getExitCode().'. Its output is shown above (for updates started from the dashboard, in var/updates/last-run.log).');
        }
    }

    /** @param list<string> $arguments @return list<string> */
    private function consoleCommand(array $arguments): array
    {
        $php = $this->toolchain->php() ?? throw new \RuntimeException('The command-line PHP executable could not be found. Set PHP_PATH or run the update from the command line.');
        $command = [...$php, $this->projectDir.'/bin/console', ...$arguments, '--env='.$this->environment, '--no-interaction'];
        if (!$this->debug) {
            $command[] = '--no-debug';
        }

        return $command;
    }

    /** @return array<string, string|false> */
    private function childEnvironment(): array
    {
        // Children inherit this process's environment. SYMFONY_DOTENV_VARS tells
        // them which values came from .env files, so they re-read those files.
        return [];
    }

    private function shouldCompileAssets(): bool
    {
        return $this->environment === 'prod' || is_file($this->projectDir.'/public/assets/manifest.json');
    }

    private function clearCacheDirectory(string $id): void
    {
        $cache = $this->projectDir.'/var/cache/'.$this->environment;
        if (!is_dir($cache)) {
            return;
        }
        $stale = $cache.'.stale-'.$id;
        $this->installer->removeTree($stale);
        if (@rename($cache, $stale)) {
            $this->installer->removeTree($stale);
        } else {
            $this->installer->removeTree($cache);
        }
    }

    private function restoreSqlite(string $snapshot): void
    {
        $path = $this->connection->getParams()['path'] ?? null;
        if (!is_string($path) || !is_file($snapshot)) {
            throw new \RuntimeException('The SQLite snapshot or database path is unavailable.');
        }
        $this->connection->close();
        $temporary = $path.'.restore-'.bin2hex(random_bytes(4));
        if (!@copy($snapshot, $temporary) || !@rename($temporary, $path)) {
            @unlink($temporary);
            throw new \RuntimeException('The SQLite database could not be restored. Copy '.$snapshot.' over the database file manually.');
        }
        @unlink($path.'-wal');
        @unlink($path.'-shm');
    }

    private function pruneBackups(): void
    {
        $directory = $this->journal->directory().'/backups';
        $backups = glob($directory.'/*', GLOB_ONLYDIR) ?: [];
        sort($backups);
        foreach (array_slice($backups, 0, max(0, count($backups) - self::KEEP_BACKUPS)) as $old) {
            $this->installer->removeTree($old);
        }
    }

    /** @param array<string, mixed> $state */
    private function stepIndex(array $state, string $step): int
    {
        $steps = self::stepsFor((string) ($state['type'] ?? 'release'));
        $index = array_search($step, $steps, true);

        return $index === false ? -1 : $index;
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    private function say(array $state, string $message, string $level = 'info'): array
    {
        if ($this->output !== null) {
            ($this->output)($message, $level);
        }

        return $this->journal->log($state, $message, $level);
    }

    /**
     * Load everything the running process may need after application files are
     * replaced, so it never mixes classes from two releases.
     */
    private function preload(): void
    {
        foreach ([
            Process::class, PhpExecutableFinder::class, ExecutableFinder::class,
            \Symfony\Component\Process\Pipes\UnixPipes::class, \Symfony\Component\Process\Pipes\AbstractPipes::class,
            \Symfony\Component\Process\Exception\ProcessFailedException::class, \Symfony\Component\Process\Exception\ProcessTimedOutException::class,
            \Symfony\Component\Process\Exception\RuntimeException::class, \Symfony\Component\Process\Exception\LogicException::class,
            \Symfony\Component\Process\Exception\InvalidArgumentException::class, \Symfony\Component\Process\ProcessUtils::class,
            FileTransaction::class, UpdatePaths::class, LocalConfigOverrides::class,
        ] as $class) {
            class_exists($class) || interface_exists($class);
        }
    }
}
