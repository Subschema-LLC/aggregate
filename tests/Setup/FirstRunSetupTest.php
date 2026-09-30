<?php

declare(strict_types=1);

namespace App\Tests\Setup;

use App\Service\AppBranding;
use App\Service\ProjectDirEnvVarProcessor;
use App\Setup\FirstRunSetup;
use App\Setup\SetupCode;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Filesystem\Filesystem;

final class FirstRunSetupTest extends TestCase
{
    private const CSRF = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private string $project;
    private array $saved;
    private array $savedProcessEnvironment = [];

    protected function setUp(): void
    {
        $this->saved = [$_SERVER, $_ENV, $_POST, $_COOKIE];
        unset($_SERVER['APP_SECRET'], $_SERVER['DATABASE_URL'], $_ENV['APP_SECRET'], $_ENV['DATABASE_URL']);
        // The gate also reads real process variables, which the test run itself sets.
        foreach (['APP_SECRET', 'DATABASE_URL'] as $name) {
            $this->savedProcessEnvironment[$name] = getenv($name);
            putenv($name);
        }
        $this->project = sys_get_temp_dir().'/aggregate-first-run-'.bin2hex(random_bytes(8));
        foreach (['vendor', 'public/assets', 'config', 'var'] as $directory) {
            mkdir($this->project.'/'.$directory, 0775, true);
        }
        touch($this->project.'/vendor/autoload_runtime.php');
        file_put_contents($this->project.'/public/assets/manifest.json', '{}');
        // What a release ZIP ships: an empty secret and the SQLite default.
        file_put_contents($this->project.'/.env', "# Generate APP_SECRET with: openssl rand -hex 32\nAPP_ENV=prod\nAPP_SECRET=\nDATABASE_URL=\"sqlite:///%kernel.project_dir%/var/data.db\"\n");
    }

    protected function tearDown(): void
    {
        [$_SERVER, $_ENV, $_POST, $_COOKIE] = $this->saved;
        foreach ($this->savedProcessEnvironment as $name => $value) {
            putenv($value === false ? $name : $name.'='.$value);
        }
        (new Filesystem())->remove($this->project);
    }

    #[DataProvider('configuredInstallations')]
    public function testGateLeavesConfiguredInstallationsAlone(string $file, string $contents, array $server): void
    {
        if ($file !== '') {
            file_put_contents($this->project.'/'.$file, $contents);
        }
        $_SERVER = array_merge($this->server(), $server);

        [$handled, $output] = $this->runGate();

        self::assertFalse($handled);
        self::assertSame('', $output);
        self::assertFileDoesNotExist($this->project.'/'.SetupCode::FILE);
    }

    public static function configuredInstallations(): iterable
    {
        yield 'written .env.local' => ['.env.local', "DATABASE_URL=x\n", []];
        yield 'dumped environment' => ['.env.local.php', "<?php return [];\n", []];
        yield 'secret in .env' => ['.env', "APP_SECRET=abc123\n", []];
        yield 'quoted secret with comment' => ['.env', "APP_SECRET=\"abc\" # set by hand\n", []];
        yield 'secret in .env.prod.local' => ['.env.prod.local', "APP_SECRET=abc\n", []];
        yield 'real secret variable' => ['', '', ['APP_SECRET' => 'from-the-server']];
        yield 'real database variable' => ['', '', ['DATABASE_URL' => 'mysql://u:p@db/x']];
    }

    #[DataProvider('unconfiguredEnvironments')]
    public function testGateServesTheSetupPageForAFreshInstallation(?string $environment): void
    {
        if ($environment === null) {
            unlink($this->project.'/.env');
        } else {
            file_put_contents($this->project.'/.env', $environment);
        }
        $_SERVER = $this->server();

        [$handled, $output] = $this->runGate();

        self::assertTrue($handled);
        self::assertStringContainsString('<title>Set up Aggregate Analytics</title>', $output);
        self::assertStringContainsString('The server is ready.', $output);
        self::assertStringNotContainsString('<fieldset disabled>', $output);
        self::assertFileExists($this->project.'/'.SetupCode::FILE);
        self::assertFileDoesNotExist($this->project.'/.env.local');
    }

