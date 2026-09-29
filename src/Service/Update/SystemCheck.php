<?php

declare(strict_types=1);

namespace App\Service\Update;

use App\Service\ApplicationUpdateService;
use App\Service\FeatureFlags;
use App\Service\InstalledRelease;
use App\Service\ReleaseMetadata;
use App\Service\ReleasePackageVerifier;
use App\Service\UpdateSettings;
use Doctrine\DBAL\Connection;

/**
 * Everything an update depends on, checked without network access or changes.
 * The Updates page shows the result for the web server user; the command line
 * (app:updates:apply --preflight) shows it for the user running the command.
 *
 * An "error" stops an update in that context. Checks with the "dashboard" scope
 * only matter for starting updates from the dashboard.
 */
class SystemCheck
{
    public const OK = 'ok';
    public const INFO = 'info';
    public const WARNING = 'warning';
    public const ERROR = 'error';

    private const REQUIRED_EXTENSIONS = ['ctype', 'iconv', 'pdo', 'mbstring', 'xml', 'curl', 'intl', 'sodium', 'zip'];
    private const WRITABLE = ['', 'bin', 'config', 'migrations', 'public', 'src', 'templates', 'var', 'vendor'];
    private const MIN_FREE_BYTES = 300 * 1024 * 1024;

    public function __construct(
        private readonly string $projectDir,
        private readonly ApplicationUpdateService $updates,
        private readonly UpdateSettings $settings,
        private readonly InstalledRelease $installed,
        private readonly ReleasePackageVerifier $verifier,
        private readonly UpdateJournal $journal,
        private readonly MaintenanceMode $maintenance,
        private readonly FeatureFlags $features,
        private readonly Connection $connection,
        private readonly Toolchain $toolchain,
    ) {
    }

    /**
     * @return list<array{id: string, label: string, status: string, detail: string, scope: string}>
     */
    public function run(): array
    {
        $checks = [];
        $add = static function (string $id, string $label, string $status, string $detail, string $scope = 'update') use (&$checks): void {
            $checks[] = ['id' => $id, 'label' => $label, 'status' => $status, 'detail' => $detail, 'scope' => $scope];
        };

        $add('feature', 'Updates feature', $this->features->isEnabled('updates') ? self::OK : self::ERROR,
            $this->features->isEnabled('updates') ? 'Enabled.' : 'Disabled by feature_flags.updates.enabled.');

        $source = null;
        try {
            $source = $this->updates->source();
            $repository = $this->settings->repository();
            $branch = $this->settings->branch();
            $add('source', 'Update source', self::OK, sprintf(
                '%s from %s, branch %s. %s',
                $source['source'] === 'git' ? 'Git pull' : 'Signed release packages',
                $repository,
                $branch,
                $source['reason'],
            ));
            if (!$this->settings->isOfficialRepository()) {
                $add('repository', 'Repository', self::WARNING, 'updates_repository points to '.$repository.' instead of '.UpdateSettings::DEFAULT_REPOSITORY.'. Only use a repository you control; releases must be signed with the key this installation trusts.');
            }
        } catch (\Throwable $e) {
            $add('source', 'Update source', self::ERROR, 'Invalid update settings: '.$e->getMessage());
        }

        $missing = array_values(array_filter(self::REQUIRED_EXTENSIONS, static fn (string $extension): bool => !extension_loaded($extension)));
        $driver = $this->databaseDriverExtension();
        if ($driver !== null && !extension_loaded($driver)) {
            $missing[] = $driver;
        }
        $add('php', 'PHP runtime', $missing === [] ? self::OK : self::ERROR, $missing === []
            ? 'PHP '.PHP_VERSION.' ('.PHP_SAPI.') with the required extensions.'
            : 'PHP '.PHP_VERSION.' is missing: '.implode(', ', $missing).'.');

        if ($source !== null && $source['source'] === 'release') {
            $this->releaseChecks($add);
        } elseif ($source !== null) {
            $this->gitChecks($add);
        }

        $unwritable = [];
        foreach (self::WRITABLE as $directory) {
            $path = rtrim($this->projectDir.'/'.$directory, '/');
            if (is_dir($path) && !is_writable($path)) {
                $unwritable[] = $directory === '' ? 'the application directory' : $directory.'/';
            }
        }
        $user = $this->userName();
        $add('writable', 'Application files writable', $unwritable === [] ? self::OK : self::ERROR, $unwritable === []
            ? 'User '.$user.' can replace application files.'
            : 'User '.$user.' cannot write '.implode(', ', $unwritable).'. Run php bin/console app:updates:apply as the user that owns the files, or give this user write access to use the dashboard button.');

        $php = $this->toolchain->php();
        $background = function_exists('proc_open') && \DIRECTORY_SEPARATOR !== '\\' && $php !== null;
        $add('background', 'Dashboard button can start updates', $background ? self::OK : self::ERROR, $background
            ? 'Command-line PHP found at '.$php[0].'.'
            : ($php === null ? 'The command-line PHP executable was not found. Set PHP_PATH for the web server.' : 'Starting background processes (proc_open on Unix) is not available.').' Use php bin/console app:updates:apply instead.', 'dashboard');

        $free = @disk_free_space($this->projectDir);
        if (is_float($free)) {
            $add('disk', 'Free disk space', $free >= self::MIN_FREE_BYTES ? self::OK : self::WARNING, sprintf(
                '%s free.%s',
                $this->bytes($free),
                $free >= self::MIN_FREE_BYTES ? '' : ' Updates need room for the download, a staging copy and the backup.',
            ));
        }

        $sqlite = $this->isSqlite();
        $add('database', 'Database backup', self::INFO, $sqlite
            ? 'SQLite: a snapshot is saved automatically before migrations run.'
            : ucfirst($this->databaseName()).': back up the database before updating. Migrations cannot be reversed automatically, so you will be asked to confirm a backup.');

        $opcache = function_exists('opcache_get_status') && filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOL)
            && !filter_var(ini_get('opcache.validate_timestamps'), FILTER_VALIDATE_BOOL);
        $add('opcache', 'PHP OPcache', $opcache ? self::WARNING : self::OK, $opcache
            ? 'opcache.validate_timestamps is off, so PHP keeps running old code after an update until PHP-FPM is reloaded. Reload it after each update.'
            : 'PHP picks up changed files after an update.');

