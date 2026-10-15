<?php

declare(strict_types=1);

namespace App\Tests\Service\ReportingDatabase;

use App\Service\LocalEnvironmentFile;
use App\Service\ReportingDatabase\ReportingDatabaseConfigurator;
use App\Service\ReportingDatabase\ReportingDatabaseConnection;
use App\Service\ReportingDatabase\ReportingDatabaseException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class ReportingDatabaseConfiguratorTest extends TestCase
{
    private string $projectDir;
    private Connection $application;
    private array $environment;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        unset($_ENV['REPORTING_DATABASE_URL'], $_SERVER['REPORTING_DATABASE_URL']);
        $this->projectDir = sys_get_temp_dir().'/aggregate-configurator-'.bin2hex(random_bytes(6));
        mkdir($this->projectDir.'/var', 0700, true);
        $this->application = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->projectDir.'/var/data.db']);
        $this->application->executeStatement('CREATE TABLE marker (id INTEGER)');
    }

    protected function tearDown(): void
    {
        [$_ENV, $_SERVER] = $this->environment;
        $this->application->close();
        array_map(unlink(...), glob($this->projectDir.'/{,.}[!.]*', GLOB_BRACE | GLOB_MARK) ?: []);
        array_map(unlink(...), glob($this->projectDir.'/var/*') ?: []);
        rmdir($this->projectDir.'/var');
        rmdir($this->projectDir);
    }

    public function testAWorkingConnectionIsSavedAndCanBeRemoved(): void
    {
        $check = $this->configurator()->save(['type' => 'sqlite', 'database' => 'var/reporting.db']);

        self::assertSame('SQLite', $check['engine']);
        self::assertSame([], $check['tables']);
        self::assertStringContainsString("REPORTING_DATABASE_URL='sqlite:///%kernel.project_dir%/var/reporting.db'", (string) file_get_contents($this->projectDir.'/.env.local'));
        self::assertTrue($this->configurator()->isSavedHere());

        $this->configurator()->remove();
        self::assertFalse($this->configurator()->isSavedHere());
        self::assertSame('', file_get_contents($this->projectDir.'/.env.local'));
    }

    public function testNothingIsSavedWhenTheTestFails(): void
    {
        $refused = [
            [['type' => 'sqlite', 'database' => 'var/data.db'], 'application database'],
            [['type' => 'oracle'], 'Choose MySQL or MariaDB'],
            [['type' => 'pgsql', 'host' => 'db', 'port' => 'abc', 'database' => 'r', 'user' => 'u'], 'The port must be'],
            [['type' => 'pgsql', 'host' => 'db example', 'database' => 'r', 'user' => 'u'], 'host name'],
            [['type' => 'sqlite', 'database' => '../outside.db'], 'plain path'],
        ];
        foreach ($refused as [$fields, $message]) {
            try {
                $this->configurator()->save($fields);
                self::fail('Saved '.json_encode($fields));
            } catch (\InvalidArgumentException|ReportingDatabaseException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
        try {
            $this->configurator()->saveUrl("mysql://u:p@db/r' x");
            self::fail('Saved a URL with a quote.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('Percent-encode', $e->getMessage());
        }
        self::assertFileDoesNotExist($this->projectDir.'/.env.local');
    }

    public function testAnEmptyPasswordKeepsTheSavedOneForTheSameServerAndUser(): void
    {
        $current = 'postgresql://sync:s%40ved@db.example.net:5432/reporting';
        $saved = null;
        $configurator = new class(new LocalEnvironmentFile($this->projectDir, 'prod'), $this->connection($current), $saved) extends ReportingDatabaseConfigurator {
            public function __construct(LocalEnvironmentFile $environment, ReportingDatabaseConnection $current, public ?string &$saved)
            {
                parent::__construct($environment, $current);
            }

            public function saveUrl(string $url): array
            {
                $this->saved = $url;

                return [];
            }
        };

        $configurator->save(['type' => 'pgsql', 'host' => 'db.example.net', 'database' => 'reporting', 'user' => 'sync', 'password' => '']);
        self::assertSame($current, $saved);
        $configurator->save(['type' => 'pgsql', 'host' => 'db.example.net', 'database' => 'reporting', 'user' => 'other', 'password' => '']);
        self::assertSame('postgresql://other:@db.example.net:5432/reporting', $saved);
    }

    public function testItRefusesToSaveWhereTheValueWouldBeIgnored(): void
    {
        file_put_contents($this->projectDir.'/.env.prod.local', "REPORTING_DATABASE_URL=sqlite:///tmp/elsewhere.db\n");

        $this->expectException(ReportingDatabaseException::class);
        $this->expectExceptionMessage('set in .env.prod.local');
        $this->configurator()->save(['type' => 'sqlite']);
    }

    private function connection(?string $url): ReportingDatabaseConnection
    {
        return new ReportingDatabaseConnection($url, $this->projectDir, $this->application);
    }

    private function configurator(): ReportingDatabaseConfigurator
    {
        $environment = new LocalEnvironmentFile($this->projectDir, 'prod');

        return new ReportingDatabaseConfigurator($environment, $this->connection($environment->get(ReportingDatabaseConfigurator::VARIABLE)));
    }
}
