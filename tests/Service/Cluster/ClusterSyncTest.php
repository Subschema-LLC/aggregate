<?php

declare(strict_types=1);

namespace App\Tests\Service\Cluster;

use App\Controller\ClusterConfigController;
use App\Service\AggregateConfigLoader;
use App\Service\Cluster\ClusterAlerts;
use App\Service\Cluster\ClusterConfigPublisher;
use App\Service\Cluster\ClusterServers;
use App\Service\Cluster\ClusterSettings;
use App\Service\Cluster\ClusterSignature;
use App\Service\Cluster\ClusterState;
use App\Service\Cluster\ClusterSync;
use App\Service\Cluster\ConfigBundle;
use App\Service\Cluster\ServerRole;
use App\Service\InstalledRelease;
use App\Service\Operations\TaskTrigger;
use App\Service\ParsedYamlCache;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Yaml\Yaml;

/**
 * A collection server pulling from an administration server, end to end
 * through the configuration endpoint, with the shared database's record of
 * the version kept in memory.
 */
final class ClusterSyncTest extends TestCase
{
    private const KEY = 'cluster-test-key-0123456789abcdefghijklmnop';
    private const SHARED_DATABASE = 'mysql://aggregate:secret@10.0.0.5:3306/aggregate?serverVersion=10.11.14-MariaDB';