        $maintenance = $this->maintenance->status();
        $add('maintenance', 'Maintenance mode', $maintenance !== null && $maintenance['active'] ? self::WARNING : self::OK,
            $maintenance !== null && $maintenance['active'] ? 'On: visitors see the maintenance page. Turn it off with php bin/console app:updates:maintenance off once resolved.' : 'Off.');

        $last = $this->journal->read();
        if ($this->journal->isLocked()) {
            $add('last_update', 'Update activity', self::ERROR, 'An update is running now.');
        } elseif (in_array($last['status'] ?? null, ['running', 'needs_attention'], true)) {
            $add('last_update', 'Update activity', self::ERROR, 'The last update stopped at "'.($last['step'] ?? 'unknown').'". Continue it with php bin/console app:updates:apply --resume or restore files with php bin/console app:updates:rollback.');
        } else {
            $add('last_update', 'Update activity', self::OK, $last === null ? 'No update has run yet.' : 'Last update '.$last['id'].': '.str_replace('_', ' ', (string) $last['status']).'.');
        }

        return $checks;
    }

    /**
     * Errors that stop an update in this context.
     *
     * @param list<string> $ignore Check ids the caller handles itself
     * @param list<array<string, string>>|null $checks A result of run() to reuse
     * @return list<string>
     */
    public function problems(bool $dashboard = false, array $ignore = [], ?array $checks = null): array
    {
        $problems = [];
        foreach ($checks ?? $this->run() as $check) {
            if ($check['status'] === self::ERROR && !in_array($check['id'], $ignore, true)
                && ($dashboard || $check['scope'] === 'update')) {
                $problems[] = $check['detail'];
            }
        }

        return $problems;
    }

    /**
     * @param list<array<string, string>>|null $checks A result of run() to reuse
     * @return list<string>
     */
    public function warnings(?array $checks = null): array
    {
        return array_values(array_map(
            static fn (array $check): string => $check['detail'],
            array_filter($checks ?? $this->run(), static fn (array $check): bool => $check['status'] === self::WARNING),
        ));
    }

    public function isSqlite(): bool
    {
        return in_array((string) ($this->connection->getParams()['driver'] ?? ''), ['pdo_sqlite', 'sqlite3'], true);
    }

    /** @param callable(string, string, string, string, string=): void $add */
    private function releaseChecks(callable $add): void
    {
        try {
            $installed = $this->installed->read();
            $add('release_metadata', 'Installed release', $installed === null ? self::WARNING : self::OK, $installed === null
                ? 'No release.json, so the installed version is unknown. Installing a release records it. If another tool deploys this directory (for example Plesk Git deployment), turn its automatic deployment off first, or it will overwrite installed updates.'
                : 'Version '.$installed['version'].(ReleaseMetadata::isCalendarVersion($installed['version']) ? '' : ' (earlier numbering)').' from '.$installed['repository'].'.');
        } catch (\RuntimeException $e) {
            $add('release_metadata', 'Installed release', self::ERROR, $e->getMessage());
        }
        $inventory = is_file($this->projectDir.'/'.UpdatePaths::INVENTORY);
        $add('inventory', 'File inventory', $inventory ? self::OK : self::INFO, $inventory
            ? 'release-files.json lets updates tell your edits apart from shipped files.'
            : 'No release-files.json yet. The first release update keeps any shipped configuration default that differs from the release as a .local.yaml override; review those files afterwards.');
        $key = $this->verifier->trustedKeyStatus();
        $add('signing_key', 'Release signing key', $key['ok'] ? self::OK : self::ERROR, $key['detail']);
    }

    /** @param callable(string, string, string, string, string=): void $add */
    private function gitChecks(callable $add): void
    {
        $git = $this->toolchain->git();
        $add('git', 'Git', $git !== null ? self::OK : self::ERROR, $git !== null ? 'Found at '.$git.'.' : 'Git is not installed or not on PATH for this user.');
        if (!file_exists($this->projectDir.'/.git')) {
            $add('checkout', 'Git checkout', self::ERROR, 'The application directory has no .git folder, so it cannot pull. Tools such as Plesk Git deployment copy files without it: set updates_source: release to update from release packages instead.');
        } elseif ($git !== null) {
            $problem = $this->updates->pullProblem();
            $add('checkout', 'Git checkout', $problem === null ? self::OK : self::ERROR, $problem ?? 'Clean checkout on the configured branch.');
        }
        $composer = $this->toolchain->composer();
        $add('composer', 'Composer', $composer !== null ? self::OK : self::WARNING, $composer !== null
            ? 'Found; dependency changes are installed automatically.'
            : 'Not found. Updates that change composer.lock or importmap.php stop before changing files. Install Composer or set AGGREGATE_COMPOSER to its path.');
    }

    private function databaseDriverExtension(): ?string
    {
        return match ((string) ($this->connection->getParams()['driver'] ?? '')) {
            'pdo_sqlite' => 'pdo_sqlite',
            'pdo_mysql' => 'pdo_mysql',
            'pdo_pgsql' => 'pdo_pgsql',
            'pdo_sqlsrv' => 'pdo_sqlsrv',
            'sqlsrv' => 'sqlsrv',
            default => null,
        };
    }

    private function databaseName(): string
    {
        return match ((string) ($this->connection->getParams()['driver'] ?? '')) {
            'pdo_mysql', 'mysqli' => 'MySQL or MariaDB',
            'pdo_pgsql', 'pgsql' => 'PostgreSQL',
            'pdo_sqlsrv', 'sqlsrv' => 'SQL Server',
            default => 'the database',
        };
    }

    private function userName(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $user = posix_getpwuid(posix_geteuid());
            if (is_array($user) && is_string($user['name'] ?? null)) {
                return $user['name'];
            }
        }

        return get_current_user() ?: 'this user';
    }

    private function bytes(float $bytes): string
    {
        return $bytes >= 1073741824 ? round($bytes / 1073741824, 1).' GB' : round($bytes / 1048576).' MB';
    }
}