    public static function unconfiguredEnvironments(): iterable
    {
        yield 'release defaults' => ["APP_ENV=prod\nAPP_SECRET=\n"];
        yield 'empty value with a comment' => ["APP_SECRET= # fill me in\n"];
        yield 'no environment file' => [null];
    }

    public function testApiRequestsGetAMachineReadableAnswer(): void
    {
        $_SERVER = $this->server(['REQUEST_URI' => '/api/receive']);

        [$handled, $output] = $this->runGate();

        self::assertTrue($handled);
        self::assertSame(['status' => 'setup_required', 'message' => 'This installation has not been set up yet.'], json_decode($output, true));
        self::assertFileDoesNotExist($this->project.'/'.SetupCode::FILE);
    }

    public function testAWebRootThatExposesTheApplicationBlocksSetupWithoutCreatingACode(): void
    {
        $output = $this->handle($this->server(['DOCUMENT_ROOT' => $this->project]));

        self::assertStringContainsString('The web server can reach private files', $output);
        self::assertStringContainsString('<fieldset disabled>', $output);
        self::assertFileDoesNotExist($this->project.'/'.SetupCode::FILE);
    }

    public function testAMissingDependencyFolderPointsToTheReleaseZip(): void
    {
        unlink($this->project.'/vendor/autoload_runtime.php');

        $output = $this->handle($this->server());

        self::assertStringContainsString('not the &quot;Source code&quot; archive', $output);
        self::assertStringContainsString('<fieldset disabled>', $output);
    }

    public function testSubmissionNeedsTheFormCookieAndTheSetupCodeBeforeWritingAnything(): void
    {
        $code = (new SetupCode($this->project))->ensure();

        $output = $this->handle($this->server(['REQUEST_METHOD' => 'POST']), ['_token' => self::CSRF, 'setup_code' => $code, 'driver' => 'sqlite'], []);
        self::assertStringContainsString('did not keep this page', $output);

        $output = $this->handle($this->server(['REQUEST_METHOD' => 'POST']), ['_token' => 'forged', 'setup_code' => $code, 'driver' => 'sqlite'], [FirstRunSetup::CSRF_COOKIE => self::CSRF]);
        self::assertStringContainsString('This form expired', $output);

        $output = $this->handle($this->server(['REQUEST_METHOD' => 'POST']), ['_token' => self::CSRF, 'setup_code' => 'AAAA-BBBB-CCCC', 'driver' => 'sqlite', 'password' => 'not echoed'], [FirstRunSetup::CSRF_COOKIE => self::CSRF]);
        self::assertStringContainsString('That code does not match', $output);
        self::assertStringNotContainsString('not echoed', $output);

        self::assertFileDoesNotExist($this->project.'/.env.local');
        self::assertFileDoesNotExist($this->project.'/var/data.db');
    }

    public function testSqliteSetupWritesAPrivateEnvironmentAndKeepsTheCodeForTheAdministratorStep(): void
    {
        $code = (new SetupCode($this->project))->ensure();

        $output = $this->handle(
            $this->server(['REQUEST_METHOD' => 'POST']),
            ['_token' => self::CSRF, 'setup_code' => strtolower($code), 'driver' => 'sqlite'],
            [FirstRunSetup::CSRF_COOKIE => self::CSRF],
        );

        self::assertSame('', $output, 'A successful setup redirects to /install.');
        $environment = (new Dotenv())->parse((string) file_get_contents($this->project.'/.env.local'));
        self::assertSame('prod', $environment['APP_ENV']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $environment['APP_SECRET']);
        self::assertSame('sqlite:///%kernel.project_dir%/var/data.db', $environment['DATABASE_URL']);
        self::assertSame('sync://', $environment['MESSENGER_TRANSPORT_DSN']);
        self::assertSame(0600, fileperms($this->project.'/.env.local') & 0777);
        self::assertSame(0600, fileperms($this->project.'/var/data.db') & 0777);
        $tables = (new \PDO('sqlite:'.$this->project.'/var/data.db'))->query("SELECT name FROM sqlite_master WHERE name LIKE 'aggregate_setup%'")->fetchAll();
        self::assertSame([], $tables, 'The permission probe cleans up after itself.');
        self::assertTrue((new SetupCode($this->project))->matches($code), '/install still needs the code.');

        // The gate is now closed for good.
        $_SERVER = $this->server();
        self::assertFalse($this->runGate()[0]);
    }

