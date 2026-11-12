<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\UpdatesController;
use App\Service\AggregateConfigLoader;
use App\Service\Alerts\AlertSettings;
use App\Service\ApplicationUpdateService;
use App\Service\Cluster\ClusterConfigPublisher;
use App\Service\Cluster\ClusterOverview;
use App\Service\Cluster\ClusterServers;
use App\Service\Cluster\ServerRole;
use App\Service\FeatureFlags;
use App\Service\InstalledRelease;
use App\Service\ParsedYamlCache;
use App\Service\Update\ApplicationUpdater;
use App\Service\Update\BackgroundUpdate;
use App\Service\Update\SystemCheck;
use App\Service\UpdateSettings;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;
use Symfony\Component\Yaml\Yaml;

/**
 * The Updates page's Servers panel on an administration server: the server
 * list, the cluster settings and the balancer's configuration, through the
 * same services as app:cluster:server, app:config and
 * app:server:balancer-config.
 */
final class UpdatesClusterPanelTest extends TestCase
{
    private const DATABASE = 'mysql://aggregate:secret@10.0.0.5:3306/aggregate?serverVersion=8.0.0';

    private string $directory;
    private array $environment;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        foreach (['COLLECTION_BASE_URL', 'APP_HOST', 'RATE_LIMIT_PER_MINUTE'] as $name) {
            unset($_ENV[$name], $_SERVER[$name]);
        }
        $this->directory = sys_get_temp_dir().'/aggregate-cluster-panel-'.bin2hex(random_bytes(6));
        (new Filesystem())->mkdir($this->directory.'/config');
        file_put_contents($this->directory.'/config/aggregate.yaml', Yaml::dump([
            'app_host' => 'https://admin.example.com',
            'collection_base_url' => 'https://collect.example.com',
            'feature_flags' => ['updates' => ['enabled' => true]],
        ]));
    }

    protected function tearDown(): void
    {
        [$_ENV, $_SERVER] = $this->environment;
        (new Filesystem())->remove($this->directory);
    }

    public function testServersAreAddedAndRemovedWithTheCommandsChecks(): void
    {
        $request = $this->post(['name' => 'collect-1', 'address' => '10.0.0.11', 'role' => 'collection', 'port' => '']);
        $this->controller($request)->addClusterServer($request);
        self::assertSame([['name' => 'collect-1', 'address' => '10.0.0.11', 'role' => 'collection']], $this->saved()['cluster']);
        self::assertSame('collect-1', $request->attributes->get('_audit_subject'));

        $refused = $this->post(['name' => 'Collect 2', 'address' => '10.0.0.12', 'role' => 'collection']);
        $this->controller($refused)->addClusterServer($refused);
        self::assertCount(1, $this->saved()['cluster']);
        self::assertStringContainsString('lower-case', implode(' ', $refused->getSession()->getFlashBag()->get('error')));

        $remove = $this->post([]);
        $this->controller($remove)->removeClusterServer($remove, 'collect-1');
        self::assertSame([], $this->saved()['cluster']);
    }

    public function testClusterSettingsAreSavedWithTheirRules(): void
    {
        $request = $this->post(['max_age_minutes' => '10', 'collection_port' => '8080', 'alert_destinations_mode' => 'selected', 'alert_destinations' => ['ops']]);
        $this->controller($request)->saveClusterSettings($request);
        self::assertSame(10, $this->saved()['cluster_config_max_age_minutes']);
        self::assertSame(8080, $this->saved()['cluster_collection_port']);
        self::assertSame(['ops'], $this->saved()['cluster_alert_destinations']);

        $refused = $this->post(['max_age_minutes' => '90', 'collection_port' => '8080', 'alert_destinations_mode' => 'all']);
        $this->controller($refused)->saveClusterSettings($refused);
        self::assertSame(10, $this->saved()['cluster_config_max_age_minutes'], 'Nothing is saved when one value is refused.');
        self::assertSame(['ops'], $this->saved()['cluster_alert_destinations']);
    }

    public function testTheBalancerConfigurationIsTheCommandsOwn(): void
    {
        $request = $this->post(['name' => 'collect-1', 'address' => '10.0.0.11', 'role' => 'collection']);
        $this->controller($request)->addClusterServer($request);

        $response = $this->controller(new Request())->balancerConfiguration('nginx');
        self::assertSame('attachment; filename=aggregate-balancer.conf', $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('server 10.0.0.11:80', (string) $response->getContent());
        self::assertStringContainsString('server_name collect.example.com;', (string) $response->getContent());

        self::assertStringContainsString('reverse_proxy 10.0.0.11:80', (string) $this->controller(new Request())->balancerConfiguration('caddy')->getContent());
    }

    public function testThePanelShowsItsFormsOnAnAdministrationServerEvenBeforeTheFirstServer(): void
    {
        $html = (string) $this->controller(new Request(), withTwig: true)->index()->getContent();

        self::assertStringContainsString('id="cluster-servers"', $html);
        self::assertStringContainsString('Add a server', $html);
        self::assertStringContainsString('Save cluster settings', $html);
        self::assertStringContainsString('app:cluster:key', $html);
        self::assertStringContainsString('No server may pull configuration yet.', $html);
    }

    private function controller(Request $request, bool $withTwig = false): UpdatesController
    {
        $config = new AggregateConfigLoader($this->directory, 'test', parsedYaml: new ParsedYamlCache($this->directory.'/var/cache'));
        $alerts = $this->createStub(AlertSettings::class);
        $alerts->method('destinations')->willReturn(['ops' => ['id' => 'ops']]);
        $publisher = $this->createStub(ClusterConfigPublisher::class);
        $publisher->method('recordedVersion')->willReturn(null);
        $overview = new ClusterOverview($config, new ClusterServers($this->directory), $publisher, new InstalledRelease($this->directory));
        $updates = $this->createStub(ApplicationUpdateService::class);
        $updates->method('check')->willReturn([
            'state' => 'up_to_date',
            'installation_type' => 'git',
            'branch' => 'master',
            'installed_branch' => 'master',
            'current_commit' => str_repeat('a', 40),
            'latest_commit' => str_repeat('a', 40),
            'checked_at' => 1789387200,
            'message' => 'Up to date.',
            'compare_url' => null,
        ]);
        $updates->method('detectedMethod')->willReturn('release');
        $updater = $this->createStub(ApplicationUpdater::class);
        $updater->method('status')->willReturn(null);
        $updater->method('isSqlite')->willReturn(false);
        $checks = $this->createStub(SystemCheck::class);
        $checks->method('run')->willReturn([]);
        $checks->method('problems')->willReturn([]);

        $controller = new UpdatesController(
            $config, $updates, new FeatureFlags($config), $updater, $this->createStub(BackgroundUpdate::class), $checks, new UpdateSettings($config),
            null, $overview, ServerRole::of('administration', self::DATABASE), $alerts,
        );
        $authorization = $this->createStub(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willReturn(true);
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(true);
        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturn('/dashboard/updates');
        $stack = new RequestStack();
        $stack->push($request);

        $container = new Container();
        $container->set('security.authorization_checker', $authorization);
        $container->set('security.csrf.token_manager', $csrf);
        $container->set('router', $router);
        $container->set('request_stack', $stack);
        if ($withTwig) {
            $twig = new Environment(new ChainLoader([
                new ArrayLoader(['base.html.twig' => '{% block body %}{% endblock %}']),
                new FilesystemLoader(dirname(__DIR__, 2).'/templates'),
            ]), ['strict_variables' => true]);
            $twig->addFunction(new TwigFunction('path', static fn (string $route, array $parameters = []): string => '/'.$route));
            $twig->addFunction(new TwigFunction('csrf_token', static fn (): string => 'rendered-token'));
            \App\Tests\Support\TwigComponents::register($twig, 'https://docs.example.test/aggregate/');
            $container->set('twig', $twig);
        }
        $controller->setContainer($container);

        return $controller;
    }

    private function post(array $fields): Request
    {
        $request = Request::create('/dashboard/updates/cluster', 'POST', ['_csrf_token' => 'token', ...$fields]);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    /** @return array<string, mixed> */
    private function saved(): array
    {
        return (array) Yaml::parseFile($this->directory.'/config/aggregate.yaml');
    }
}
