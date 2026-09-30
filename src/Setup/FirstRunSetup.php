<?php

declare(strict_types=1);

namespace App\Setup;

/**
 * Browser setup for a fresh installation, served by config/setup.php before
 * Composer or the kernel load. It lets someone who uploaded a release ZIP with a
 * hosting panel's file manager finish setup without a terminal:
 *
 *  1. check the server (PHP, extensions, prepared release files, web root,
 *     write access) and say how to fix each problem;
 *  2. confirm the visitor can read the server's files (SETUP-CODE.txt);
 *  3. test the database connection, detect the server version, and write
 *     .env.local with a newly generated APP_SECRET;
 *  4. hand over to /install in the same browser to create the administrator.
 *
 * Keep this class free of dependencies other than SetupCode, and never let it
 * write anything before the setup code has been verified.
 */
final class FirstRunSetup
{
    /** Mirrors App\Service\AppBranding::DEFAULT_NAME; branding is not configured yet. */
    public const PRODUCT_NAME = 'Aggregate Analytics';
    public const CSRF_COOKIE = 'aggregate_setup_csrf';
    /** Marks every response from this page, so the browser can tell which requests reach PHP. */
    public const MARKER_HEADER = 'X-Aggregate-Setup';

    /** @var array<string, array{label: string, extension: string, port: int, minimum: string, help: string}> */
    public const DRIVERS = [
        'mysql' => [
            'label' => 'MySQL or MariaDB',
            'extension' => 'pdo_mysql',
            'port' => 3306,
            'minimum' => 'MySQL 8.0 or MariaDB 10.6',
            'help' => 'The usual choice with a hosting panel. Create an empty database and user first (in Plesk: Databases, then Add Database).',
        ],
        'pgsql' => [
            'label' => 'PostgreSQL',
            'extension' => 'pdo_pgsql',
            'port' => 5432,
            'minimum' => 'PostgreSQL 13',
            'help' => 'Use an empty database owned by the user you enter below.',
        ],
        'sqlite' => [
            'label' => 'SQLite file',
            'extension' => 'pdo_sqlite',
            'port' => 0,
            'minimum' => 'SQLite 3.25',
            'help' => 'Nothing to create: the data is kept in var/data.db. Good for trying it out and small sites. BI tools read the whole file, so they cannot be limited to the reporting views.',
        ],
    ];

    /** Used when release.json is missing, as in a source checkout. */
    private const DEFAULT_EXTENSIONS = ['ctype', 'curl', 'iconv', 'intl', 'mbstring', 'pdo', 'sodium', 'xml', 'zip'];
    private const SQLITE_FILE = 'var/data.db';
    private const SQLITE_URL = 'sqlite:///%kernel.project_dir%/var/data.db';
    private const PROBE_TABLE = 'aggregate_setup_check';

    private readonly SetupCode $code;
    private readonly string $nonce;

    /**
     * @param array<string, mixed> $server  $_SERVER
     * @param array<string, mixed> $post    $_POST
     * @param array<string, mixed> $cookies $_COOKIE
     */
    public function __construct(
        private readonly string $projectDir,
        private readonly array $server,
        private readonly array $post = [],
        private readonly array $cookies = [],
    ) {
        $this->code = new SetupCode($projectDir);
        $this->nonce = bin2hex(random_bytes(16));
    }

    /** Answer the current request. Always returns true: every request is handled while setup is pending. */
    public function handle(): bool
    {
        $path = $this->path();
        $method = strtoupper((string) ($this->server['REQUEST_METHOD'] ?? 'GET'));

        if ($path === '/api' || str_starts_with($path, '/api/')) {
            $this->sendHeaders(503, 'application/json');
            echo json_encode(['status' => 'setup_required', 'message' => 'This installation has not been set up yet.'], JSON_UNESCAPED_SLASHES);

            return true;
        }

        $checks = $this->checks();
        $state = ['errors' => [], 'detail' => null, 'values' => $this->defaults(), 'verified' => false];

        if ($method === 'POST') {
            $result = $this->submit($checks);
            if ($result === null) {
                return true;
            }
            $state = $result;
        }

        $this->render($checks, $state, $method === 'HEAD');

        return true;
    }

