<?php

declare(strict_types=1);

namespace App\Tests\Configuration;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDB1060Platform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\MySQL84Platform;
use Doctrine\DBAL\Platforms\PostgreSQL120Platform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

final class DatabasePlatformConfigurationTest extends TestCase
{
    #[DataProvider('connectionExampleFiles')]
    public function testCheckedInMysqlExamplesSelectSupportedPlatformsWithoutConnecting(string $file): void
    {
        $contents = file_get_contents(dirname(__DIR__, 2).'/'.$file);
        self::assertIsString($contents);
        preg_match_all('~mysql://[^\s"\'`<>]+[?&]serverVersion=([a-zA-Z0-9_.-]+)~', $contents, $matches);
        self::assertNotEmpty($matches[0], 'The fixture must exercise a checked-in connection example.');

        foreach ($matches[0] as $index => $dsn) {
            $version = $matches[1][$index];
            $expected = str_contains($version, 'MariaDB')
                ? MariaDB1060Platform::class
                : (str_starts_with($version, '8.4') ? MySQL84Platform::class : MySQL80Platform::class);
            $this->assertPlatformWithoutConnecting($dsn, $expected);
        }
    }

    public static function connectionExampleFiles(): iterable
    {
        foreach ([
            '.env.dev',
            '.env.test',
            '.env.prod.example',
            '.env.local.example',
            '.github/workflows/ci.yml',
            '.github/workflows/release.yml',
            'Makefile',
            'DEPLOYMENT.md',
            'PLESK-DEPLOYMENT.md',
            'docs/CONFIGURATION.md',
            'docs/DATABASE.md',
            'templates/install/config_error.html.twig',
        ] as $file) {
            yield $file => [$file];
        }
    }

    #[DataProvider('supportedDatabaseVersions')]
    public function testDocumentedDatabaseVersionsSelectSupportedPlatformsWithoutConnecting(
        string $dsn,
        string $expected,
    ): void {
        $this->assertPlatformWithoutConnecting($dsn, $expected);
    }

    public static function supportedDatabaseVersions(): iterable
    {
        yield 'MySQL 8.0' => ['mysql://ci:ci@127.0.0.1:1/aggregate?serverVersion=8.0.0', MySQL80Platform::class];
        yield 'MySQL 8.4' => ['mysql://ci:ci@127.0.0.1:1/aggregate?serverVersion=8.4.0', MySQL84Platform::class];
        foreach (['10.6.0', '10.11.0', '11.0.0', '11.4.0'] as $version) {
            yield 'MariaDB '.$version => [
                'mysql://ci:ci@127.0.0.1:1/aggregate?serverVersion='.$version.'-MariaDB',
                MariaDB1060Platform::class,
            ];
        }
        foreach (['13', '14', '15', '16'] as $version) {
            yield 'PostgreSQL '.$version => [
                'postgresql://ci:ci@127.0.0.1:1/aggregate?serverVersion='.$version,
                PostgreSQL120Platform::class,
            ];
        }
        foreach (['2017', '2019', '2022'] as $version) {
            yield 'SQL Server '.$version => [
                'sqlsrv://ci:ci@127.0.0.1:1/aggregate?serverVersion='.$version,
                SQLServerPlatform::class,
            ];
        }
        yield 'SQLite' => ['sqlite:///:memory:', SQLitePlatform::class];
    }

    public function testComposeApplicationAndWorkerDefaultsSelectMysql8WithoutConnecting(): void
    {
        $compose = Yaml::parseFile(dirname(__DIR__, 2).'/compose.yaml');

        foreach (['php', 'worker'] as $service) {
            $environment = implode("\n", $compose['services'][$service]['environment']);
            self::assertSame(1, preg_match('/serverVersion=\$\{MYSQL_SERVER_VERSION:-([^}]+)\}/', $environment, $matches));
            $this->assertPlatformWithoutConnecting(
                'mysql://ci:ci@127.0.0.1:1/aggregate?serverVersion='.$matches[1],
                MySQL80Platform::class,
            );
        }
    }

    #[DataProvider('installerVersions')]
    public function testShellInstallerBuildsSupportedMysqlFamilyConnections(
        int $choice,
        string $versionInput,
        string $expectedVersion,
        string $expectedPlatform,
    ): void {
        $installer = file_get_contents(dirname(__DIR__, 2).'/install.sh');
        self::assertIsString($installer);
        self::assertSame(1, preg_match('/case \$DB_CHOICE in\n.*?\nesac/s', $installer, $matches));

        // Exercise only the interactive DSN selection, with synthetic input.
        // No installation, file mutation, or database operation is executed.
        $process = new Process(['bash', '-c',
            "set -e\nDB_CHOICE=".$choice."\n".$matches[0]."\nprintf '\\nTEST_DSN=%s\\n' \"\$DB_URL\"\n",
        ]);
        $process->setInput("\n\naggregate\nci\nci\n".$versionInput."\n");
        $process->mustRun();
        self::assertSame(1, preg_match('/^TEST_DSN=(.+)$/m', $process->getOutput(), $output));
        self::assertStringEndsWith('serverVersion='.$expectedVersion, $output[1]);
        $this->assertPlatformWithoutConnecting($output[1], $expectedPlatform);
    }

    public static function installerVersions(): iterable
    {
        yield 'MySQL default' => [3, '', '8.0.0', MySQL80Platform::class];
        yield 'MySQL 8.4 patch' => [3, '8.4.6', '8.4.6', MySQL84Platform::class];
        yield 'MariaDB default' => [4, '', '11.4.0-MariaDB', MariaDB1060Platform::class];
        yield 'MariaDB 10.6 patch' => [4, '10.6.23', '10.6.23-MariaDB', MariaDB1060Platform::class];
    }

    /** @param class-string<AbstractPlatform> $expected */
    private function assertPlatformWithoutConnecting(string $dsn, string $expected): void
    {
        $parser = new DsnParser([
            'mysql' => 'pdo_mysql',
            'postgresql' => 'pdo_pgsql',
            'sqlsrv' => 'pdo_sqlsrv',
            'sqlite' => 'pdo_sqlite',
        ]);
        $connection = DriverManager::getConnection($parser->parse($dsn));

        self::assertFalse($connection->isConnected());
        self::assertInstanceOf($expected, $connection->getDatabasePlatform(), $dsn);
        self::assertFalse($connection->isConnected(), 'An explicit platform must not require a database connection.');
    }
}
