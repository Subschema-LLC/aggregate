<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\DashboardCommand;
use App\Service\AggregateConfigLoader;
use App\Service\BootSettings;
use App\Service\Cluster\ServerRole;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/**
 * The dashboard switch from the command line, saved where the settings
 * loader saves it and read from there by the kernel.
 */
final class DashboardCommandTest extends TestCase
{
    private string $directory;
    private array $environment;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        unset($_ENV['DASHBOARD_ENABLED'], $_SERVER['DASHBOARD_ENABLED']);
        $this->directory = sys_get_temp_dir().'/aggregate-dashboard-'.bin2hex(random_bytes(6));
        (new Filesystem())->dumpFile($this->directory.'/config/aggregate.yaml', "app_host: 'https://analytics.example.com'\n");
    }

    protected function tearDown(): void
    {
        [$_ENV, $_SERVER] = $this->environment;
        (new Filesystem())->remove($this->directory);
    }

    public function testTheDashboardIsTurnedOffAndOnWhereTheKernelReadsIt(): void
    {
        $tester = $this->tester('all');
        self::assertSame(0, $tester->execute(['--json' => true]));
        self::assertTrue(json_decode($tester->getDisplay(), true)['dashboard_enabled']);

        self::assertSame(0, $tester->execute(['state' => 'off']));
        self::assertFalse(Yaml::parseFile($this->directory.'/config/aggregate.yaml')['dashboard_enabled']);
        self::assertFalse(BootSettings::read($this->directory, 'prod')['dashboard_enabled'], 'The kernel reads the top-level value the loader saved.');
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('The dashboard is off.', $tester->getDisplay());

        self::assertSame(0, $tester->execute(['state' => 'on']));
        self::assertTrue(BootSettings::read($this->directory, 'prod')['dashboard_enabled']);
    }

    public function testTheEnvironmentEntryIsWrittenWhenTheFileHasOne(): void
    {
        file_put_contents($this->directory.'/config/aggregate.yaml', Yaml::dump(['environments' => ['prod' => ['dashboard_enabled' => true]]], 4));
        self::assertSame(0, $this->tester('administration')->execute(['state' => 'off']));

        self::assertFalse(Yaml::parseFile($this->directory.'/config/aggregate.yaml')['environments']['prod']['dashboard_enabled']);
        self::assertFalse(BootSettings::read($this->directory, 'prod')['dashboard_enabled']);
    }

    public function testAnotherValueAndOtherRolesAreRefused(): void
    {
        self::assertSame(1, $this->tester('all')->execute(['state' => 'maybe']));

        $collection = $this->tester('collection');
        self::assertSame(1, $collection->execute(['state' => 'off']));
        self::assertStringContainsString('configuration is changed on', $collection->getDisplay());
        self::assertArrayNotHasKey('dashboard_enabled', Yaml::parseFile($this->directory.'/config/aggregate.yaml'));
    }

    private function tester(string $role): CommandTester
    {
        $_ENV['SERVER_ROLE'] = $_SERVER['SERVER_ROLE'] = $role;
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'mysql://a:b@10.0.0.5/aggregate';

        return new CommandTester(new DashboardCommand(
            new AggregateConfigLoader($this->directory, 'prod'),
            ServerRole::of($role, 'mysql://a:b@10.0.0.5/aggregate'),
        ));
    }
}
