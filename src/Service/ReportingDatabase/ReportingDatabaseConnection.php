<?php

declare(strict_types=1);

namespace App\Service\ReportingDatabase;

use App\Service\ProjectDirEnvVarProcessor;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\MalformedDsnException;
use Doctrine\DBAL\Tools\DsnParser;

/**
 * The reporting database: a second database that holds copies of the selected
 * reporting views as tables, for BI users who should not connect to the
 * application database. Its address is REPORTING_DATABASE_URL, written like
 * DATABASE_URL and kept in .env.local or the server environment because it
 * holds a password. Nothing here returns or logs the password.
 */
class ReportingDatabaseConnection
{
    /**
     * The URL schemes DATABASE_URL accepts (DoctrineBundle's map), with the
     * drivers they select; other schemes name a driver, such as sqlsrv:// for
     * Microsoft's native sqlsrv extension.
     */
    private const SCHEME_MAP = [
        'mssql' => 'pdo_sqlsrv',
        'mysql' => 'pdo_mysql',
        'mysql2' => 'pdo_mysql',
        'postgres' => 'pdo_pgsql',
        'postgresql' => 'pdo_pgsql',
        'pgsql' => 'pdo_pgsql',
        'sqlite' => 'pdo_sqlite',
        'sqlite3' => 'pdo_sqlite',
    ];

    /** Database engines by driver, and their default ports. */
    private const ENGINES = [
        'pdo_mysql' => ['MySQL or MariaDB', 3306],
        'mysqli' => ['MySQL or MariaDB', 3306],
        'pdo_pgsql' => ['PostgreSQL', 5432],
        'pgsql' => ['PostgreSQL', 5432],
        'pdo_sqlsrv' => ['SQL Server', 1433],
        'sqlsrv' => ['SQL Server', 1433],
        'pdo_sqlite' => ['SQLite', null],
        'sqlite3' => ['SQLite', null],
    ];

    private ?Connection $connection = null;

    public function __construct(
        private readonly ?string $url,
        private readonly string $projectDir,
        private readonly Connection $application,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->url !== null && trim($this->url) !== '';
    }

    /**
     * Where the reporting database is, for the admin page and the check
     * command: the engine, server and database name, never the user name or
     * password.
     *
     * @return array{engine: string, host: ?string, port: ?int, database: string}
     *
     * @throws ReportingDatabaseException when the URL is missing or invalid
     */
    public function describe(): array
    {
        $params = $this->params();
        [$engine, $defaultPort] = self::ENGINES[$params['driver']];
        if (in_array($params['driver'], ['pdo_sqlite', 'sqlite3'], true)) {
            $path = (string) $params['path'];

            return ['engine' => $engine, 'host' => null, 'port' => null, 'database' => str_starts_with($path, $this->projectDir.'/') ? substr($path, strlen($this->projectDir) + 1) : $path];
        }

        return [
            'engine' => $engine,
            'host' => isset($params['host']) ? (string) $params['host'] : (isset($params['unix_socket']) ? 'local socket' : 'localhost'),
            'port' => isset($params['port']) ? (int) $params['port'] : $defaultPort,
            'database' => (string) ($params['dbname'] ?? ''),
        ];
    }

    /**
     * A one-line description of a location from describe().
     *
     * @param array{engine: string, host: ?string, port: ?int, database: string} $location
     */
    public static function label(array $location): string
    {
        return $location['host'] === null
            ? $location['engine'].' file '.$location['database']
            : sprintf('%s database %s on %s%s', $location['engine'], $location['database'], $location['host'], $location['port'] !== null ? ':'.$location['port'] : '');
    }

    /**
     * The reporting database connection. It connects on first use.
     *
     * @throws ReportingDatabaseException when the URL is missing or invalid, or
     *                                    names the application database
     */
    public function connection(): Connection
    {
        if ($this->connection === null) {
            $params = $this->params();
            $this->assertSeparateFromApplication($params);
            $this->connection = DriverManager::getConnection($params);
        }

        return $this->connection;
    }

    /** @return array<string, mixed> */
    private function params(): array
    {
        if (!$this->isConfigured()) {
            throw new ReportingDatabaseException('REPORTING_DATABASE_URL is not set. Add it to .env.local or the server environment; see the reporting database guide.');
        }
        try {
            $params = (new DsnParser(self::SCHEME_MAP))->parse(str_replace(ProjectDirEnvVarProcessor::TOKEN, $this->projectDir, trim((string) $this->url)));
        } catch (MalformedDsnException) {
            throw new ReportingDatabaseException('REPORTING_DATABASE_URL is not a valid database URL. Write it like DATABASE_URL, for example postgresql://user:password@host:5432/aggregate_reporting.');
        }
        $driver = $params['driver'] ?? null;
        if (!is_string($driver) || !isset(self::ENGINES[$driver])) {
            throw new ReportingDatabaseException('REPORTING_DATABASE_URL must use a MySQL, MariaDB, PostgreSQL, SQL Server or SQLite database URL.');
        }
        if (in_array($driver, ['pdo_sqlite', 'sqlite3'], true)) {
            if (($params['memory'] ?? false) || !is_string($params['path'] ?? null) || in_array($params['path'], ['', ':memory:'], true)) {
                throw new ReportingDatabaseException('A SQLite reporting database needs a file path, for example sqlite:///%kernel.project_dir%/var/reporting.db.');
            }
        } elseif (!is_string($params['dbname'] ?? null) || $params['dbname'] === '') {
            throw new ReportingDatabaseException('REPORTING_DATABASE_URL must name a database, for example postgresql://user:password@host:5432/aggregate_reporting.');
        }
        if (in_array($driver, ['pdo_mysql', 'mysqli'], true)) {
            $params['charset'] ??= 'utf8mb4';
        }

        return $params;
    }

    /**
     * BI users get the reporting database so that they never reach the raw
     * tables; a URL that leads back to the application database would defeat
     * that, and a sync would replace tables next to the application's own.
     *
     * @param array<string, mixed> $params
     */
    private function assertSeparateFromApplication(array $params): void
    {
        $application = $this->application->getParams();
        $sameEngine = self::ENGINES[$params['driver']][0] === (self::ENGINES[$application['driver'] ?? ''][0] ?? null);
        if (!$sameEngine) {
            return;
        }
        if (in_array($params['driver'], ['pdo_sqlite', 'sqlite3'], true)) {
            $reporting = self::realPath((string) $params['path']);
            $same = is_string($application['path'] ?? null) && $reporting === self::realPath($application['path']);
        } else {
            $defaultPort = self::ENGINES[$params['driver']][1];
            $same = strcasecmp((string) $params['dbname'], (string) ($application['dbname'] ?? '')) === 0
                && (int) ($params['port'] ?? $defaultPort) === (int) ($application['port'] ?? $defaultPort)
                && self::host($params) === self::host($application);
        }
        if ($same) {
            throw new ReportingDatabaseException('REPORTING_DATABASE_URL names the application database. Create a separate database for reporting (it can be on the same server), so BI users never get access to the raw tables.');
        }
    }

    /** @param array<string, mixed> $params */
    private static function host(array $params): string
    {
        $host = strtolower((string) ($params['host'] ?? ''));

        return in_array($host, ['', 'localhost', '127.0.0.1', '::1', '[::1]'], true) ? 'local' : $host;
    }

    private static function realPath(string $path): string
    {
        $real = realpath($path);
        if ($real !== false) {
            return $real;
        }
        // A file that does not exist yet: resolve its folder.
        $folder = realpath(dirname($path));

        return $folder !== false ? $folder.'/'.basename($path) : $path;
    }
}
