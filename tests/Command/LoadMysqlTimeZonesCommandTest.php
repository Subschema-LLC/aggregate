<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\LoadMysqlTimeZonesCommand;
use App\Service\AggregateConfigLoader;
use App\Service\AnalyticsPrivacySettings;
use App\Service\AnonymousBiViewManager;
use App\Service\MysqlTimeZoneTables;
use App\Service\ReportingTimezone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Yaml\Yaml;

final class LoadMysqlTimeZonesCommandTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/aggregate-tz-load-'.bin2hex(random_bytes(6));
        mkdir($this->directory.'/config', 0700, true);
        file_put_contents($this->directory.'/config/aggregate.yaml', Yaml::dump(['operator_setting' => 'preserve']));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/config/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory.'/config');
        rmdir($this->directory);
    }

    public function testOtherDatabasesNeedNothing(): void
    {
        $tester = $this->tester(new PostgreSQLPlatform(), null);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('does not need MySQL time zone tables', $tester->getDisplay());
    }

    public function testAServerWithoutAdminToolsPrintsTheOneCommand(): void
    {
        $tester = $this->tester(new MySQL80Platform(), null, missingTools: true);

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('mysql_tzinfo_to_sql /usr/share/zoneinfo | mysql -u root -p mysql', $tester->getDisplay());
    }

    public function testLoadedTablesRegenerateANamedZone(): void
    {
        file_put_contents($this->directory.'/config/aggregate.yaml', Yaml::dump([
            'operator_setting' => 'preserve',
            ReportingTimezone::KEY => 'America/Chicago',
        ]));
        $executed = [];
        $tester = $this->tester(new MySQL80Platform(), '2024-07-15 07:00:00', $executed);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Regenerated the BI views for America/Chicago', $tester->getDisplay());
        self::assertNotEmpty($executed);
        self::assertStringContainsString('America/Chicago', $executed[0]);
        self::assertSame('preserve', Yaml::parseFile($this->directory.'/config/aggregate.yaml')['operator_setting']);
    }

    public function testUtcDoesNotRegenerateAfterTheTablesAreLoaded(): void
    {
        $executed = [];
        $tester = $this->tester(new MySQL80Platform(), '2024-07-15 07:00:00', $executed);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('still UTC', $tester->getDisplay());
        self::assertSame([], $executed);
    }

    /** @param list<string> $executed */
    private function tester(AbstractPlatform $platform, ?string $converted, array &$executed = [], bool $missingTools = false): CommandTester
    {
        $config = new AggregateConfigLoader($this->directory, 'test');
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->method('fetchOne')->willReturn($converted);
        $connection->method('executeStatement')->willReturnCallback(static function (string $sql) use (&$executed): int {
            $executed[] = $sql;

            return 0;
        });
        $zones = new MysqlTimeZoneTables(
            $connection,
            $this->directory.'/no-shadow',
            $missingTools ? $this->directory.'/no-loader' : null,
            $missingTools ? $this->directory.'/no-client' : null,
            $missingTools ? $this->directory.'/no-zoneinfo' : null,
        );

        return new CommandTester(new LoadMysqlTimeZonesCommand(
            $zones,
            new ReportingTimezone($config),
            new AnalyticsPrivacySettings($config),
            new AnonymousBiViewManager($connection, null, new ReportingTimezone($config), $zones),
        ));
    }
}
