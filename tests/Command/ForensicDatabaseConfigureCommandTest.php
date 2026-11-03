<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ForensicDatabaseConfigureCommand;
use App\Service\AggregateConfigLoader;
use App\Service\ForensicDatabase\ForensicCatalog;
use App\Service\ForensicDatabase\ForensicDatabaseConfigurator;
use App\Service\ForensicDatabase\ForensicDatabaseConnection;
use App\Service\ForensicDatabase\ForensicDatabaseSettings;
use App\Service\LocalEnvironmentFile;
use App\Service\ReportingDatabase\ReportingDatabaseConnection;
use App\Service\ViewSync\ViewCatalog;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Yaml\Yaml;

/** app:forensic-database:configure, which hosting panels run to connect a forensic copy and turn its sync on. */
final class ForensicDatabaseConfigureCommandTest extends TestCase
{
    private string $projectDir;
    private Connection $application;
    private array $environment;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        unset($_ENV['FORENSIC_DATABASE_URL'], $_SERVER['FORENSIC_DATABASE_URL']);
        foreach (array_keys(ForensicDatabaseSettings::defaults()) as $key) {
            unset($_ENV[strtoupper($key)], $_SERVER[strtoupper($key)]);
        }
        $this->projectDir = sys_get_temp_dir().'/aggregate-forensic-configure-'.bin2hex(random_bytes(6));
        mkdir($this->projectDir.'/var', 0700, true);
        mkdir($this->projectDir.'/config', 0700, true);
        // SQLite keeps no database comment, so the copy is allowed by the confirmation.
        $this->writeYaml(['forensic_database_access_restricted' => true]);
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

    public function testEnableTurnsTheSyncOnForEverythingSelectedInTheSameStep(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['--type' => 'sqlite', '--enable' => true, '--interval' => '720']));
        self::assertStringContainsString("FORENSIC_DATABASE_URL='sqlite:///%kernel.project_dir%/var/forensic.db'", (string) file_get_contents($this->projectDir.'/.env.local'));
        $yaml = Yaml::parseFile($this->projectDir.'/config/aggregate.yaml');
        self::assertTrue($yaml['forensic_database_enabled']);
        self::assertSame(720, $yaml['forensic_database_interval_minutes']);
        // The selection is kept: every table copy and private view by default.
        self::assertSame(array_keys(ForensicCatalog::TABLES), $yaml['forensic_database_tables']);
        self::assertSame(array_keys(ViewCatalog::PRIVATE), $yaml['forensic_database_views']);
        self::assertTrue($yaml['forensic_database_access_restricted']);
        self::assertStringContainsString('The sync is on: '.(count(ForensicCatalog::TABLES) + count(ViewCatalog::PRIVATE)).' tables and views, every 720 minutes.', preg_replace('/\s+/', ' ', $tester->getDisplay()));
    }

    public function testEnableKeepsASelectionTheAdministratorNarrowed(): void
    {
        $this->writeYaml(['forensic_database_access_restricted' => true, 'forensic_database_tables' => ['forensic_events_v1'], 'forensic_database_views' => []]);

        self::assertSame(Command::SUCCESS, $this->tester()->execute(['--type' => 'sqlite', '--enable' => true]));
        $yaml = Yaml::parseFile($this->projectDir.'/config/aggregate.yaml');
        self::assertSame(['forensic_events_v1'], $yaml['forensic_database_tables']);
        self::assertSame([], $yaml['forensic_database_views']);
        self::assertSame(1440, $yaml['forensic_database_interval_minutes']);
    }

    public function testWithoutEnableTheSyncStaysOff(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['--type' => 'sqlite']));
        self::assertStringContainsString('--enable', $tester->getDisplay());
        self::assertArrayNotHasKey('forensic_database_enabled', Yaml::parseFile($this->projectDir.'/config/aggregate.yaml'));
    }

    public function testIntervalNeedsEnableAndAValidValue(): void
    {
        self::assertSame(Command::INVALID, $this->tester()->execute(['--type' => 'sqlite', '--interval' => '60']));
        self::assertSame(Command::INVALID, $this->tester()->execute(['--type' => 'sqlite', '--enable' => true, '--interval' => '45']));
        self::assertFileDoesNotExist($this->projectDir.'/.env.local');
    }

    public function testADatabaseThatMayNotReceiveForensicCopiesIsNeitherSavedNorTurnedOn(): void
    {
        $this->writeYaml([]);
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['--type' => 'sqlite', '--enable' => true]));
        self::assertStringContainsString('keeps no database comment', preg_replace('/\s+/', ' ', $tester->getDisplay()));
        self::assertFileDoesNotExist($this->projectDir.'/.env.local');
        self::assertArrayNotHasKey('forensic_database_enabled', (array) Yaml::parseFile($this->projectDir.'/config/aggregate.yaml'));
    }

    public function testTheEnvironmentCanKeepTheSyncOff(): void
    {
        $_ENV['FORENSIC_DATABASE_ENABLED'] = 'false';
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['--type' => 'sqlite', '--enable' => true]));
        self::assertStringContainsString('FORENSIC_DATABASE_ENABLED in the server environment keeps the sync off', preg_replace('/\s+/', ' ', $tester->getDisplay()));
        self::assertFileExists($this->projectDir.'/.env.local');
    }

    /** @param array<string, mixed> $values */
    private function writeYaml(array $values): void
    {
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump($values));
    }

    private function tester(): CommandTester
    {
        $environment = new LocalEnvironmentFile($this->projectDir, 'prod');
        $settings = new ForensicDatabaseSettings(new AggregateConfigLoader($this->projectDir, 'test'));
        $connection = new ForensicDatabaseConnection(
            $environment->get(ForensicDatabaseConfigurator::VARIABLE),
            $this->projectDir,
            $this->application,
            new ReportingDatabaseConnection(null, $this->projectDir, $this->application),
            $settings,
        );

        return new CommandTester(new ForensicDatabaseConfigureCommand(new ForensicDatabaseConfigurator($environment, $connection), $settings));
    }
}
