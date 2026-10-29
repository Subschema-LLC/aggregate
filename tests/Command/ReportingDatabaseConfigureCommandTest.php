<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ReportingDatabaseConfigureCommand;
use App\Service\AggregateConfigLoader;
use App\Service\LocalEnvironmentFile;
use App\Service\ReportingDatabase\ReportingDatabaseConfigurator;
use App\Service\ReportingDatabase\ReportingDatabaseConnection;
use App\Service\ReportingDatabase\ReportingDatabaseSettings;
use App\Service\ViewSync\ViewCatalog;
use Symfony\Component\Yaml\Yaml;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ReportingDatabaseConfigureCommandTest extends TestCase
{
    private string $projectDir;
    private Connection $application;
    private array $environment;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        unset($_ENV['REPORTING_DATABASE_URL'], $_SERVER['REPORTING_DATABASE_URL']);
        foreach (array_keys(ReportingDatabaseSettings::defaults()) as $key) {
            unset($_ENV[strtoupper($key)], $_SERVER[strtoupper($key)]);
        }
        $this->projectDir = sys_get_temp_dir().'/aggregate-configure-command-'.bin2hex(random_bytes(6));
        mkdir($this->projectDir.'/var', 0700, true);
        mkdir($this->projectDir.'/config', 0700, true);
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump(['reporting_database_private_views' => ['analytics_custom_events_v1']]));
        $this->application = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->projectDir.'/var/data.db']);
        $this->application->executeStatement('CREATE TABLE marker (id INTEGER)');
    }

    protected function tearDown(): void
    {
        [$_ENV, $_SERVER] = $this->environment;
        $this->application->close();
        array_map(unlink(...), [...glob($this->projectDir.'/var/*') ?: [], ...glob($this->projectDir.'/.env*') ?: [], ...glob($this->projectDir.'/config/*') ?: []]);
        rmdir($this->projectDir.'/var');
        rmdir($this->projectDir.'/config');
        rmdir($this->projectDir);
    }

    public function testItTestsSavesAndRemovesTheConnection(): void
    {
        $tester = $this->tester();
        self::assertSame(Command::SUCCESS, $tester->execute(['--type' => 'sqlite']));
        self::assertStringContainsString('saved REPORTING_DATABASE_URL to .env.local', $tester->getDisplay());
        self::assertStringContainsString("REPORTING_DATABASE_URL='sqlite:///%kernel.project_dir%/var/reporting.db'", (string) file_get_contents($this->projectDir.'/.env.local'));

        $tester = $this->tester();
        self::assertSame(Command::SUCCESS, $tester->execute(['--remove' => true]));
        self::assertStringNotContainsString('REPORTING_DATABASE_URL', (string) file_get_contents($this->projectDir.'/.env.local'));
    }

    public function testNothingIsSavedWhenTheConnectionIsRefused(): void
    {
        $tester = $this->tester();
        self::assertSame(Command::INVALID, $tester->execute([]));
        self::assertStringContainsString('Choose --type', $tester->getDisplay());

        self::assertSame(Command::FAILURE, $tester->execute(['--type' => 'sqlite', '--database' => 'var/data.db']));
        self::assertStringContainsString('Nothing was saved.', $tester->getDisplay());
        self::assertStringContainsString('application database', $tester->getDisplay());
        self::assertFileDoesNotExist($this->projectDir.'/.env.local');
    }

    public function testEnableTurnsTheSyncOnForTheApprovedViewsInTheSameStep(): void
    {
        $tester = $this->tester();
        self::assertSame(Command::SUCCESS, $tester->execute(['--type' => 'sqlite', '--enable' => true, '--interval' => '30']));
        self::assertStringContainsString('The sync is on: '.count(ViewCatalog::APPROVED).' approved views every 30 minutes.', preg_replace('/\s+/', ' ', $tester->getDisplay()));

        $yaml = Yaml::parseFile($this->projectDir.'/config/aggregate.yaml');
        self::assertTrue($yaml['reporting_database_enabled']);
        self::assertSame(array_keys(ViewCatalog::APPROVED), $yaml['reporting_database_views']);
        self::assertSame([], $yaml['reporting_database_private_views'], 'A private view selected before is no longer copied.');
        self::assertSame(30, $yaml['reporting_database_interval_minutes']);
    }

    public function testIntervalNeedsEnableAndAValidValue(): void
    {
        self::assertSame(Command::INVALID, $this->tester()->execute(['--type' => 'sqlite', '--interval' => '30']));
        self::assertSame(Command::INVALID, $this->tester()->execute(['--type' => 'sqlite', '--enable' => true, '--interval' => '45']));
        self::assertFileDoesNotExist($this->projectDir.'/.env.local');
    }

    public function testWithoutEnableTheSyncStaysAsItWas(): void
    {
        $tester = $this->tester();
        self::assertSame(Command::SUCCESS, $tester->execute(['--type' => 'sqlite']));
        self::assertStringContainsString('--enable', $tester->getDisplay());
        self::assertArrayNotHasKey('reporting_database_enabled', Yaml::parseFile($this->projectDir.'/config/aggregate.yaml'));
    }

    public function testThePasswordIsAskedForWithoutEcho(): void
    {
        $fields = null;
        $configurator = $this->createMock(ReportingDatabaseConfigurator::class);
        $configurator->expects(self::once())->method('save')->willReturnCallback(static function (array $values) use (&$fields): array {
            $fields = $values;

            return ['engine' => 'MySQL', 'host' => 'db', 'port' => 3306, 'database' => 'reporting', 'server_version' => '8.4.0', 'tables' => [], 'mark' => null];
        });
        $tester = new CommandTester(new ReportingDatabaseConfigureCommand($configurator, $this->settings()));
        $tester->setInputs(['p@ss word']);

        self::assertSame(Command::SUCCESS, $tester->execute(['--type' => 'mysql', '--host' => 'db', '--database' => 'reporting', '--user' => 'sync'], ['interactive' => true]));
        self::assertSame(['type' => 'mysql', 'host' => 'db', 'port' => '', 'database' => 'reporting', 'user' => 'sync', 'password' => 'p@ss word', 'trust_certificate' => ''], $fields);
        self::assertStringNotContainsString('p@ss word', $tester->getDisplay());
        self::assertStringContainsString('MySQL database reporting on db:3306', $tester->getDisplay());
    }

    private function tester(): CommandTester
    {
        $environment = new LocalEnvironmentFile($this->projectDir, 'prod');
        $connection = new ReportingDatabaseConnection($environment->get(ReportingDatabaseConfigurator::VARIABLE), $this->projectDir, $this->application);

        return new CommandTester(new ReportingDatabaseConfigureCommand(new ReportingDatabaseConfigurator($environment, $connection), $this->settings()));
    }

    private function settings(): ReportingDatabaseSettings
    {
        return new ReportingDatabaseSettings(new AggregateConfigLoader($this->projectDir, 'test'));
    }
}
