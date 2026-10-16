<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ReportingTimezoneCommand;
use App\Service\AggregateConfigLoader;
use App\Service\AnalyticsPrivacySettings;
use App\Service\AnonymousBiViewManager;
use App\Service\ReportingTimezone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Yaml\Yaml;

final class ReportingTimezoneCommandTest extends TestCase
{
    private string $directory;
    private array $environment;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        unset($_ENV['REPORTING_TIMEZONE'], $_SERVER['REPORTING_TIMEZONE'], $_ENV['ANONYMOUS_MIN_CELL_COUNT'], $_SERVER['ANONYMOUS_MIN_CELL_COUNT']);
        $this->directory = sys_get_temp_dir().'/aggregate-tz-command-'.bin2hex(random_bytes(6));
        mkdir($this->directory.'/config', 0700, true);
        file_put_contents($this->directory.'/config/aggregate.yaml', Yaml::dump(['operator_setting' => 'preserve']));
    }

    protected function tearDown(): void
    {
        [$_ENV, $_SERVER] = $this->environment;
        foreach (glob($this->directory.'/config/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory.'/config');
        rmdir($this->directory);
    }

    public function testShowingTheDefaultDoesNotTouchTheDatabase(): void
    {
        $executed = [];
        $tester = $this->tester($executed);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame([], $executed);
        self::assertStringContainsString('Reporting timezone: UTC', $tester->getDisplay());
    }

    public function testSettingAZoneWritesYamlAndRegeneratesEventViewsOnly(): void
    {
        $executed = [];
        $tester = $this->tester($executed);

        self::assertSame(Command::SUCCESS, $tester->execute(['timezone' => 'America/Chicago']));
        self::assertSame('preserve', Yaml::parseFile($this->directory.'/config/aggregate.yaml')['operator_setting']);
        self::assertSame('America/Chicago', Yaml::parseFile($this->directory.'/config/aggregate.yaml')[ReportingTimezone::KEY]);
        self::assertStringContainsString("AT TIME ZONE 'America/Chicago'", $executed[0]);
        self::assertStringNotContainsString("AT TIME ZONE 'America/Chicago'", $executed[1]);
        self::assertStringContainsString('Saved the reporting timezone America/Chicago', $tester->getDisplay());
    }

    public function testAnOffsetIsRejectedBeforeSavingOrRegenerating(): void
    {
        $executed = [];
        $before = (string) file_get_contents($this->directory.'/config/aggregate.yaml');
        $tester = $this->tester($executed);

        self::assertSame(Command::FAILURE, $tester->execute(['timezone' => 'UTC-5']));
        self::assertSame([], $executed);
        self::assertSame($before, file_get_contents($this->directory.'/config/aggregate.yaml'));
        self::assertStringContainsString('IANA time zone name', $tester->getDisplay());
    }

    /** @param list<string> $executed */
    private function tester(array &$executed): CommandTester
    {
        $config = new AggregateConfigLoader($this->directory, 'test');
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $connection->method('executeStatement')->willReturnCallback(static function (string $sql) use (&$executed): int {
            $executed[] = $sql;

            return 0;
        });

        return new CommandTester(new ReportingTimezoneCommand(
            new ReportingTimezone($config),
            new AnalyticsPrivacySettings($config),
            new AnonymousBiViewManager($connection, null, new ReportingTimezone($config)),
        ));
    }
}
