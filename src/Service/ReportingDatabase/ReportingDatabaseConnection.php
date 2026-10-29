<?php

declare(strict_types=1);

namespace App\Service\ReportingDatabase;

use App\Service\ProjectDirEnvVarProcessor;
use App\Service\ViewSync\ViewCatalog;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\MalformedDsnException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
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

    /**
     * The database types the admin page and app:reporting-database:configure
     * offer, with the URL scheme and PHP extension each needs.
     */
    public const TYPES = [
        'mysql' => ['label' => 'MySQL or MariaDB', 'scheme' => 'mysql', 'extension' => 'pdo_mysql', 'port' => 3306],
        'pgsql' => ['label' => 'PostgreSQL', 'scheme' => 'postgresql', 'extension' => 'pdo_pgsql', 'port' => 5432],
        'sqlsrv' => ['label' => 'SQL Server', 'scheme' => 'mssql', 'extension' => 'pdo_sqlsrv', 'port' => 1433],
        'sqlite' => ['label' => 'SQLite file', 'scheme' => 'sqlite', 'extension' => 'pdo_sqlite', 'port' => null],
    ];
    public const DEFAULT_SQLITE_FILE = 'var/reporting.db';
    /**
     * The word that marks a database, in its own comment, as a read-only
     * reporting copy for BI users, optionally followed by source=<the
     * application database>, for example
     * "Read-only reporting copy for BI tools [aggregate-reporting-copy source=aggregate]".
     */
    public const MARK = 'aggregate-reporting-copy';
    private const CHECK_TABLE = 'aggregate_sync_check';

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

    /** The same application database with another reporting database URL, to test it before saving. */
    public function withUrl(string $url): self
    {
        return new self($url, $this->projectDir, $this->application);
    }

    /**
     * A REPORTING_DATABASE_URL from form fields, written like DATABASE_URL.
     * Credentials and the database name are percent-encoded, so the result
     * holds no quotes, spaces or backslashes. A relative SQLite path is under
     * the application folder.
     *
     * @throws \InvalidArgumentException with a message for the administrator
     */
    public static function buildUrl(string $type, string $host, ?int $port, string $database, string $user, string $password, bool $trustCertificate = false): string
    {
        if (!isset(self::TYPES[$type])) {
            throw new \InvalidArgumentException('Choose MySQL or MariaDB, PostgreSQL, SQL Server or SQLite.');
        }
        $database = trim($database);
        if ($type === 'sqlite') {
            $path = $database === '' ? self::DEFAULT_SQLITE_FILE : $database;
            if (preg_match('/[\x00-\x1f\x7f\'"\\\\%?#]/', $path) === 1 || str_contains('/'.$path.'/', '/../')) {
                throw new \InvalidArgumentException('Enter the SQLite file as a plain path, such as '.self::DEFAULT_SQLITE_FILE.'.');
            }

            return 'sqlite:///'.(str_starts_with($path, '/') ? $path : ProjectDirEnvVarProcessor::TOKEN.'/'.$path);
        }
        $host = trim($host);
        if ($host === '' || strlen($host) > 255 || preg_match('/[^A-Za-z0-9.\-:_\[\]]/', $host) === 1) {
            throw new \InvalidArgumentException('Enter the database server’s host name or IP address.');
        }
        if ($port !== null && ($port < 1 || $port > 65535)) {
            throw new \InvalidArgumentException('The port must be between 1 and 65535.');
        }
        if ($database === '' || strlen($database) > 128 || preg_match('/[\x00-\x1f\x7f]/', $database) === 1) {
            throw new \InvalidArgumentException('Enter the reporting database’s name.');
        }
        if (trim($user) === '' || strlen($user) > 128 || preg_match('/[\x00-\x1f\x7f]/', $user) === 1) {
            throw new \InvalidArgumentException('Enter the database user name.');
        }
        if (strlen($password) > 1024 || str_contains($password, "\0")) {
            throw new \InvalidArgumentException('The password is too long.');
        }
        $host = str_contains($host, ':') && !str_starts_with($host, '[') ? '['.$host.']' : $host;

        return sprintf(
            '%s://%s:%s@%s:%d/%s%s',
            self::TYPES[$type]['scheme'],
            rawurlencode(trim($user)),
            rawurlencode($password),
            $host,
            $port ?? self::TYPES[$type]['port'],
            rawurlencode($database),
            $type === 'sqlsrv' && $trustCertificate ? '?driverOptions[TrustServerCertificate]=1' : '',
        );
    }

    /**
     * The configured URL as form fields, without the password; null when the
     * URL is missing or cannot be read.
     *
     * @return array{type: string, host: string, port: string, database: string, user: string, trust_certificate: bool, has_password: bool}|null
     */
    public function fields(): ?array
    {
        try {
            $params = $this->params();
        } catch (ReportingDatabaseException) {
            return null;
        }
        $type = match ($params['driver']) {
            'pdo_mysql', 'mysqli' => 'mysql',
            'pdo_pgsql', 'pgsql' => 'pgsql',
            'pdo_sqlsrv', 'sqlsrv' => 'sqlsrv',
            default => 'sqlite',
        };
        if ($type === 'sqlite') {
            return ['type' => 'sqlite', 'host' => '', 'port' => '', 'database' => $this->describe()['database'], 'user' => '', 'trust_certificate' => false, 'has_password' => false];
        }

        return [
            'type' => $type,
            'host' => (string) ($params['host'] ?? ''),
            'port' => isset($params['port']) ? (string) $params['port'] : '',
            'database' => (string) ($params['dbname'] ?? ''),
            'user' => (string) ($params['user'] ?? ''),
            'trust_certificate' => (string) ($params['driverOptions']['TrustServerCertificate'] ?? '') === '1',
            'has_password' => (string) ($params['password'] ?? '') !== '',
        ];
    }

    /**
     * The configured password, so a form left blank keeps it. Only for
     * building a new URL; never shown.
     */
    public function password(): string
    {
        try {
            return (string) ($this->params()['password'] ?? '');
        } catch (ReportingDatabaseException) {
            return '';
        }
    }

    /**
     * Connects, and creates and drops a small table to prove the sync may
     * replace tables, without touching the copied views. Reports the
     * database's mark, and refuses a database marked as another
     * installation's copy.
     *
     * @return array{engine: string, host: ?string, port: ?int, database: string, server_version: string, tables: list<string>, mark: array{source: ?string}|null}
     *
     * @throws ReportingDatabaseException with a message for the administrator
     */
    public function probe(): array
    {
        $location = $this->describe();
        try {
            $connection = $this->connection();
            $version = $connection->getServerVersion();
        } catch (ReportingDatabaseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ReportingDatabaseException(self::firstLine($e, 'The reporting database could not be reached'), previous: $e);
        }
        try {
            $platform = $connection->getDatabasePlatform();
            $table = $platform->quoteSingleIdentifier(self::CHECK_TABLE);
            $connection->executeStatement('DROP TABLE IF EXISTS '.$table);
            $connection->executeStatement('CREATE TABLE '.$table.' (checked_at '.$platform->getDateTimeTypeDeclarationSQL([]).' NULL)');
            $connection->executeStatement('DROP TABLE '.$table);
        } catch (\Throwable $e) {
            throw new ReportingDatabaseException(self::firstLine($e, 'Connected, but this database user cannot create and drop tables, which every sync does'), previous: $e);
        }
        $mark = $this->assertMarkAllowsSync();
        $writer = new ReportingTableWriter($connection);
        $copied = array_values(array_filter(array_keys(ViewCatalog::APPROVED + ViewCatalog::PRIVATE), $writer->tableExists(...)));

        return [...$location, 'server_version' => $version, 'tables' => $copied, 'mark' => $mark];
    }

    /**
     * The reporting database's mark, from its own comment, or null when it has
     * none. A marked database is a read-only reporting copy for BI users: they
     * read every table in it, so only approved views are copied there. MariaDB
     * (10.5 and later) and PostgreSQL keep database comments; MySQL, SQL
     * Server and SQLite databases have none, and so no mark.
     *
     * @return array{source: ?string}|null
     *
     * @throws ReportingDatabaseException when the URL is missing or invalid
     */
    public function mark(): ?array
    {
        $connection = $this->connection();
        try {
            $platform = $connection->getDatabasePlatform();
            $comment = match (true) {
                // MySQL has no SCHEMA_COMMENT column; the query fails there, and that is no mark.
                $platform instanceof AbstractMySQLPlatform => $connection->fetchOne('SELECT SCHEMA_COMMENT FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = DATABASE()'),
                $platform instanceof PostgreSQLPlatform => $connection->fetchOne("SELECT pg_catalog.shobj_description(oid, 'pg_database') FROM pg_catalog.pg_database WHERE datname = current_database()"),
                default => null,
            };
        } catch (\Doctrine\DBAL\Exception) {
            return null;
        }

        return self::parseMark(is_string($comment) ? $comment : '');
    }

    /**
     * The mark in a database comment: the word aggregate-reporting-copy, on
     * its own, optionally followed by source=<database name>.
     *
     * @return array{source: ?string}|null
     */
    public static function parseMark(string $comment): ?array
    {
        if (preg_match('/(?<![A-Za-z0-9_-])'.preg_quote(self::MARK, '/').'(?![A-Za-z0-9_-])(?:\s+source=([A-Za-z0-9_$-]{1,64}))?/i', $comment, $match) !== 1) {
            return null;
        }

        return ['source' => ($match[1] ?? '') !== '' ? $match[1] : null];
    }

    /**
     * The mark, after checking it allows this installation to write the
     * copy: a database marked as the copy of another database belongs to
     * another installation, whose tables a sync from here would replace.
     *
     * @return array{source: ?string}|null
     *
     * @throws ReportingDatabaseException when the mark names another source
     */
    public function assertMarkAllowsSync(): ?array
    {
        $mark = $this->mark();
        $application = $this->applicationDatabase();
        if ($mark !== null && $mark['source'] !== null && $application !== null && strcasecmp($mark['source'], $application) !== 0) {
            throw new ReportingDatabaseException(sprintf(
                'The reporting database is marked as the reporting copy of the database %s, not of this installation\'s database %s, so Aggregate does not write to it. Use a reporting database of its own, or change the mark in the database\'s comment if this copy now belongs to this installation.',
                $mark['source'],
                $application,
            ));
        }

        return $mark;
    }

    /** The application database's name on a database server, or null for SQLite. */
    public function applicationDatabase(): ?string
    {
        $name = $this->application->getParams()['dbname'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * What a mark means, for messages; empty for an unmarked database.
     *
     * @param array{source: ?string}|null $mark
     */
    public static function markSentence(?array $mark): string
    {
        return $mark === null ? '' : 'It is marked as a read-only reporting copy for BI users'
            .($mark['source'] !== null ? ' of '.$mark['source'] : '').', so only approved views are copied to it.';
    }

    /**
     * A database error's first line, which names hosts, users and SQL states
     * for connections and table changes but no stored value.
     */
    public static function firstLine(\Throwable $e, string $context): string
    {
        if (!$e instanceof \Doctrine\DBAL\Exception) {
            return $context.' ('.(new \ReflectionClass($e))->getShortName().'); see the application log.';
        }
        $detail = trim((string) strtok($e->getMessage(), "\n"));
        if (strlen($detail) > 300) {
            $detail = substr($detail, 0, 297).'...';
        }
        $state = $e instanceof \Doctrine\DBAL\Exception\DriverException && $e->getSQLState() !== null ? ' (SQLSTATE '.$e->getSQLState().')' : '';

        return $context.$state.': '.$detail;
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
