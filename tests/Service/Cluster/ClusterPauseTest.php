<?php

declare(strict_types=1);

namespace App\Tests\Service\Cluster;

use App\Command\ClusterJoinCommand;
use App\Controller\HealthController;
use App\Controller\ReceiveController;
use App\EventSubscriber\ClusterWorkerPause;
use App\Service\AggregateConfigLoader;
use App\Service\Cluster\ClusterGate;
use App\Service\Cluster\ClusterSignature;
use App\Service\Cluster\ClusterState;
use App\Service\EventIngestion;
use App\Service\InstalledRelease;
use App\Service\ParsedYamlCache;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Worker;

/**
 * A collection or worker server whose configuration may be out of date
 * records nothing and tells the balancer so; `all` never reads the state.
 */
final class ClusterPauseTest extends TestCase
{
    private const KEY = 'cluster-test-key-0123456789abcdefghijklmnop';

    private string $directory;
    private array $environment;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        $_ENV['CLUSTER_KEY'] = $_SERVER['CLUSTER_KEY'] = self::KEY;
        $this->directory = sys_get_temp_dir().'/aggregate-cluster-pause-'.bin2hex(random_bytes(6));
        (new Filesystem())->mkdir($this->directory.'/config');
        file_put_contents($this->directory.'/config/aggregate.yaml', "cluster_config_max_age_minutes: 5\n");
    }

    protected function tearDown(): void
    {
        [$_ENV, $_SERVER] = $this->environment;
        (new Filesystem())->remove($this->directory);
    }

    public function testAPausedCollectionServerRecordsNothing(): void
    {
        // Uninitialised: if the controller called it, the test would fail.
        $ingestion = (new \ReflectionClass(EventIngestion::class))->newInstanceWithoutConstructor();
        $request = Request::create('/api/receive', 'POST', server: ['HTTP_ORIGIN' => 'https://shop.example']);

        $response = (new ReceiveController())($request, $ingestion, $this->gate('collection'));

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('https://shop.example', $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function testHealthTellsTheBalancerAndOnlyTheClusterLearnsWhy(): void
    {
        $health = new HealthController(
            DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]),
            $this->config(),
            null,
            $this->gate('collection'),
            new ClusterState($this->directory),
            new InstalledRelease($this->directory),
        );

        $anonymous = $health(Request::create('/api/health'));
        self::assertSame(503, $anonymous->getStatusCode());
        $body = json_decode((string) $anonymous->getContent(), true);
        self::assertSame('error', $body['checks']['cluster']['status']);
        self::assertArrayNotHasKey('cluster', $body, 'An anonymous caller learns nothing about the cluster.');

        $headers = ClusterSignature::headers(self::KEY, 'GET', '/api/health', 'monitor', time());
        $signed = $health(Request::create('/api/health', server: array_combine(
            array_map(static fn (string $h): string => 'HTTP_'.strtoupper(str_replace('-', '_', $h)), array_keys($headers)),
            array_values($headers),
        )));
        $cluster = json_decode((string) $signed->getContent(), true)['cluster'];
        self::assertStringContainsString('has not verified', $cluster['paused']);
        self::assertNull($cluster['configuration_version']);
    }

    public function testACurrentServerCollectsAndAllNeverReadsTheState(): void
    {
        (new ClusterState($this->directory))->write(['version' => str_repeat('a', 64), 'verified_at' => time(), 'behind_since' => null, 'pulled_at' => time(), 'problem' => null]);
        self::assertNull($this->gate('collection')->pauseReason());

        unlink($this->directory.'/var/cluster_state.json');
        self::assertNull($this->gate('all')->pauseReason(), 'A single server never pauses.');
        self::assertNull($this->gate('administration')->pauseReason());
    }

    public function testAPausedWorkerStopsItsConsumerBeforeTakingAMessage(): void
    {
        $worker = $this->getMockBuilder(Worker::class)->disableOriginalConstructor()->onlyMethods(['stop'])->getMock();
        $worker->expects(self::once())->method('stop');
        (new ClusterWorkerPause($this->gate('worker')))->onStarted(new WorkerStartedEvent($worker));

        $current = $this->getMockBuilder(Worker::class)->disableOriginalConstructor()->onlyMethods(['stop'])->getMock();
        $current->expects(self::never())->method('stop');
        (new ClusterWorkerPause($this->gate('all')))->onStarted(new WorkerStartedEvent($current));
    }

    public function testJoiningWritesTheServersOwnSettingsFromJson(): void
    {
        $command = new ClusterJoinCommand($this->directory, 'prod');
        $variables = ClusterJoinCommand::variables([
            'role' => 'collection', 'name' => 'collect-1', 'administration_url' => 'https://admin.example.com/',
            'cluster_key' => self::KEY, 'database_url' => 'mysql://aggregate:secret@10.0.0.5:3306/aggregate', 'trusted_proxies' => '10.0.0.2, 10.0.0.3',
        ]);
        self::assertSame([
            'SERVER_ROLE' => 'collection', 'CLUSTER_SERVER_NAME' => 'collect-1', 'CLUSTER_KEY' => self::KEY,
            'ADMINISTRATION_URL' => 'https://admin.example.com', 'DATABASE_URL' => 'mysql://aggregate:secret@10.0.0.5:3306/aggregate',
            'TRUSTED_PROXIES' => '10.0.0.2,10.0.0.3',
        ], $variables);

        foreach ([
            [['role' => 'all'], 'role must be'],
            [['role' => 'collection', 'name' => 'Collect 1', 'cluster_key' => self::KEY], 'server name'],
            [['role' => 'collection', 'name' => 'collect-1', 'cluster_key' => 'short'], 'cluster_key'],
            [['role' => 'collection', 'name' => 'collect-1', 'cluster_key' => self::KEY], 'administration_url'],
            [['role' => 'worker', 'name' => 'w', 'cluster_key' => self::KEY, 'administration_url' => 'https://a.example', 'database_url' => 'sqlite:///x.db'], 'SQLite cannot be shared'],
            [['role' => 'worker', 'password' => 'x'], 'Unknown setting'],
        ] as [$settings, $expected]) {
            try {
                ClusterJoinCommand::variables($settings);
                self::fail('Accepted '.json_encode($settings));
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString($expected, $e->getMessage());
            }
        }

        $tester = new CommandTester($command);
        self::assertSame(2, $tester->execute([]), 'Secrets come from standard input only.');
    }

    private function gate(string $role): ClusterGate
    {
        return new ClusterGate($role, new ClusterState($this->directory), $this->config());
    }

    private function config(): AggregateConfigLoader
    {
        return new AggregateConfigLoader($this->directory, 'prod', parsedYaml: new ParsedYamlCache($this->directory.'/var/cache'));
    }
}
