<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Named IANA zones on MySQL and MariaDB need the server's time zone tables.
 * The application user cannot load them. An administrator does, with one
 * command on the database server, or this process when it can administer a
 * local server.
 */
final class MysqlTimeZoneTables
{
    /** Winter and summer, so a loaded zone must know both offsets. */
    private const PROBES = ['2024-01-15 12:00:00', '2024-07-15 12:00:00'];

    private ?bool $mariaDb = null;

    /**
     * @param string      $pleskShadowPath Plesk's admin password file, when this host is Plesk
     * @param string|null $forcedLoader    set by tests; no discovery when it is not executable
     * @param string|null $forcedClient    set by tests; no discovery when it is not executable
     * @param string|null $forcedZoneinfo  set by tests; no discovery when it is not a directory
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly string $pleskShadowPath = '/etc/psa/.psa.shadow',
        private readonly ?string $forcedLoader = null,
        private readonly ?string $forcedClient = null,
        private readonly ?string $forcedZoneinfo = null,
    ) {
    }

    public function applies(): bool
    {
        return $this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;
    }

    public function converts(string $timezone): bool
    {
        foreach (self::PROBES as $instant) {
            try {
                $converted = $this->connection->fetchOne('SELECT CONVERT_TZ(?, ?, ?)', [$instant, 'UTC', $timezone]);
            } catch (\Throwable) {
                return false;
            }
            if ($converted === null || $converted === false || $converted === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * The command an administrator runs before $timezone can be compiled
     * into the views, or null when this database needs none.
     */
    public function loadCommandFor(string $timezone): ?string
    {
        if (!$this->applies() || ReportingTimezone::isIdentity($timezone) || $this->converts($timezone)) {
            return null;
        }

        return $this->operatorCommand();
    }

    public function assertConverts(string $timezone): void
    {
        if ($this->converts($timezone)) {
            return;
        }

        $command = $this->operatorCommand();
        throw new MysqlTimeZoneTablesMissing($command, sprintf(
            "MySQL cannot convert %s. On the database server, load the time zone tables, then run app:analytics:bi-views:regenerate. The existing views were left unchanged.\n%s\nOr run app:analytics:mysql-timezones:load where this server can administer MySQL.",
            $timezone,
            $command,
        ));
    }

    /**
     * The one command an administrator runs on the database server.
     * Plesk's administrator is admin; other hosts use root and a password prompt.
     */
    public function operatorCommand(): string
    {
        [$loader, $client] = $this->toolNames();
        if (is_file($this->pleskShadowPath)) {
            return sprintf(
                '%s /usr/share/zoneinfo | %s -u admin -p"$(sudo cat /etc/psa/.psa.shadow)" mysql',
                $loader,
                $client,
            );
        }

        return sprintf('%s /usr/share/zoneinfo | %s -u root -p mysql', $loader, $client);
    }

    /**
     * Load the tables when this process can administer a local server.
     * Returns a status string. Throws when the operator must run the command.
     */
    public function load(): string
    {
        if (!$this->applies()) {
            return 'This database does not need MySQL time zone tables.';
        }
        if ($this->converts('America/Chicago')) {
            return 'Time zone tables already convert named zones.';
        }

        $loader = $this->loaderBinary();
        $client = $this->clientBinary();
        $zoneinfo = $this->zoneinfoDirectory();
        if ($loader === null || $client === null || $zoneinfo === null) {
            throw new \RuntimeException($this->cannotLoadMessage());
        }

        $auth = $this->adminAuth($client);
        if ($auth === null) {
            throw new \RuntimeException($this->cannotLoadMessage());
        }

        try {
            $this->emptyTablesIfNeeded($auth);
            $this->importZoneinfo($loader, $zoneinfo, $auth);
        } finally {
            $this->discard($auth);
        }

        if (!$this->converts('America/Chicago')) {
            throw new \RuntimeException(
                'The time zone tables were loaded, but MySQL still cannot convert America/Chicago. Restart the database server so it drops cached time zone data, then run app:analytics:mysql-timezones:load again.',
            );
        }

        return 'Loaded MySQL time zone tables. Named zones convert.';
    }

    /** @return array{0: string, 1: string} loader and client names */
    private function toolNames(): array
    {
        if ($this->isMariaDb()) {
            return ['mariadb-tzinfo-to-sql', 'mariadb'];
        }

        return ['mysql_tzinfo_to_sql', 'mysql'];
    }

    private function cannotLoadMessage(): string
    {
        return "This process cannot load the time zone tables. On the database server, run:\n".$this->operatorCommand();
    }

    private function isMariaDb(): bool
    {
        if ($this->mariaDb !== null) {
            return $this->mariaDb;
        }
        try {
            $version = $this->connection->fetchOne('SELECT VERSION()');
        } catch (\Throwable) {
            return $this->mariaDb = false;
        }

        return $this->mariaDb = is_string($version) && str_contains(strtolower($version), 'mariadb');
    }

