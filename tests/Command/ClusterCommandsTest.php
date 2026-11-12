<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ClusterServerCommand;
use App\Command\ClusterSettingsCommand;
use App\Command\CollectionAddressCommand;
use App\Command\ServerBalancerConfigCommand;
use App\Service\AggregateConfigLoader;
use App\Service\Alerts\AlertSettings;
use App\Service\Cluster\ClusterConfigPublisher;
use App\Service\Cluster\ClusterOverview;
use App\Service\Cluster\ClusterServers;
use App\Service\InstalledRelease;
use App\Service\ParsedYamlCache;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/**
 * Every cluster setting can be set from the command line as well as in
 * YAML, with the validation the setting gets when it is read, and the
 * balancer's configuration is built from those settings alone.
 */
final class ClusterCommandsTest extends TestCase
{
    private string $directory;
    private array $environment;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        foreach (['SERVER_ROLE', 'COLLECTION_BASE_URL', 'APP_HOST', 'RATE_LIMIT_PER_MINUTE', 'CLUSTER_KEY'] as $name) {
            unset($_ENV[$name], $_SERVER[$name]);
        }
        $this->directory = sys_get_temp_dir().'/aggregate-cluster-commands-'.bin2hex(random_bytes(6));
        (new Filesystem())->mkdir($this->directory.'/config');
        $this->configure(['app_host' => 'https://admin.example.com', 'rate_limit_per_minute' => 200]);
    }

    protected function tearDown(): void
    {
        [$_ENV, $_SERVER] = $this->environment;
        (new Filesystem())->remove($this->directory);
    }

    public function testTheBalancerIsBuiltFromSettingsSetOnTheCommandLine(): void
    {
        self::assertSame(0, $this->execute(new CollectionAddressCommand($this->config()), ['url' => 'https://collect.example.com/']));
        self::assertSame(0, $this->execute($this->serverCommand(), ['action' => 'add', 'name' => 'collect-1', 'address' => '10.0.0.11', '--role' => 'collection']));
        self::assertSame(0, $this->execute($this->serverCommand(), ['action' => 'add', 'name' => 'collect-2', 'address' => '10.0.0.12', '--role' => 'collection']));
        self::assertSame(0, $this->execute($this->serverCommand(), ['action' => 'add', 'name' => 'worker-1', 'address' => '10.0.0.21', '--role' => 'worker']));
        self::assertSame(0, $this->execute(new ClusterSettingsCommand($this->config(), $this->alerts()), ['--collection-port' => '8080']));

        self::assertSame([
            'app_host' => 'https://admin.example.com',
            'rate_limit_per_minute' => 200,
            'collection_base_url' => 'https://collect.example.com',
            'cluster' => [
                ['name' => 'collect-1', 'address' => '10.0.0.11', 'role' => 'collection'],
                ['name' => 'collect-2', 'address' => '10.0.0.12', 'role' => 'collection'],
                ['name' => 'worker-1', 'address' => '10.0.0.21', 'role' => 'worker'],
            ],
            'cluster_collection_port' => 8080,
        ], Yaml::parseFile($this->directory.'/config/aggregate.yaml'), 'Each command saves one YAML setting, as a hand edit would.');

        $tester = new CommandTester(new ServerBalancerConfigCommand($this->config(), $this->servers()));
        self::assertSame(0, $tester->execute(['--nginx' => true], ['capture_stderr_separately' => true]));
        $nginx = $tester->getDisplay();
        self::assertStringStartsWith('# Aggregate balancer for collect.example.com, from', $nginx, 'Only the configuration goes to standard output.');
        self::assertStringContainsString('server 10.0.0.11:8080 max_fails=1 fail_timeout=10s; # collect-1', $nginx);
        self::assertStringContainsString('server 10.0.0.12:8080 ', $nginx);
        self::assertStringNotContainsString('10.0.0.21', $nginx, 'Worker servers take no web traffic.');
        self::assertStringContainsString('rate=200r/m', $nginx);
        self::assertStringContainsString('Built for collect.example.com with 2 collection server(s): collect-1, collect-2.', $tester->getErrorOutput());

        self::assertSame(0, $tester->execute(['--caddy' => true], ['capture_stderr_separately' => true]));
        self::assertStringContainsString('reverse_proxy 10.0.0.11:8080 10.0.0.12:8080 {', $tester->getDisplay());
    }

    public function testTheBalancerNeedsACollectionAddressOfItsOwn(): void
    {
        $this->configure(['app_host' => 'https://admin.example.com', 'cluster' => [['name' => 'collect-1', 'address' => '10.0.0.11', 'role' => 'collection']]]);
        $tester = new CommandTester(new ServerBalancerConfigCommand($this->config(), $this->servers()));

        self::assertSame(1, $tester->execute(['--nginx' => true], ['capture_stderr_separately' => true]));
        self::assertStringContainsString('app:collection-address https://collect.example.com', $tester->getErrorOutput());
        self::assertSame('', $tester->getDisplay());

        $this->configure(['app_host' => 'https://admin.example.com', 'collection_base_url' => 'https://ADMIN.example.com', 'cluster' => [['name' => 'collect-1', 'address' => '10.0.0.11', 'role' => 'collection']]]);
        $tester = new CommandTester(new ServerBalancerConfigCommand($this->config(), $this->servers()));
        self::assertSame(1, $tester->execute(['--nginx' => true], ['capture_stderr_separately' => true]));
        self::assertStringContainsString('has the administration server\'s host name (admin.example.com)', $tester->getErrorOutput());
    }

    public function testTheBalancerSaysWhichServersItLeftOut(): void
    {
        $this->configure([
            'collection_base_url' => 'https://collect.example.com',
            'cluster' => [
                ['name' => 'collect-1', 'address' => '10.0.0.11', 'role' => 'collection'],
                ['name' => 'collect-2', 'address' => '10.0.0.12'],
            ],
        ]);
        $tester = new CommandTester(new ServerBalancerConfigCommand($this->config(), $this->servers()));

        self::assertSame(0, $tester->execute(['--caddy' => true], ['capture_stderr_separately' => true]));
        self::assertStringNotContainsString('10.0.0.12', $tester->getDisplay());
        self::assertStringContainsString('collect-2 has no role in the cluster list, so it is left out.', $tester->getErrorOutput());
    }

    public function testOptionsMustFitTheBalancersSoftware(): void
    {
        $this->configure(['collection_base_url' => 'https://collect.example.com', 'cluster' => [['name' => 'collect-1', 'address' => '10.0.0.11', 'role' => 'collection']]]);
        $tester = new CommandTester(new ServerBalancerConfigCommand($this->config(), $this->servers()));

        foreach ([[], ['--nginx' => true, '--caddy' => true], ['--caddy' => true, '--part' => 'http'], ['--nginx' => true, '--caddy-rate-limit' => true], ['--nginx' => true, '--part' => 'location']] as $options) {
            self::assertSame(1, $tester->execute($options, ['capture_stderr_separately' => true]), json_encode($options));
            self::assertSame('', $tester->getDisplay());
        }
    }

    public function testAServerIsAddedWithItsRole(): void
    {
        $command = $this->serverCommand();

        self::assertSame(1, $this->execute($command, ['action' => 'add', 'name' => 'collect-1', 'address' => '10.0.0.11']));
        self::assertSame(1, $this->execute($command, ['action' => 'add', 'name' => 'collect-1', 'address' => '10.0.0.11', '--role' => 'balancer']));
        self::assertArrayNotHasKey('cluster', Yaml::parseFile($this->directory.'/config/aggregate.yaml'));

        $tester = new CommandTester($command);
        self::assertSame(0, $tester->execute(['action' => 'add', 'name' => 'collect-1', 'address' => '10.0.0.11', '--role' => 'collection']));
        self::assertStringContainsString('Run app:server:balancer-config again', $tester->getDisplay());
        self::assertSame(0, $tester->execute(['--json' => true]));
        $list = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['collect-1', '10.0.0.11', 'collection', null], [$list['servers'][0]['name'], $list['servers'][0]['address'], $list['servers'][0]['role'], $list['servers'][0]['reported_role']]);
        self::assertSame([null, null], [$list['servers'][0]['same_release'], $list['servers'][0]['newest_configuration']], 'It has not pulled yet.');
        self::assertSame(str_repeat('a', 64), $list['configuration_version']);

        (new ClusterServers($this->directory))->recordPull('collect-1', time(), str_repeat('b', 64), '2026.11.03', 'collection', '10.0.0.11');
        self::assertSame(0, $tester->execute(['--json' => true]));
        $list = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['2026.11.03', false, 'collection'], [$list['servers'][0]['release'], $list['servers'][0]['newest_configuration'], $list['servers'][0]['reported_role']]);
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('behind', $tester->getDisplay());
        self::assertSame('collection (reports worker)', ClusterServerCommand::roleCell('collection', 'worker'));
    }

    public function testClusterSettingsAreShownAndSetWithTheirRules(): void
    {
        $command = new ClusterSettingsCommand($this->config(), $this->alerts());

        $tester = new CommandTester($command);
        self::assertSame(0, $tester->execute(['--json' => true]));
        self::assertSame(['cluster_config_max_age_minutes' => 5, 'cluster_alert_destinations' => null, 'cluster_collection_port' => 80, 'servers' => 0, 'cluster_key_set' => false],
            json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR));

        self::assertSame(1, $this->execute($command, ['--max-age-minutes' => '0']));
        self::assertSame(1, $this->execute($command, ['--max-age-minutes' => '61']));
        self::assertSame(1, $this->execute($command, ['--collection-port' => '0']));
        self::assertSame(1, $this->execute($command, ['--alert-destinations' => 'ops-webhook,pager']));
        self::assertSame(1, $this->execute($command, ['--max-age-minutes' => '10', '--collection-port' => 'x']), 'Nothing is saved when one value is wrong.');
        self::assertSame(['app_host' => 'https://admin.example.com', 'rate_limit_per_minute' => 200], Yaml::parseFile($this->directory.'/config/aggregate.yaml'));

        self::assertSame(0, $this->execute($command, ['--max-age-minutes' => '10', '--alert-destinations' => 'ops-webhook']));
        self::assertSame(0, $tester->execute(['--json' => true]));
        $shown = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame([10, ['ops-webhook']], [$shown['cluster_config_max_age_minutes'], $shown['cluster_alert_destinations']]);

        self::assertSame(0, $this->execute($command, ['--alert-destinations' => 'none']));
        self::assertSame([], Yaml::parseFile($this->directory.'/config/aggregate.yaml')['cluster_alert_destinations']);
        self::assertSame(0, $this->execute($command, ['--alert-destinations' => 'all']));
        self::assertNull(Yaml::parseFile($this->directory.'/config/aggregate.yaml')['cluster_alert_destinations'] ?? null);
    }

    public function testTheCollectionAddressIsSetAndClearedLikeGeneralSettings(): void
    {
        $command = new CollectionAddressCommand($this->config());

        self::assertSame(1, $this->execute($command, ['url' => 'collect.example.com']));
        self::assertSame(1, $this->execute($command, ['url' => 'https://collect.example.com', '--clear' => true]));
        self::assertSame(0, $this->execute($command, ['url' => 'https://collect.example.com/']));
        self::assertSame('https://collect.example.com', Yaml::parseFile($this->directory.'/config/aggregate.yaml')['collection_base_url']);

        $tester = new CommandTester($command);
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('Collection address: https://collect.example.com', $tester->getDisplay());

        self::assertSame(0, $tester->execute(['--clear' => true]));
        self::assertSame('', Yaml::parseFile($this->directory.'/config/aggregate.yaml')['collection_base_url']);

        $_ENV['COLLECTION_BASE_URL'] = 'https://track.example.com';
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('COLLECTION_BASE_URL overrides the saved value', $tester->getDisplay());
    }

    public function testCollectionAndWorkerServersChangeNoClusterSetting(): void
    {
        $_ENV['SERVER_ROLE'] = 'collection';
        $_ENV['DATABASE_URL'] = 'mysql://aggregate:secret@10.0.0.5:3306/aggregate?serverVersion=8.0.0';

        self::assertSame(1, $this->execute(new CollectionAddressCommand($this->config()), ['url' => 'https://collect.example.com']));
        self::assertSame(1, $this->execute(new ClusterSettingsCommand($this->config(), $this->alerts()), ['--max-age-minutes' => '10']));
        self::assertSame(['app_host' => 'https://admin.example.com', 'rate_limit_per_minute' => 200], Yaml::parseFile($this->directory.'/config/aggregate.yaml'));
    }

    private function execute(Command $command, array $input): int
    {
        return (new CommandTester($command))->execute($input);
    }

    private function config(): AggregateConfigLoader
    {
        return new AggregateConfigLoader($this->directory, 'prod', parsedYaml: new ParsedYamlCache($this->directory.'/cache'));
    }

    private function serverCommand(): ClusterServerCommand
    {
        $config = $this->config();
        $publisher = $this->createStub(ClusterConfigPublisher::class);
        $publisher->method('recordedVersion')->willReturn(str_repeat('a', 64));

        return new ClusterServerCommand($config, new ClusterOverview($config, $this->servers(), $publisher, new InstalledRelease($this->directory)));
    }

    private function servers(): ClusterServers
    {
        return new ClusterServers($this->directory);
    }

    private function alerts(): AlertSettings
    {
        $alerts = $this->createMock(AlertSettings::class);
        $alerts->method('destinations')->willReturn(['ops-webhook' => ['id' => 'ops-webhook'], 'ops-email' => ['id' => 'ops-email']]);

        return $alerts;
    }

    private function configure(array $settings): void
    {
        file_put_contents($this->directory.'/config/aggregate.yaml', Yaml::dump($settings, 4));
    }
}