    public function testAnExistingEnvironmentFileIsNeverReplaced(): void
    {
        $code = (new SetupCode($this->project))->ensure();
        file_put_contents($this->project.'/.env.local', "APP_SECRET=written-meanwhile\n");

        $output = $this->handle(
            $this->server(['REQUEST_METHOD' => 'POST']),
            ['_token' => self::CSRF, 'setup_code' => $code, 'driver' => 'sqlite'],
            [FirstRunSetup::CSRF_COOKIE => self::CSRF],
        );

        self::assertStringContainsString('.env.local already exists', $output);
        self::assertSame("APP_SECRET=written-meanwhile\n", file_get_contents($this->project.'/.env.local'));
    }

    public function testADatabaseUsedByAnotherApplicationIsRefused(): void
    {
        $code = (new SetupCode($this->project))->ensure();
        (new \PDO('sqlite:'.$this->project.'/var/data.db'))->exec('CREATE TABLE wp_options (id INT)');

        $output = $this->handle(
            $this->server(['REQUEST_METHOD' => 'POST']),
            ['_token' => self::CSRF, 'setup_code' => $code, 'driver' => 'sqlite'],
            [FirstRunSetup::CSRF_COOKIE => self::CSRF],
        );

        self::assertStringContainsString('already contains tables from another application (wp_options)', $output);
        self::assertFileDoesNotExist($this->project.'/.env.local');
    }

    public function testInvalidServerDetailsAreRejectedBeforeConnecting(): void
    {
        $code = (new SetupCode($this->project))->ensure();
        $post = ['_token' => self::CSRF, 'setup_code' => $code, 'driver' => 'mysql', 'host' => 'db;dbname=other', 'name' => 'x', 'user' => 'u', 'password' => 'secret-typed-by-owner'];

        $output = $this->handle($this->server(['REQUEST_METHOD' => 'POST']), $post, [FirstRunSetup::CSRF_COOKIE => self::CSRF]);

        self::assertStringContainsString('Enter the database server as a host name or IP address', $output);
        self::assertStringContainsString('value="secret-typed-by-owner"', $output, 'After the code is verified, the owner does not retype the password.');
        self::assertFileDoesNotExist($this->project.'/.env.local');
    }

    #[DataProvider('mysqlVersions')]
    public function testMysqlServerVersionKeepsTheMariaDbMarker(string $reported, string $expected): void
    {
        self::assertSame($expected, FirstRunSetup::mysqlServerVersion($reported));
    }

    public static function mysqlVersions(): iterable
    {
        yield 'MySQL' => ['8.0.39-0ubuntu0.22.04.1', '8.0.39'];
        yield 'MySQL 8.4' => ['8.4.2', '8.4.2'];
        yield 'MariaDB' => ['10.11.14-MariaDB-0ubuntu0.24.04.1', '10.11.14-MariaDB'];
        yield 'MariaDB with replication prefix' => ['5.5.5-10.6.12-MariaDB-log', '10.6.12-MariaDB'];
    }

    #[DataProvider('unsupportedVersions')]
    public function testUnsupportedServersAreExplained(string $reported, string $message): void
    {
        $this->expectExceptionMessage($message);
        FirstRunSetup::mysqlServerVersion($reported);
    }

    public static function unsupportedVersions(): iterable
    {
        yield 'MariaDB 10.3' => ['10.3.39-MariaDB', 'MariaDB 10.3.39; version 10.6 or newer'];
        yield 'MySQL 5.7' => ['5.7.44-log', 'MySQL 5.7.44; version 8.0 or newer'];
        yield 'garbage' => ['unknown', 'unrecognized version'];
    }