    private function loaderBinary(): ?string
    {
        if ($this->forcedLoader !== null) {
            return is_executable($this->forcedLoader) ? $this->forcedLoader : null;
        }
        $finder = new ExecutableFinder();
        $names = $this->isMariaDb()
            ? ['mariadb-tzinfo-to-sql', 'mysql_tzinfo_to_sql']
            : ['mysql_tzinfo_to_sql', 'mariadb-tzinfo-to-sql'];
        foreach ($names as $name) {
            $found = $finder->find($name);
            if (is_string($found) && $found !== '') {
                return $found;
            }
        }

        return null;
    }

    private function clientBinary(): ?string
    {
        if ($this->forcedClient !== null) {
            return is_executable($this->forcedClient) ? $this->forcedClient : null;
        }
        $finder = new ExecutableFinder();
        [, $preferred] = $this->toolNames();
        $found = $finder->find($preferred) ?? $finder->find($preferred === 'mariadb' ? 'mysql' : 'mariadb');

        return is_string($found) && $found !== '' ? $found : null;
    }

    private function zoneinfoDirectory(): ?string
    {
        if ($this->forcedZoneinfo !== null) {
            return is_dir($this->forcedZoneinfo) ? $this->forcedZoneinfo : null;
        }
        foreach (['/usr/share/zoneinfo', '/usr/share/lib/zoneinfo'] as $directory) {
            if (is_dir($directory)) {
                return $directory;
            }
        }

        return null;
    }

    /**
     * @return array{args: list<string>, cleanup: ?string}|null
     */
    private function adminAuth(string $client): ?array
    {
        $candidates = [];
        if (is_readable($this->pleskShadowPath)) {
            $password = rtrim((string) file_get_contents($this->pleskShadowPath), "\r\n");
            if ($password !== '') {
                $candidates[] = $this->defaultsAuth($client, 'admin', $password);
            }
        }
        $candidates[] = ['args' => [$client, '--protocol=socket', '-u', 'root'], 'cleanup' => null];
        foreach (['MYSQL_ROOT_PASSWORD', 'MARIADB_ROOT_PASSWORD'] as $variable) {
            $password = getenv($variable);
            if (is_string($password) && $password !== '') {
                $candidates[] = $this->defaultsAuth($client, 'root', $password);
            }
        }

        foreach ($candidates as $auth) {
            if ($this->canConnect($auth)) {
                return $auth;
            }
            $this->discard($auth);
        }

        return null;
    }

    /**
     * @return array{args: list<string>, cleanup: ?string}
     */
    private function defaultsAuth(string $client, string $user, string $password): array
    {
        $path = tempnam(sys_get_temp_dir(), 'aggregate-tz-');
        if ($path === false) {
            throw new \RuntimeException($this->cannotLoadMessage());
        }
        chmod($path, 0600);
        $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $password);
        file_put_contents($path, "[client]\nuser={$user}\npassword=\"{$escaped}\"\n");

        return ['args' => [$client, '--defaults-extra-file='.$path, '--protocol=socket'], 'cleanup' => $path];
    }

    /** @param array{args: list<string>, cleanup: ?string} $auth */
    private function canConnect(array $auth): bool
    {
        try {
            $output = $this->runClient($auth, ['-Nse', 'SELECT 1'], null, 15);
        } catch (\Throwable) {
            return false;
        }

        return trim($output) === '1';
    }

    /** @param array{args: list<string>, cleanup: ?string} $auth */
    private function emptyTablesIfNeeded(array $auth): void
    {
        $count = trim($this->runClient($auth, ['-Nse', 'SELECT COUNT(*) FROM mysql.time_zone_name']));
        if ($count === '' || $count === '0') {
            return;
        }

        $this->runClient($auth, ['mysql'], <<<'SQL'
SET FOREIGN_KEY_CHECKS=0;
TRUNCATE TABLE time_zone_transition;
TRUNCATE TABLE time_zone_transition_type;
TRUNCATE TABLE time_zone_name;
TRUNCATE TABLE time_zone;
SET FOREIGN_KEY_CHECKS=1;
SQL);
    }

    /** @param array{args: list<string>, cleanup: ?string} $auth */
    private function importZoneinfo(string $loader, string $zoneinfo, array $auth): void
    {
        $process = new Process([$loader, $zoneinfo]);
        $process->setTimeout(180);
        $process->run();
        $sql = $process->getOutput();
        if (trim($sql) === '') {
            throw new \RuntimeException($this->cannotLoadMessage());
        }

        $this->runClient($auth, ['mysql'], $sql);
    }

    /**
     * @param array{args: list<string>, cleanup: ?string} $auth
     * @param list<string>                                 $extra
     */
    private function runClient(array $auth, array $extra, ?string $input = null, int $timeout = 180): string
    {
        $process = new Process([...$auth['args'], ...$extra], null, null, $input, $timeout);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new \RuntimeException($this->cannotLoadMessage());
        }

        return $process->getOutput();
    }

    /** @param array{args: list<string>, cleanup: ?string} $auth */
    private function discard(array $auth): void
    {
        if ($auth['cleanup'] !== null && is_file($auth['cleanup'])) {
            unlink($auth['cleanup']);
        }
    }
}