    /**
     * Server requirements, in the order they are shown.
     *
     * @return list<array{label: string, status: string, detail: string}>
     */
    public function checks(): array
    {
        $checks = [];
        $requirements = $this->releaseRequirements();

        $minimum = $requirements['php'];
        $checks[] = version_compare(PHP_VERSION, $minimum, '>=')
            ? ['label' => 'PHP version', 'status' => 'ok', 'detail' => 'PHP '.PHP_VERSION.'.']
            : ['label' => 'PHP version', 'status' => 'fail', 'detail' => 'PHP '.PHP_VERSION.' is too old; '.$minimum.' or newer is needed. Choose a newer PHP version for this site (in Plesk: PHP Settings).'];

        $missing = array_values(array_filter($requirements['extensions'], static fn (string $name): bool => !extension_loaded($name)));
        $checks[] = $missing === []
            ? ['label' => 'PHP extensions', 'status' => 'ok', 'detail' => 'All required extensions are enabled.']
            : ['label' => 'PHP extensions', 'status' => 'fail', 'detail' => 'Missing: '.implode(', ', $missing).'. Enable them for this site (in Plesk: PHP Settings), or ask your hosting provider.'];

        $drivers = array_keys(array_filter(self::DRIVERS, static fn (array $driver): bool => extension_loaded($driver['extension'])));
        $checks[] = $drivers !== []
            ? ['label' => 'Database drivers', 'status' => 'ok', 'detail' => 'Available: '.implode(', ', array_map(static fn (string $key): string => self::DRIVERS[$key]['label'], $drivers)).'.']
            : ['label' => 'Database drivers', 'status' => 'fail', 'detail' => 'PHP has no driver for a supported database. Enable pdo_mysql, pdo_pgsql or pdo_sqlite (in Plesk: PHP Settings).'];

        if (!is_file($this->projectDir.'/vendor/autoload_runtime.php')) {
            $checks[] = ['label' => 'Application files', 'status' => 'fail', 'detail' => is_dir($this->projectDir.'/.git')
                ? 'Dependencies are not installed. In a source checkout, run composer install and compile the dashboard assets, or use a release ZIP instead.'
                : 'This folder does not contain a prepared release. Download aggregate-VERSION.zip from the Assets list of a GitHub release, not the "Source code" archive, and extract it here.'];
        } elseif (!is_file($this->projectDir.'/public/assets/manifest.json')) {
            $checks[] = ['label' => 'Application files', 'status' => 'warn', 'detail' => 'Dashboard assets are not compiled, so the dashboard will look unstyled. Release ZIPs include them; in a source checkout run asset-map:compile.'];
        } else {
            $checks[] = ['label' => 'Application files', 'status' => 'ok', 'detail' => 'Dependencies and dashboard assets are present.'];
        }

        $checks[] = $this->webRootCheck();

        $unwritable = [];
        foreach (['.' => 'the application folder', 'config' => 'config/', 'var' => 'var/'] as $relative => $label) {
            $directory = $relative === '.' ? $this->projectDir : $this->projectDir.'/'.$relative;
            if ($relative === 'var' && !is_dir($directory)) {
                @mkdir($directory, 0775, true);
            }
            if (!is_dir($directory) || !is_writable($directory)) {
                $unwritable[] = $label;
            }
        }
        $checks[] = $unwritable === []
            ? ['label' => 'Write access', 'status' => 'ok', 'detail' => 'PHP can write its configuration and data.']
            : ['label' => 'Write access', 'status' => 'fail', 'detail' => 'PHP cannot write to '.implode(', ', $unwritable).'. The files must belong to the account PHP runs as. In Plesk, upload and extract them with the File Manager of this domain; otherwise ask your hosting provider to fix the owner.'];

        $memory = $this->memoryLimitBytes();
        if ($memory !== null && $memory < 128 * 1024 * 1024) {
            $checks[] = ['label' => 'Memory limit', 'status' => 'warn', 'detail' => 'PHP may use only '.ini_get('memory_limit').'. Set memory_limit to at least 128M (in Plesk: PHP Settings).'];
        }

        return $checks;
    }

    /** Build the DATABASE_URL for a MySQL or MariaDB server from its reported VERSION(). */
    public static function mysqlServerVersion(string $reported): string
    {
        if (preg_match('/^(?:5\.5\.5-)?(\d+)\.(\d+)\.(\d+)/', trim($reported), $match) !== 1) {
            throw new \RuntimeException('The database server reported an unrecognized version ('.$reported.').');
        }
        $version = $match[1].'.'.$match[2].'.'.$match[3];
        if (stripos($reported, 'mariadb') !== false) {
            if (version_compare($version, '10.6.0', '<')) {
                throw new \RuntimeException('This server runs MariaDB '.$version.'; version 10.6 or newer is needed. Ask your hosting provider about an upgrade, or choose SQLite.');
            }

            return $version.'-MariaDB';
        }
        if (version_compare($version, '8.0.0', '<')) {
            throw new \RuntimeException('This server runs MySQL '.$version.'; version 8.0 or newer is needed. Ask your hosting provider about an upgrade, or choose SQLite.');
        }

        return $version;
    }

    /** Build the serverVersion hint for PostgreSQL from SHOW server_version. */
    public static function postgresServerVersion(string $reported): string
    {
        if (preg_match('/^(\d+)(?:\.(\d+))?/', trim($reported), $match) !== 1) {
            throw new \RuntimeException('The database server reported an unrecognized version ('.$reported.').');
        }
        if ((int) $match[1] < 13) {
            throw new \RuntimeException('This server runs PostgreSQL '.$match[0].'; version 13 or newer is needed.');
        }

        return $match[1];
    }