    private string $administration;
    private string $collection;
    private array $environment;
    private MockClock $clock;
    private MemoryPublisher $publisher;
    /** @var 'serve'|'fail' */
    private string $network = 'serve';
    private string $clientIp = '10.0.0.11';

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        foreach (['SERVER_ROLE', 'ADMINISTRATION_URL', 'CLUSTER_SERVER_NAME'] as $name) {
            unset($_ENV[$name], $_SERVER[$name]);
        }
        $_ENV['CLUSTER_KEY'] = $_SERVER['CLUSTER_KEY'] = self::KEY;
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = self::SHARED_DATABASE;
        $_ENV['CLUSTER_SERVER_NAME'] = $_SERVER['CLUSTER_SERVER_NAME'] = 'collect-1';
        $base = sys_get_temp_dir().'/aggregate-cluster-'.bin2hex(random_bytes(6));
        $this->administration = $base.'/administration';
        $this->collection = $base.'/collection';
        $fs = new Filesystem();
        $fs->dumpFile($this->administration.'/config/aggregate.yaml', Yaml::dump([
            'server_role' => 'administration',
            'app_host' => 'https://admin.example.com',
            'collection_base_url' => 'https://collect.example.com',
            'cluster' => [['name' => 'collect-1', 'address' => '10.0.0.0/24']],
        ], 4));
        $fs->dumpFile($this->administration.'/config/websites.yaml', Yaml::dump(['websites' => [['name' => 'Shop', 'domain' => 'shop.example', 'token' => 'shop-token']]], 4));
        $fs->dumpFile($this->administration.'/config/tag-manager/sites/abc123.yaml', "tag_manager:\n  enabled: true\n");
        $fs->dumpFile($this->administration.'/config/website-scopes/websites/shop.yaml', "bot_traffic:\n  mode: exclude\n");
        $fs->dumpFile($this->administration.'/var/branding/prod/logo.png', "\x89PNG\r\n\x1a\nnot-really-an-image");
        $fs->dumpFile($this->administration.'/config/secrets/api_tokens.yaml', "tokens: secret\n");
        $fs->dumpFile($this->administration.'/config/permission-groups.yaml', "groups: {}\n");
        $fs->dumpFile($this->collection.'/config/aggregate.yaml', Yaml::dump([
            'server_role' => 'collection',
            'administration_url' => 'https://admin.example.com',
            'app_host' => 'https://stale.example.com',
        ], 4));
        $fs->dumpFile($this->collection.'/config/tag-manager/sites/gone.yaml', "tag_manager:\n  enabled: true\n");
        $this->clock = new MockClock('@'.time());
        $this->publisher = new MemoryPublisher($this->administration);
    }

    protected function tearDown(): void
    {
        [$_ENV, $_SERVER] = $this->environment;
        (new Filesystem())->remove(dirname($this->administration));
    }

    public function testACollectionServerPullsTheConfigurationAndKeepsItsOwnRole(): void
    {
        $result = $this->collectionSync()->run();

        self::assertTrue($result['ok'], implode(' ', $result['messages']));
        self::assertStringStartsWith('Pulled configuration', $result['messages'][0]);
        foreach (['config/websites.yaml', 'config/tag-manager/sites/abc123.yaml', 'config/website-scopes/websites/shop.yaml', 'var/branding/prod/logo.png'] as $path) {
            self::assertFileEquals($this->administration.'/'.$path, $this->collection.'/'.$path, $path.' was not copied.');
        }
        self::assertFileDoesNotExist($this->collection.'/config/tag-manager/sites/gone.yaml', 'A file the administration server no longer has is removed.');
        self::assertFileDoesNotExist($this->collection.'/config/secrets/api_tokens.yaml', 'Secrets never leave the administration server.');
        self::assertFileDoesNotExist($this->collection.'/config/permission-groups.yaml', 'Dashboard-only files are not shared.');
        $settings = Yaml::parseFile($this->collection.'/config/aggregate.yaml');
        self::assertSame('collection', $settings['server_role'], 'The server keeps its own role.');
        self::assertSame('https://admin.example.com', $settings['administration_url'] ?? null,
            'The server keeps the administration server\'s address set in its YAML, or it could not pull again.');
        self::assertSame('https://collect.example.com', $settings['collection_base_url']);
        self::assertSame('https://admin.example.com', $settings['app_host']);
        self::assertSame('collection', ServerRole::resolve($this->collection, 'prod')->role);
        self::assertSame('https://admin.example.com', ServerRole::resolve($this->collection, 'prod')->administrationUrl);

        $state = (new ClusterState($this->collection))->read();
        self::assertSame($this->publisher->recordedVersion(), $state['version']);
        self::assertSame($this->clock->now()->getTimestamp(), $state['verified_at']);
        self::assertNull((new ClusterState($this->collection))->pauseReason(5, $this->clock->now()->getTimestamp()));
        $recorded = (new ClusterServers($this->administration))->all()['collect-1'];
        self::assertSame(['collection', '10.0.0.11'], [$recorded['role'], $recorded['address']]);
    }

    public function testAnUnchangedConfigurationIsVerifiedWithoutBeingSentAgain(): void
    {
        $this->collectionSync()->run();
        $this->clock->sleep(60);
        $result = $this->collectionSync()->run();

        self::assertTrue($result['ok']);
        self::assertStringStartsWith('The configuration is the newest', $result['messages'][0]);
        self::assertSame($this->clock->now()->getTimestamp(), (new ClusterState($this->collection))->read()['verified_at']);
    }

    public function testAnAdministrationOutageWithTheSameVersionKeepsCollecting(): void
    {
        $this->collectionSync()->run();
        $this->network = 'fail';
        $this->clock->sleep(600);
        $result = $this->collectionSync()->run();

        self::assertFalse($result['ok']);
        self::assertStringContainsString('the recorded version is this server\'s own', strtolower($result['messages'][0]));
        self::assertNull((new ClusterState($this->collection))->pauseReason(5, $this->clock->now()->getTimestamp()), 'An unreachable administration server alone does not pause collection.');
    }

    public function testANetworkSplitWithANewerVersionPausesAfterTheLimit(): void
    {
        $this->collectionSync()->run();
        file_put_contents($this->administration.'/config/websites.yaml', Yaml::dump(['websites' => [['name' => 'Shop', 'domain' => 'shop.example', 'token' => 'shop-token', 'domain_policy' => ['mode' => 'exact']]]], 4));
        $this->publisher->publish(TaskTrigger::command());
        $this->network = 'fail';
        $state = new ClusterState($this->collection);

        $this->collectionSync()->run();
        $behindSince = $state->read()['behind_since'];
        self::assertSame($this->clock->now()->getTimestamp(), $behindSince);
        self::assertNull($state->pauseReason(5, $this->clock->now()->getTimestamp()));

        $this->clock->sleep(240);
        $this->collectionSync()->run();
        self::assertSame($behindSince, $state->read()['behind_since'], 'behind_since is kept from the first minute.');
        self::assertNull($state->pauseReason(5, $this->clock->now()->getTimestamp()));

        $this->clock->sleep(120);
        self::assertStringContainsString('behind a newer configuration', (string) $state->pauseReason(5, $this->clock->now()->getTimestamp()));

        $this->network = 'serve';
        $this->collectionSync()->run();
        self::assertNull($state->pauseReason(5, $this->clock->now()->getTimestamp()), 'The first good pull resumes collection.');
        self::assertSame($this->publisher->recordedVersion(), $state->read()['version']);
    }

    public function testADeadTimerOrAMissingDatabaseIsNotVerified(): void
    {
        $state = new ClusterState($this->collection);
        self::assertStringContainsString('has not verified', (string) $state->pauseReason(5, time()), 'A server that never verified does not collect.');

        $this->collectionSync()->run();
        $verifiedAt = $state->read()['verified_at'];
        $this->network = 'fail';
        $this->publisher->databaseDown = true;
        $this->clock->sleep(120);
        $result = $this->collectionSync()->run();
        self::assertStringContainsString('database could not be reached', $result['messages'][0]);
        self::assertSame($verifiedAt, $state->read()['verified_at']);

        self::assertStringContainsString('has not verified its configuration for 6 minutes', (string) $state->pauseReason(5, $verifiedAt + 360));
    }

    public function testTheEndpointAnswersOnlyListedServersWithAFreshSignatureAsAnUnknownRoute(): void
    {
        $controller = $this->controller();
        $signed = fn (string $key, int $time, string $ip = '10.0.0.11', string $name = 'collect-1'): Request => Request::create(ClusterSync::PATH, 'GET', [], [], [], ['REMOTE_ADDR' => $ip] + array_combine(
            array_map(static fn (string $h): string => 'HTTP_'.strtoupper(str_replace('-', '_', $h)), array_keys($headers = ClusterSignature::headers($key, 'GET', ClusterSync::PATH, $name, $time))),
            array_values($headers),
        ));

        self::assertSame(200, $controller($signed(self::KEY, time()))->getStatusCode());
        foreach ([
            'another key' => $signed(str_repeat('x', 40), time()),
            'an old request' => $signed(self::KEY, time() - 120),
            'another address' => $signed(self::KEY, time(), '203.0.113.9'),
            'an unlisted server' => $signed(self::KEY, time(), '10.0.0.11', 'collect-9'),
            'no signature' => Request::create(ClusterSync::PATH, 'GET', [], [], [], ['REMOTE_ADDR' => '10.0.0.11']),
        ] as $case => $request) {
            try {
                $controller($request);
                self::fail('The endpoint answered '.$case.'.');
            } catch (NotFoundHttpException) {
                $this->addToAssertionCount(1);
            }
        }

        $onAll = new ClusterConfigController('all', $this->adminConfig(), $this->publisher, new ClusterServers($this->administration), $this->clock);
        $this->expectException(NotFoundHttpException::class);
        $onAll($signed(self::KEY, time()));
    }

    public function testTheAdministrationServerAlertsOnceWhenAServerStopsPullingAndWhenItIsBack(): void
    {
        $alerts = $this->createMock(ClusterAlerts::class);
        $sent = [];
        $alerts->method('send')->willReturnCallback(static function (string $server, string $type) use (&$sent): array {
            $sent[] = $type.' '.$server;

            return [];
        });
        $administration = fn (): ClusterSync => new ClusterSync(
            ServerRole::of('administration', self::SHARED_DATABASE), new ClusterSettings($this->adminConfig()), new ClusterState($this->administration),
            new ClusterServers($this->administration), $this->publisher, $alerts, new InstalledRelease($this->administration),
            new MockHttpClient(), $this->administration, 'prod', $this->clock,
        );

        $administration()->run();
        $this->clock->sleep(360);
        $administration()->run();
        $administration()->run();
        self::assertSame(['firing collect-1'], $sent, 'A server added but never pulling is alerted once.');

        $this->collectionSync()->run();
        $administration()->run();
        self::assertSame(['firing collect-1', 'recovered collect-1'], $sent);
    }

    public function testABundleCannotNameFilesOutsideItsOwn(): void
    {
        foreach (['../.env.local', 'config/secrets/api_tokens.yaml', '.env', 'config/services.yaml', 'public/index.php'] as $path) {
            $bundle = ['format' => 1, 'version' => ConfigBundle::version([$path => 'x']), 'files' => [['path' => $path, 'sha256' => hash('sha256', 'x'), 'content' => base64_encode('x')]]];
            try {
                ConfigBundle::decode($bundle);
                self::fail($path.' was accepted.');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('may not change', $e->getMessage());
            }
        }
        $bundle = ConfigBundle::build($this->administration);
        $bundle['files'][0]['content'] = base64_encode('changed');
        $this->expectExceptionMessage('arrived damaged');
        ConfigBundle::decode($bundle);
    }

    public function testTheLocalRoleIsKeptWhereTheEnvironmentReadsIt(): void
    {
        file_put_contents($this->administration.'/config/aggregate.yaml', Yaml::dump([
            'app_host' => 'https://admin.example.com',
            'environments' => ['prod' => ['server_role' => 'administration', 'dashboard_enabled' => true]],
        ], 4));
        file_put_contents($this->collection.'/config/aggregate.yaml', Yaml::dump(['environments' => ['prod' => ['server_role' => 'collection']]], 4));

        ConfigBundle::apply($this->collection, 'prod', ConfigBundle::decode(ConfigBundle::build($this->administration)));

        $settings = Yaml::parseFile($this->collection.'/config/aggregate.yaml');
        self::assertSame('collection', $settings['environments']['prod']['server_role']);
        self::assertTrue($settings['environments']['prod']['dashboard_enabled']);
        self::assertSame('collection', ServerRole::resolve($this->collection, 'prod')->role);
    }

    private function collectionSync(): ClusterSync
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            if ($this->network === 'fail') {
                throw new TransportException('Could not connect to admin.example.com.');
            }
            $parts = parse_url($url);
            parse_str($parts['query'] ?? '', $query);
            $server = ['REMOTE_ADDR' => $this->clientIp];
            foreach ($options['normalized_headers'] as $name => $values) {
                $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = substr($values[0], strlen($name) + 2);
            }
            try {
                $response = $this->controller()(Request::create($parts['path'], $method, $query, [], [], $server));
            } catch (NotFoundHttpException) {
                return new MockResponse('', ['http_code' => 404]);
            }

            return new MockResponse((string) $response->getContent(), ['http_code' => $response->getStatusCode()]);
        });

        return new ClusterSync(
            ServerRole::of('collection', self::SHARED_DATABASE, 'https://admin.example.com'),
            new ClusterSettings(new AggregateConfigLoader($this->collection, 'prod', parsedYaml: new ParsedYamlCache($this->collection.'/var/cache'))),
            new ClusterState($this->collection), new ClusterServers($this->collection), $this->publisher,
            $this->createMock(ClusterAlerts::class), new InstalledRelease($this->collection), $client, $this->collection, 'prod', $this->clock,
        );
    }

    private function controller(): ClusterConfigController
    {
        return new ClusterConfigController('administration', $this->adminConfig(), $this->publisher, new ClusterServers($this->administration), $this->clock);
    }

    private function adminConfig(): AggregateConfigLoader
    {
        return new AggregateConfigLoader($this->administration, 'prod', parsedYaml: new ParsedYamlCache($this->administration.'/var/cache'));
    }
}

/** The shared database's record of the configuration version, in memory. */
final class MemoryPublisher extends ClusterConfigPublisher
{
    private ?string $recorded = null;
    public bool $databaseDown = false;

    public function __construct(private readonly string $administration)
    {
    }

    public function recordedVersion(): ?string
    {
        if ($this->databaseDown) {
            throw new \RuntimeException('Connection refused.');
        }

        return $this->recorded;
    }

    public function publish(TaskTrigger $trigger): array
    {
        $bundle = ConfigBundle::build($this->administration);
        $changed = $this->recorded !== $bundle['version'];
        $this->recorded = $bundle['version'];

        return ['version' => $bundle['version'], 'recorded' => $changed, 'bundle' => $bundle];
    }
}
