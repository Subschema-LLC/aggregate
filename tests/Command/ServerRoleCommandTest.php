<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ServerRoleCommand;
use App\Service\AggregateConfigLoader;
use App\Service\Cluster\ServerRole;
use App\Service\Cluster\ServerRoleReport;
use App\Service\InstalledRelease;
use App\Service\ParsedYamlCache;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

final class ServerRoleCommandTest extends TestCase
{
    private const SHARED_DATABASE = 'mysql://aggregate:secret@10.0.0.5:3306/aggregate?serverVersion=8.0.0';

    private string $directory;
    private array $environment;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        foreach (['TRUSTED_PROXIES', 'MESSENGER_TRANSPORT_DSN'] as $name) {
            unset($_ENV[$name], $_SERVER[$name]);
        }
        $this->directory = sys_get_temp_dir().'/aggregate-role-report-'.bin2hex(random_bytes(6));
        (new Filesystem())->mkdir($this->directory.'/config');
    }

    protected function tearDown(): void
    {
        [$_ENV, $_SERVER] = $this->environment;
        (new Filesystem())->remove($this->directory);
    }

    public function testTheJsonReportIsTheVersionedContractToolsReadOnASingleServer(): void
    {
        $tester = $this->tester(ServerRole::of('all', 'sqlite:///:memory:'), DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]));

        self::assertSame(0, $tester->execute(['--json' => true]));
        $report = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(1, $report['schema_version']);
        self::assertSame(['schema_version', 'role', 'configured_role', 'role_source', 'compiled_role', 'problem', 'release', 'configuration_version',
            'administration_url', 'collection_base_url', 'serves', 'queue', 'database', 'requirements'], array_keys($report));
        self::assertSame(['all', 'all', null], [$report['role'], $report['compiled_role'], $report['problem']]);
        self::assertSame(['dashboard' => true, 'collection' => true, 'scheduled_task' => true, 'consumers' => true], $report['serves']);
        self::assertSame('sync', $report['queue']['transport']);
        self::assertSame('sqlite', $report['database']['engine']);
        self::assertSame(['role', 'cache', 'database'], array_column($report['requirements'], 'id'));
        foreach ($report['requirements'] as $requirement) {
            self::assertSame(['id', 'status', 'message', 'fix'], array_keys($requirement));
        }
    }

    public function testACollectionServerIsToldWhatItStillNeeds(): void
    {
        $_ENV['MESSENGER_TRANSPORT_DSN'] = 'doctrine://default';
        $connection = $this->mysql(open: 120, max: 151);
        $tester = $this->tester(ServerRole::of('collection', self::SHARED_DATABASE), $connection, compiledRole: 'all');

        self::assertSame(1, $tester->execute(['--json' => true]), 'A stale cache is a failure.');
        $report = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        $checks = array_column($report['requirements'], null, 'id');
        self::assertSame(['dashboard' => false, 'collection' => true, 'scheduled_task' => false, 'consumers' => false], $report['serves']);
        self::assertSame('error', $checks['cache']['status']);
        self::assertSame('Remove the cache with "rm -rf var/cache/prod", then reload PHP-FPM.', $checks['cache']['fix']);
        self::assertSame('warning', $checks['connections']['status'], '120 of 151 is past 70%.');
        self::assertSame('warning', $checks['administration_url']['status']);
        self::assertSame('warning', $checks['trusted_proxies']['status']);
        self::assertSame('ok', $checks['queue']['status']);
        self::assertStringContainsString('must run messenger:consume', $checks['queue']['message']);
        self::assertSame(['engine' => 'mysql', 'persistent_connections' => false, 'max_connections' => 151, 'open_connections' => 120, 'error' => null], $report['database']);
    }

    public function testAnAdministrationServerWithoutACollectionAddressIsWarned(): void
    {
        $tester = $this->tester(ServerRole::of('administration', self::SHARED_DATABASE, 'https://admin.example.com'), $this->mysql(open: 10, max: 151), compiledRole: 'administration');

        self::assertSame(0, $tester->execute([]));
        $display = $tester->getDisplay();
        self::assertStringContainsString('Server role: administration', $display);
        self::assertStringContainsString('the dashboard and configuration, the scheduled task', $display);
        self::assertStringContainsString('Set the collection address (collection_base_url)', $display);

        file_put_contents($this->directory.'/config/aggregate.yaml', Yaml::dump(['collection_base_url' => 'https://collect.example.com']));
        $tester = $this->tester(ServerRole::of('administration', self::SHARED_DATABASE), $this->mysql(open: 10, max: 151), compiledRole: 'administration');
        $tester->execute(['--json' => true]);
        $checks = array_column(json_decode($tester->getDisplay(), true)['requirements'], null, 'id');
        self::assertSame('ok', $checks['collection_address']['status']);
    }

    public function testAWorkerOnSyncHasNothingToConsumeAndPostgresqlCountsPersistentConnections(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getParams')->willReturn(['persistent' => 'true']);
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $connection->method('fetchOne')->willReturnOnConsecutiveCalls('100', '18');
        $tester = $this->tester(ServerRole::of('worker', 'postgresql://a:b@10.0.0.5/aggregate'), $connection, compiledRole: 'worker');

        $tester->execute(['--json' => true]);
        $report = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        $checks = array_column($report['requirements'], null, 'id');
        self::assertSame(['postgresql', true, 100, 18], [$report['database']['engine'], $report['database']['persistent_connections'], $report['database']['max_connections'], $report['database']['open_connections']]);
        self::assertStringContainsString('each PHP-FPM worker and consumer of every server keeps one open', $checks['connections']['message']);
        self::assertSame('warning', $checks['queue']['status']);
        self::assertStringContainsString('nothing to consume', $checks['queue']['message']);
    }

    private function mysql(int $open, int $max): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getParams')->willReturn([]);
        $connection->method('getDatabasePlatform')->willReturn(new MySQL80Platform());
        $connection->method('fetchOne')->willReturn((string) $max);
        $connection->method('fetchAssociative')->willReturn(['Variable_name' => 'Threads_connected', 'Value' => (string) $open]);

        return $connection;
    }

    private function tester(ServerRole $role, Connection $connection, string $compiledRole = 'all'): CommandTester
    {
        $config = new AggregateConfigLoader($this->directory, 'prod', parsedYaml: new ParsedYamlCache($this->directory.'/cache'));
        $report = new ServerRoleReport($role, $config, new InstalledRelease($this->directory), $connection, $compiledRole);

        return new CommandTester(new ServerRoleCommand($report));
    }
}