    /**
     * The DATABASE_URL for a tested server connection. Credentials and the
     * database name are percent-encoded, so the result never contains quotes,
     * whitespace or a literal "%kernel.project_dir%".
     */
    public static function serverDatabaseUrl(string $driver, string $host, int $port, string $name, string $user, string $password, string $serverVersion): string
    {
        $scheme = match ($driver) {
            'mysql' => 'mysql',
            'pgsql' => 'postgresql',
            default => throw new \InvalidArgumentException('Unsupported database driver.'),
        };
        $hostPart = str_contains($host, ':') ? '['.$host.']' : $host;

        return sprintf(
            '%s://%s:%s@%s:%d/%s?serverVersion=%s',
            $scheme,
            rawurlencode($user),
            rawurlencode($password),
            $hostPart,
            $port,
            rawurlencode($name),
            rawurlencode($serverVersion),
        );
    }

    /** The complete .env.local written by the setup page. */
    public static function environmentFile(string $secret, string $databaseUrl, \DateTimeInterface $now): string
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $secret) !== 1 || preg_match('/[\s\'"\\\\]/', $databaseUrl) === 1) {
            throw new \InvalidArgumentException('Refusing to write an unsafe environment value.');
        }

        return "# Written by the setup page on ".$now->format('Y-m-d H:i')." UTC.\n"
            ."# Keep this file private: it holds the database password and the application secret.\n"
            ."# Real environment variables take precedence over the values below.\n"
            ."APP_ENV=prod\n"
            ."APP_DEBUG=0\n"
            ."APP_SECRET={$secret}\n"
            ."DATABASE_URL='{$databaseUrl}'\n"
            ."# Events are recorded during the request; no background worker is needed.\n"
            ."MESSENGER_TRANSPORT_DSN=sync://\n"
            ."MAILER_DSN=null://null\n";
    }

    /**
     * @param list<array{label: string, status: string, detail: string}> $checks
     *
     * @return array{errors: array<string, string>, detail: ?string, values: array<string, string>, verified: bool}|null
     *                                                                                                                    null after a redirect
     */
    private function submit(array $checks): ?array
    {
        $values = $this->submittedValues();
        $state = ['errors' => [], 'detail' => null, 'values' => $values, 'verified' => false];
        $state['values']['password'] = '';

        $token = $this->post['_token'] ?? null;
        $cookie = $this->cookies[self::CSRF_COOKIE] ?? null;
        if (!is_string($token) || !is_string($cookie) || $cookie === '' || !hash_equals($cookie, $token)) {
            $state['errors']['form'] = is_string($cookie) && $cookie !== ''
                ? 'This form expired. Enter the details again and resubmit.'
                : 'Your browser did not keep this page\'s cookie. Allow cookies for this site, reload the page and try again.';

            return $state;
        }
        if ($this->blocked($checks)) {
            $state['errors']['form'] = 'Fix the problems listed under Server check first.';

            return $state;
        }
        if (!$this->code->matches($values['setup_code'])) {
            $state['errors']['setup_code'] = 'That code does not match the one in '.SetupCode::FILE.'. Copy it again from the file.';

            return $state;
        }
        $state['verified'] = true;
        // The visitor has shown they can read the server's files, so their own
        // password can be shown back to them if the connection needs fixing.
        $state['values']['password'] = $values['password'];

        try {
            $databaseUrl = $this->prepareDatabase($values);
        } catch (\RuntimeException $e) {
            $state['errors']['database'] = $e->getMessage();
            $previous = $e->getPrevious();
            $state['detail'] = $previous instanceof \Throwable ? $previous->getMessage() : null;

            return $state;
        }

        try {
            $this->writeEnvironment($databaseUrl);
        } catch (\RuntimeException $e) {
            $state['errors']['form'] = $e->getMessage();

            return $state;
        }

        // Continue in this browser: /install accepts the verified code from this cookie.
        $code = $this->code->current();
        if ($code !== null) {
            $this->setCookie(SetupCode::COOKIE, $code, $this->base().'/install', 3600);
        }
        $this->sendHeaders(303, 'text/html');
        header('Location: '.$this->base().'/install');

        return null;
    }

    /** Test the connection and return the DATABASE_URL to save. */
    private function prepareDatabase(array $values): string
    {
        $driver = $values['driver'];
        if (!isset(self::DRIVERS[$driver])) {
            throw new \RuntimeException('Choose a database type.');
        }
        if (!extension_loaded(self::DRIVERS[$driver]['extension'])) {
            throw new \RuntimeException('PHP on this server lacks the '.self::DRIVERS[$driver]['extension'].' extension. Enable it (in Plesk: PHP Settings) or choose another database.');
        }
        if ($driver === 'sqlite') {
            $this->prepareSqlite();

            return self::SQLITE_URL;
        }

        [$host, $port] = $this->hostAndPort($values['host'], $values['port'], self::DRIVERS[$driver]['port']);
        $name = $values['name'];
        $user = $values['user'];
        $password = $values['password'];
        if ($name === '' || strlen($name) > 64 || preg_match('/[\x00-\x1f\x7f;]/', $name) === 1) {
            throw new \RuntimeException('Enter the database name (up to 64 characters, without semicolons).');
        }
        if ($user === '' || strlen($user) > 128 || preg_match('/[\x00-\x1f\x7f]/', $user) === 1) {
            throw new \RuntimeException('Enter the database user name.');
        }
        if (strlen($password) > 1024 || str_contains($password, "\0")) {
            throw new \RuntimeException('The password is too long.');
        }

        $dsn = $driver === 'mysql'
            ? sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name)
            : sprintf('pgsql:host=%s;port=%d;dbname=%s;connect_timeout=5', $host, $port, $name);
        try {
            $pdo = new \PDO($dsn, $user, $password, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 5]);
        } catch (\PDOException $e) {
            throw new \RuntimeException($this->explainConnectionError($driver, $e, $host), 0, $e);
        }

        try {
            if ($driver === 'mysql') {
                $serverVersion = self::mysqlServerVersion((string) $pdo->query('SELECT VERSION()')->fetchColumn());
                $tables = $pdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchAll(\PDO::FETCH_COLUMN);
            } else {
                $serverVersion = self::postgresServerVersion((string) $pdo->query('SHOW server_version')->fetchColumn());
                $tables = $pdo->query('SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema()')->fetchAll(\PDO::FETCH_COLUMN);
            }
        } catch (\PDOException $e) {
            throw new \RuntimeException('Connected, but the server could not be inspected. Check that the user has full access to this database.', 0, $e);
        }
        $this->assertUsableTables(array_map('strval', $tables));
        $this->assertCanCreateTablesAndViews($pdo);

        return self::serverDatabaseUrl($driver, $host, $port, $name, $user, $password, $serverVersion);
    }

    private function prepareSqlite(): void
    {
        $file = $this->projectDir.'/'.self::SQLITE_FILE;
        $existed = is_file($file);
        try {
            $pdo = new \PDO('sqlite:'.$file, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $version = (string) $pdo->query('SELECT sqlite_version()')->fetchColumn();
            if (version_compare($version, '3.25.0', '<')) {
                throw new \RuntimeException('PHP uses SQLite '.$version.'; version 3.25 or newer is needed. Choose another database.');
            }
            $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll(\PDO::FETCH_COLUMN);
            $this->assertUsableTables(array_map('strval', $tables));
            $this->assertCanCreateTablesAndViews($pdo);
        } catch (\PDOException $e) {
            throw new \RuntimeException('The SQLite file '.self::SQLITE_FILE.' could not be created or opened. Check that PHP can write to var/.', 0, $e);
        } finally {
            unset($pdo);
            if (!$existed && is_file($file)) {
                // Event data: readable only by the account PHP runs as.
                @chmod($file, 0600);
            }
        }
    }

    /** @param list<string> $tables */
    private function assertUsableTables(array $tables): void
    {
        $tables = array_values(array_diff($tables, [self::PROBE_TABLE, self::PROBE_TABLE.'_view']));
        if ($tables === [] || in_array('doctrine_migration_versions', $tables, true)) {
            // Empty, or an existing installation's database that is reconnected.
            return;
        }
        sort($tables);
        $sample = implode(', ', array_slice($tables, 0, 3)).(count($tables) > 3 ? ', …' : '');
        throw new \RuntimeException('This database already contains tables from another application ('.$sample.'). Use a new, empty database (in Plesk: Databases, then Add Database).');
    }

    /** Migrations create tables and reporting views; a read-only user should fail here, not halfway through. */
    private function assertCanCreateTablesAndViews(\PDO $pdo): void
    {
        $table = self::PROBE_TABLE;
        $view = self::PROBE_TABLE.'_view';
        try {
            $pdo->exec("CREATE TABLE {$table} (id INT)");
            $pdo->exec("CREATE VIEW {$view} AS SELECT id FROM {$table}");
        } catch (\PDOException $e) {
            throw new \RuntimeException('Connected, but this user cannot create tables and views in the database. Give the user full access to it (in Plesk this is the default for a database user).', 0, $e);
        } finally {
            try {
                $pdo->exec("DROP VIEW IF EXISTS {$view}");
                $pdo->exec("DROP TABLE IF EXISTS {$table}");
            } catch (\PDOException) {
                // Nothing else to clean up; the migrations do not use these names.
            }
        }
    }

    /** @return array{0: string, 1: int} */
    private function hostAndPort(string $host, string $port, int $defaultPort): array
    {
        $host = $host === '' ? 'localhost' : $host;
        // Hosting panels often show "localhost:3306" as the server address.
        if ($port === '' && preg_match('/^([^:\[\]]+):(\d{1,5})$/', $host, $match) === 1) {
            [$host, $port] = [$match[1], $match[2]];
        }
        $host = trim($host, '[]');
        $validName = strlen($host) <= 253 && preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9.\-]*[A-Za-z0-9])?$/D', $host) === 1;
        $validIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
        if (!$validName && !$validIp) {
            throw new \RuntimeException('Enter the database server as a host name or IP address, for example localhost.');
        }
        if ($port === '') {
            return [$host, $defaultPort];
        }
        if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
            throw new \RuntimeException('Enter a port number between 1 and 65535, or leave it empty.');
        }

        return [$host, (int) $port];
    }

    private function explainConnectionError(string $driver, \PDOException $e, string $host): string
    {
        $message = $e->getMessage();
        $code = (string) ($e->errorInfo[1] ?? '');
        $state = (string) ($e->errorInfo[0] ?? $e->getCode());
        if ($driver === 'mysql') {
            return match (true) {
                $code === '1045' || str_contains($message, '[1045]') => 'The server rejected the user name or password.',
                $code === '1044' || str_contains($message, '[1044]') => 'This user has no access to that database. Check the database name, or give the user access to it.',
                $code === '1049' || str_contains($message, '[1049]') => 'That database does not exist. Check its name, or create it first.',
                str_contains($message, '[2002]') || str_contains($message, '[2005]') || str_contains($message, '[2006]') => 'Could not reach a database server at '.$host.'. Check the server address and port.',
                default => 'Could not connect to the database.',
            };
        }

        return match (true) {
            $state === '28P01' || str_contains($message, 'password authentication failed') => 'The server rejected the user name or password.',
            $state === '3D000' || str_contains($message, 'does not exist') => 'That database or user does not exist. Check the names.',
            default => 'Could not connect to a database server at '.$host.'. Check the server address, port, user and password.',
        };
    }

    private function writeEnvironment(string $databaseUrl): void
    {
        $contents = self::environmentFile(bin2hex(random_bytes(32)), $databaseUrl, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $path = $this->projectDir.'/.env.local';
        // Never replace a file that appeared meanwhile, for example from another tab.
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            throw new \RuntimeException(is_file($path)
                ? '.env.local already exists. Reload the page to continue.'
                : 'The configuration file .env.local could not be created. Check that PHP can write to the application folder.');
        }
        @chmod($path, 0600);
        $written = fwrite($handle, $contents);
        $flushed = fflush($handle);
        fclose($handle);
        if ($written !== strlen($contents) || !$flushed) {
            @unlink($path);
            throw new \RuntimeException('The configuration file .env.local could not be written completely. Check the free disk space.');
        }
    }

    /** @param list<array{label: string, status: string, detail: string}> $checks */
    private function blocked(array $checks): bool
    {
        foreach ($checks as $check) {
            if ($check['status'] === 'fail') {
                return true;
            }
        }

        return false;
    }

    /** @return array{label: string, status: string, detail: string} */
    private function webRootCheck(): array
    {
        $documentRoot = is_string($this->server['DOCUMENT_ROOT'] ?? null) ? realpath($this->server['DOCUMENT_ROOT']) : false;
        $project = realpath($this->projectDir);
        if ($documentRoot !== false && $project !== false) {
            $root = rtrim(str_replace('\\', '/', $documentRoot), '/').'/';
            $application = rtrim(str_replace('\\', '/', $project), '/').'/';
            if (str_starts_with($application, $root)) {
                return ['label' => 'Web root', 'status' => 'fail', 'detail' => 'The web server can reach private files such as configuration and data. Set the document root to '.$this->shortPath('public').' (in Plesk: Hosting Settings), then open this site again.'];
            }
        }

        return ['label' => 'Web root', 'status' => 'ok', 'detail' => 'Only the public folder is served.'];
    }

    /** @return array{php: string, extensions: list<string>} */
    private function releaseRequirements(): array
    {
        $requirements = ['php' => '8.2.0', 'extensions' => self::DEFAULT_EXTENSIONS];
        $raw = @file_get_contents($this->projectDir.'/release.json', false, null, 0, 65536);
        $release = is_string($raw) ? json_decode($raw, true) : null;
        $declared = is_array($release) && is_array($release['requirements'] ?? null) ? $release['requirements'] : [];
        if (is_string($declared['php'] ?? null) && preg_match('/^>=\s*(\d+\.\d+(?:\.\d+)?)$/', trim($declared['php']), $match) === 1) {
            $requirements['php'] = $match[1];
        }
        if (is_array($declared['extensions'] ?? null)) {
            $extensions = array_values(array_filter($declared['extensions'], static fn (mixed $name): bool => is_string($name) && preg_match('/^[a-z0-9_]+$/', $name) === 1));
            if ($extensions !== []) {
                $requirements['extensions'] = $extensions;
            }
        }

        return $requirements;
    }

    private function memoryLimitBytes(): ?int
    {
        $limit = trim((string) ini_get('memory_limit'));
        if ($limit === '' || $limit === '-1' || preg_match('/^(\d+)\s*([KMG]?)$/i', $limit, $match) !== 1) {
            return null;
        }

        return (int) $match[1] * match (strtoupper($match[2])) {
            'G' => 1024 ** 3,
            'M' => 1024 ** 2,
            'K' => 1024,
            default => 1,
        };
    }

    /** @return array<string, string> */
    private function defaults(): array
    {
        return [
            'driver' => extension_loaded('pdo_mysql') ? 'mysql' : (extension_loaded('pdo_sqlite') ? 'sqlite' : 'pgsql'),
            'host' => 'localhost',
            'port' => '',
            'name' => '',
            'user' => '',
            'password' => '',
            'setup_code' => '',
        ];
    }

    /** @return array<string, string> */
    private function submittedValues(): array
    {
        $values = $this->defaults();
        foreach (array_keys($values) as $key) {
            $value = $this->post[$key] ?? '';
            $values[$key] = is_string($value) ? ($key === 'password' ? $value : trim($value)) : '';
        }

        return $values;
    }

    private function path(): string
    {
        $path = parse_url((string) ($this->server['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : '/';
        $base = $this->base();

        return $base !== '' && str_starts_with($path, $base) ? (substr($path, strlen($base)) ?: '/') : $path;
    }

    /** The URL prefix when the site is served from a subdirectory; empty at the domain root. */
    private function base(): string
    {
        $script = str_replace('\\', '/', (string) ($this->server['SCRIPT_NAME'] ?? '/index.php'));
        $base = rtrim(dirname($script), '/.');

        return preg_match('#^(?:/[A-Za-z0-9._~\-]+)*$#D', $base) === 1 ? $base : '';
    }

    private function isHttps(): bool
    {
        $https = strtolower((string) ($this->server['HTTPS'] ?? ''));

        return ($https !== '' && $https !== 'off') || (string) ($this->server['SERVER_PORT'] ?? '') === '443';
    }

    /** A path relative to the folder above the application, as a hosting panel's file manager shows it. */
    private function shortPath(string $relative = ''): string
    {
        $folder = basename(str_replace('\\', '/', $this->projectDir));

        return $folder.($relative === '' ? '' : '/'.$relative);
    }

    private function setCookie(string $name, string $value, string $path, int $lifetime): void
    {
        if (headers_sent()) {
            return;
        }
        setcookie($name, $value, [
            'expires' => time() + $lifetime,
            'path' => $path === '' ? '/' : $path,
            'secure' => $this->isHttps(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    private function sendHeaders(int $status, string $contentType): void
    {
        if (headers_sent()) {
            return;
        }
        http_response_code($status);
        header('Content-Type: '.$contentType.'; charset=UTF-8');
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex, nofollow');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src 'nonce-{$this->nonce}'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
        header(self::MARKER_HEADER.': 1');
    }

    /**
     * @param list<array{label: string, status: string, detail: string}>                                                 $checks
     * @param array{errors: array<string, string>, detail: ?string, values: array<string, string>, verified: bool} $state
     */
    private function render(array $checks, array $state, bool $headersOnly): void
    {
        $blocked = $this->blocked($checks);
        $codeProblem = null;
        if (!$blocked) {
            try {
                $this->code->ensure();
            } catch (\RuntimeException) {
                $codeProblem = 'The setup code file could not be created. Check that PHP can write to the application folder.';
            }
        }

        $csrf = $this->cookies[self::CSRF_COOKIE] ?? null;
        if (!is_string($csrf) || preg_match('/^[a-f0-9]{64}$/D', $csrf) !== 1) {
            $csrf = bin2hex(random_bytes(32));
            $this->setCookie(self::CSRF_COOKIE, $csrf, $this->base() === '' ? '/' : $this->base(), 86400);
        }

        $this->sendHeaders(200, 'text/html');
        if ($headersOnly) {
            return;
        }

        echo $this->page($checks, $state, $blocked, $codeProblem, $csrf);
    }

    /**
     * @param list<array{label: string, status: string, detail: string}>                                                 $checks
     * @param array{errors: array<string, string>, detail: ?string, values: array<string, string>, verified: bool} $state
     */
    private function page(array $checks, array $state, bool $blocked, ?string $codeProblem, string $csrf): string
    {
        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $values = $state['values'];
        $errors = $state['errors'];
        $product = $e(self::PRODUCT_NAME);
        $base = $e($this->base());

        $icons = ['ok' => '✓', 'warn' => '!', 'fail' => '✕'];
        $statusText = ['ok' => 'OK', 'warn' => 'Warning', 'fail' => 'Needs fixing'];
        $rows = '';
        foreach ($checks as $check) {
            $rows .= '<li class="check '.$check['status'].'"><span class="icon" aria-hidden="true">'.$icons[$check['status']].'</span>'
                .'<span><strong>'.$e($check['label']).'</strong> <span class="sr">('.$statusText[$check['status']].')</span><br>'
                .$e($check['detail']).'</span></li>';
        }
        $failures = count(array_filter($checks, static fn (array $check): bool => $check['status'] === 'fail'));
        $summary = $failures > 0
            ? $failures.' '.($failures === 1 ? 'problem needs' : 'problems need').' fixing. Fix '.($failures === 1 ? 'it' : 'them').', then reload this page.'
            : 'The server is ready.';
        $checksOpen = $failures > 0 || array_filter($checks, static fn (array $check): bool => $check['status'] === 'warn') !== [] ? ' open' : '';

        $formError = isset($errors['form']) ? '<p class="error" role="alert">'.$e($errors['form']).'</p>' : '';
        $codeError = isset($errors['setup_code']) ? '<p class="error" id="code-error" role="alert">'.$e($errors['setup_code']).'</p>' : '';
        $codeDescribedBy = isset($errors['setup_code']) ? 'code-help code-error" aria-invalid="true' : 'code-help';
        $databaseError = '';
        if (isset($errors['database'])) {
            $databaseError = '<div class="error" id="database-error" role="alert"><p>'.$e($errors['database']).'</p>';
            if ($state['detail'] !== null && $state['detail'] !== '') {
                $databaseError .= '<details><summary>Technical details</summary><pre>'.$e($state['detail']).'</pre></details>';
            }
            $databaseError .= '</div>';
        }

        $options = '';
        foreach (self::DRIVERS as $key => $driver) {
            $available = extension_loaded($driver['extension']);
            $checked = $values['driver'] === $key ? ' checked' : '';
            $disabled = $available ? '' : ' disabled';
            $note = $available ? $driver['help'] : 'Not available: PHP lacks the '.$driver['extension'].' extension.';
            $options .= '<label class="choice'.($available ? '' : ' unavailable').'"><input type="radio" name="driver" value="'.$key.'"'.$checked.$disabled.'>'
                .'<span><strong>'.$e($driver['label']).'</strong> <span class="muted">('.$e($driver['minimum']).' or newer)</span><br>'
                .'<span class="muted">'.$e($note).'</span></span></label>';
        }

        $codeFile = $e($this->shortPath(SetupCode::FILE));
        $disabledForm = $blocked || $codeProblem !== null ? ' disabled' : '';
        $codeBlock = $codeProblem !== null ? '<p class="error">'.$e($codeProblem).'</p>' : '';
        $nonce = $this->nonce;
        $marker = self::MARKER_HEADER;
        $sqliteSelected = $values['driver'] === 'sqlite' ? ' hidden' : '';

        return <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Set up {$product}</title>
<style>
:root { color-scheme: light dark; --bg: #f6f7f9; --fg: #1f2328; --muted: #57606a; --card: #fff; --line: #d0d7de; --accent: #0b5cad; --accent-fg: #fff; --ok: #1a7f37; --warn: #9a6700; --fail: #cf222e; --field: #fff; }
@media (prefers-color-scheme: dark) { :root { --bg: #111418; --fg: #e6edf3; --muted: #9da7b3; --card: #1a1f26; --line: #30363d; --accent: #4493f8; --accent-fg: #0d1117; --ok: #3fb950; --warn: #d29922; --fail: #f85149; --field: #0d1117; } }
* { box-sizing: border-box; }
body { margin: 0; background: var(--bg); color: var(--fg); font: 16px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; }
main { max-width: 42rem; margin: 0 auto; padding: 2rem 1rem 4rem; }
h1 { font-size: 1.6rem; margin: 0 0 .25rem; }
h2 { font-size: 1.1rem; margin: 0 0 .5rem; }
p { margin: .5rem 0; }
.lead { color: var(--muted); margin-bottom: 1.5rem; }
section { background: var(--card); border: 1px solid var(--line); border-radius: 10px; padding: 1.25rem; margin: 0 0 1rem; }
.muted { color: var(--muted); }
.checks { list-style: none; padding: 0; margin: .75rem 0 0; }
.check { display: flex; gap: .75rem; padding: .5rem 0; border-top: 1px solid var(--line); }
.check:first-child { border-top: 0; }
.icon { flex: 0 0 1.5rem; height: 1.5rem; border-radius: 50%; display: grid; place-items: center; font-weight: 700; font-size: .85rem; color: #fff; }
.ok .icon { background: var(--ok); } .warn .icon { background: var(--warn); } .fail .icon { background: var(--fail); }
.sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
summary { cursor: pointer; font-weight: 600; }
label { display: block; font-weight: 600; margin: .75rem 0 .25rem; }
input[type=text], input[type=password], input[type=number] { width: 100%; padding: .55rem .65rem; border: 1px solid var(--line); border-radius: 6px; background: var(--field); color: var(--fg); font: inherit; }
input:focus-visible, button:focus-visible, summary:focus-visible { outline: 3px solid var(--accent); outline-offset: 2px; }
.code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; letter-spacing: .08em; text-transform: uppercase; }
.choice { display: flex; gap: .6rem; font-weight: 400; border: 1px solid var(--line); border-radius: 8px; padding: .6rem .75rem; margin: .5rem 0; cursor: pointer; }
.choice input { margin-top: .3rem; }
.unavailable { opacity: .6; cursor: not-allowed; }
.row { display: grid; grid-template-columns: 1fr 8rem; gap: .75rem; }
fieldset { border: 0; padding: 0; margin: 0; }
fieldset[disabled] { opacity: .6; }
.help { color: var(--muted); font-size: .9rem; margin: .25rem 0 0; }
.error { color: var(--fail); font-weight: 600; }
.error pre { white-space: pre-wrap; font-weight: 400; color: var(--muted); }
.notice { border-left: 4px solid var(--warn); padding: .25rem .75rem; margin: .75rem 0 0; }
button { margin-top: 1.25rem; width: 100%; padding: .75rem; border: 0; border-radius: 8px; background: var(--accent); color: var(--accent-fg); font: inherit; font-weight: 700; cursor: pointer; }
button[disabled] { opacity: .5; cursor: not-allowed; }
code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .92em; }
@media (max-width: 30rem) { .row { grid-template-columns: 1fr; } }
</style>
</head>
<body>
<main>
<h1>Set up {$product}</h1>
<p class="lead">Two short steps: connect a database here, then create your administrator account.</p>
{$formError}
<section aria-labelledby="checks-title">
<details{$checksOpen}>
<summary id="checks-title">Server check: {$e($summary)}</summary>
<ul class="checks">{$rows}</ul>
<div id="probe" class="notice" hidden></div>
<noscript><p class="help">The web server routing checks need JavaScript. Everything else on this page works without it.</p></noscript>
</details>
</section>

<form method="post" action="" novalidate>
<input type="hidden" name="_token" value="{$e($csrf)}">
<fieldset{$disabledForm}>
<section aria-labelledby="code-title">
<h2 id="code-title">1. Confirm this is your server</h2>
<p id="code-help">Open <code>{$codeFile}</code> with your hosting panel's file manager (it is next to <code>README.md</code>) and copy the code from it. This stops anyone else from finishing setup before you do.</p>
{$codeBlock}
<label for="setup_code">Setup code</label>
<input type="text" id="setup_code" name="setup_code" class="code" value="{$e($values['setup_code'])}" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="XXXX-XXXX-XXXX" aria-describedby="{$codeDescribedBy}" required>
{$codeError}
</section>

<section aria-labelledby="db-title">
<h2 id="db-title">2. Connect a database</h2>
{$databaseError}
<div role="radiogroup" aria-labelledby="db-title">{$options}</div>
<div id="server-fields"{$sqliteSelected}>
<label for="host">Server</label>
<div class="row">
<input type="text" id="host" name="host" value="{$e($values['host'])}" autocomplete="off" spellcheck="false" aria-describedby="host-help">
<input type="text" id="port" name="port" value="{$e($values['port'])}" inputmode="numeric" autocomplete="off" placeholder="Port" aria-label="Port (optional)">
</div>
<p class="help" id="host-help">Usually <code>localhost</code>. Leave the port empty for the default.</p>
<label for="name">Database name</label>
<input type="text" id="name" name="name" value="{$e($values['name'])}" autocomplete="off" spellcheck="false">
<label for="user">User name</label>
<input type="text" id="user" name="user" value="{$e($values['user'])}" autocomplete="off" spellcheck="false">
<label for="password">Password</label>
<input type="password" id="password" name="password" value="{$e($values['password'])}" autocomplete="new-password">
</div>
<p class="help">Saving tests the connection, detects the server version and stores the settings, with a newly generated application secret, in <code>.env.local</code>.</p>
<button type="submit">Save and continue</button>
</section>
</fieldset>
</form>
<p class="help">Next: create the administrator account. Guides: <code>PLESK-DEPLOYMENT.md</code> and <code>DEPLOYMENT.md</code> in the application folder.</p>
</main>
<script nonce="{$nonce}">
(function () {
  var fields = document.getElementById('server-fields');
  function sync() {
    var chosen = document.querySelector('input[name=driver]:checked');
    fields.hidden = !!chosen && chosen.value === 'sqlite';
  }
  document.querySelectorAll('input[name=driver]').forEach(function (input) { input.addEventListener('change', sync); });
  sync();

  if (!window.fetch) { return; }
  var base = '{$base}';
  function reaches(path) {
    return fetch(base + path, { method: 'HEAD', cache: 'no-store', credentials: 'same-origin' })
      .then(function (response) { return response.headers.get('{$marker}') === '1'; })
      .catch(function () { return null; });
  }
  Promise.all([reaches('/install'), reaches('/aggregate.js'), reaches('/branding/theme.css')]).then(function (results) {
    var messages = [];
    if (results[0] === false) {
      messages.push('Addresses other than the home page do not reach the application, so the next step would show "not found". In Plesk: Apache & nginx Settings, turn on Proxy mode so Apache handles requests, or add the nginx directives from PLESK-DEPLOYMENT.md.');
    }
    if (results[1] === false || results[2] === false) {
      messages.push('The web server answers some .js and .css addresses itself, so tracker settings and dashboard colours saved later would not take effect. In Plesk: Apache & nginx Settings, remove js and css from the file types nginx serves directly (or turn that option off).');
    }
    if (messages.length === 0) { return; }
    var box = document.getElementById('probe');
    box.hidden = false;
    box.innerHTML = '<p><strong>Web server settings to change</strong></p>';
    messages.forEach(function (text) { var p = document.createElement('p'); p.textContent = text; box.appendChild(p); });
    box.closest('details').open = true;
  });
})();
</script>
</body>
</html>
HTML;
    }
}
