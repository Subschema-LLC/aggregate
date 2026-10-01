<?php

declare(strict_types=1);

namespace App\Service\Update;

/**
 * The commands to run after each deployment of code deployed another way, with
 * the paths of this server: as Plesk Git's additional deployment actions, tasks
 * in cPanel's .cpanel.yml, a CI/CD step, or by hand. The Updates page shows them
 * and app:updates:deployed --show-action prints them. Nothing here runs them.
 *
 * Every command uses absolute paths, because tools differ in the directory
 * they run deployment actions from.
 */
class DeploymentAction
{
    /** Where hosting panels keep Composer when it is not on PATH. */
    private const PANEL_COMPOSER = [
        '/opt/psa/var/modules/composer/composer.phar',
        '/opt/cpanel/composer/bin/composer',
        '/usr/local/bin/composer',
    ];

    public function __construct(
        private readonly string $projectDir,
        private readonly string $environment,
        private readonly Toolchain $toolchain,
        private readonly DeploymentState $deployment,
        private readonly SystemCheck $systemCheck,
    ) {
    }

    /**
     * @return array{
     *     commands: list<string>,
     *     script: string,
     *     php: string,
     *     composer: string,
     *     composer_found: bool,
     *     repository: ?string,
     *     repository_source: 'clone'|'detected'|null,
     *     candidates: list<string>,
     *     backup_flag: bool,
     *     environment: string,
     *     project_dir: string
     * }
     */
    public function build(): array
    {
        $php = $this->toolchain->php()[0] ?? 'php';
        [$composer, $found] = $this->composer($php);

        // A clone in the application directory is read without --git-dir. A
        // hosting panel's repository is found automatically too, but naming it
        // keeps the action working if another repository is added later.
        $repository = null;
        $source = null;
        $candidates = [];
        if (file_exists($this->projectDir.'/.git')) {
            $repository = $this->projectDir;
            $source = 'clone';
        } else {
            $candidates = $this->deployment->candidates();
            if (count($candidates) === 1) {
                $repository = $candidates[0];
                $source = 'detected';
            }
        }

        $project = $this->projectDir;
        $commands = [];
        $cache = $project.'/var/cache/'.$this->environment;
        $commands[] = \DIRECTORY_SEPARATOR === '\\'
            ? 'if exist '.$this->quote($cache).' rmdir /s /q '.$this->quote($cache)
            : 'rm -rf '.$this->quote($cache);
        $install = [...$composer, 'install', '--working-dir='.$project, '--no-interaction', '--optimize-autoloader'];
        if ($this->environment === 'prod') {
            $install[] = '--no-dev';
        }
        $commands[] = implode(' ', array_map($this->quote(...), $install));
        $deployed = [$php, $project.'/bin/console', 'app:updates:deployed'];
        if ($this->environment !== 'prod') {
            $deployed[] = '--env='.$this->environment;
        }
        if ($source === 'detected') {
            $deployed[] = '--git-dir='.$repository;
        } elseif ($source === null) {
            $deployed[] = '--git-dir=/path/to/the/deployment/repository.git';
        }
        $backupFlag = !$this->systemCheck->isSqlite();
        if ($backupFlag) {
            $deployed[] = '--database-backup-confirmed';
        }
        $commands[] = implode(' ', array_map($this->quote(...), $deployed));

        return [
            'commands' => $commands,
            'script' => implode("\n", $commands),
            'php' => $php,
            'composer' => implode(' ', $composer),
            'composer_found' => $found,
            'repository' => $repository,
            'repository_source' => $source,
            'candidates' => $candidates,
            'backup_flag' => $backupFlag,
            'environment' => $this->environment,
            'project_dir' => $project,
        ];
    }

    /** @return array{0: list<string>, 1: bool} The Composer command, and whether it was found */
    private function composer(string $php): array
    {
        $composer = $this->toolchain->composer();
        if ($composer !== null) {
            return [$composer, true];
        }
        foreach (self::PANEL_COMPOSER as $candidate) {
            if (@is_file($candidate)) {
                return [str_ends_with($candidate, '.phar') ? [$php, $candidate] : [$candidate], true];
            }
        }
        // open_basedir can hide Plesk's Composer from the web server.
        if (str_starts_with($php, '/opt/plesk/php/')) {
            return [[$php, self::PANEL_COMPOSER[0]], false];
        }

        return [['composer'], false];
    }

    private function quote(string $argument): string
    {
        return preg_match('#^[A-Za-z0-9_./:=@%+,-]+$#', $argument) === 1 ? $argument : escapeshellarg($argument);
    }
}