    public function testPostgresServerVersionUsesTheMajorVersion(): void
    {
        self::assertSame('16', FirstRunSetup::postgresServerVersion('16.13 (Ubuntu 16.13-0ubuntu0.24.04.1)'));
        self::assertSame('13', FirstRunSetup::postgresServerVersion('13.2'));
        $this->expectExceptionMessage('PostgreSQL 12.4; version 13 or newer');
        FirstRunSetup::postgresServerVersion('12.4');
    }

    public function testSavedUrlsSurviveDotenvDoctrineAndTheProjectDirectoryProcessor(): void
    {
        $password = "p@ss\$w0rd'%40x \"#;\\";
        $url = FirstRunSetup::serverDatabaseUrl('mysql', 'localhost', 3306, 'ana lytics', 'user@host', $password, '10.11.14-MariaDB');
        $file = FirstRunSetup::environmentFile(str_repeat('ab', 32), $url, new \DateTimeImmutable('2026-09-30 12:00'));

        $parsed = (new Dotenv())->parse($file);
        $resolved = (new ProjectDirEnvVarProcessor('/srv/app'))->getEnv('project_dir', 'DATABASE_URL', static fn (): string => $parsed['DATABASE_URL']);
        $params = (new DsnParser(['mysql' => 'pdo_mysql', 'postgresql' => 'pdo_pgsql']))->parse($resolved);

        self::assertSame($url, $parsed['DATABASE_URL']);
        self::assertSame('user@host', $params['user']);
        self::assertSame($password, $params['password']);
        self::assertSame('ana lytics', $params['dbname']);
        self::assertSame('10.11.14-MariaDB', $params['serverVersion']);

        $ipv6 = (new DsnParser(['postgresql' => 'pdo_pgsql']))->parse(FirstRunSetup::serverDatabaseUrl('pgsql', '::1', 5432, 'db', 'u', 'p', '16'));
        self::assertSame('pdo_pgsql', $ipv6['driver']);
        self::assertSame(5432, $ipv6['port']);
    }

    public function testEnvironmentFileRefusesValuesThatWouldBreakQuoting(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FirstRunSetup::environmentFile(str_repeat('ab', 32), "sqlite:///tmp/it's.db", new \DateTimeImmutable());
    }

    public function testTheSetupPageNamesNoHostingVendor(): void
    {
        // Panel-specific steps belong in the hosting guides linked from DEPLOYMENT.md.
        unlink($this->project.'/vendor/autoload_runtime.php');
        $blocked = $this->handle($this->server(['DOCUMENT_ROOT' => $this->project]));
        self::assertStringContainsString('in your hosting panel', $blocked);

        foreach ([$blocked, (string) file_get_contents(dirname(__DIR__, 2).'/src/Setup/FirstRunSetup.php'), (string) file_get_contents(dirname(__DIR__, 2).'/config/setup.php')] as $text) {
            foreach (['plesk', 'cpanel', 'directadmin'] as $vendor) {
                self::assertStringNotContainsStringIgnoringCase($vendor, $text);
            }
        }
    }

    public function testTheSetupPageUsesTheDefaultBrandName(): void
    {
        self::assertSame(AppBranding::DEFAULT_NAME, FirstRunSetup::PRODUCT_NAME);
    }

    /** @return array{0: bool, 1: string} */
    private function runGate(): array
    {
        $gate = require dirname(__DIR__, 2).'/config/setup.php';
        ob_start();
        try {
            $handled = $gate($this->project);
        } finally {
            $output = (string) ob_get_clean();
        }

        return [$handled, $output];
    }

    private function handle(array $server, array $post = [], array $cookies = []): string
    {
        ob_start();
        try {
            (new FirstRunSetup($this->project, $server, $post, $cookies))->handle();
        } finally {
            $output = (string) ob_get_clean();
        }

        return $output;
    }

    private function server(array $overrides = []): array
    {
        return $overrides + [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'SCRIPT_NAME' => '/index.php',
            'DOCUMENT_ROOT' => $this->project.'/public',
        ];
    }
}
