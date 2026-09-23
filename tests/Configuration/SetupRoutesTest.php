<?php

declare(strict_types=1);

namespace App\Tests\Configuration;

use App\Command\BuildBrowserAssetsCommand;
use App\Controller\BrowserAssetsController;
use App\Controller\SetupController;
use App\Controller\TagManagerController;
use App\Entity\User;
use App\Kernel;
use App\Service\AggregateConfigLoader;
use App\Service\BrowserAssetBuilder;
use App\Service\SiteScriptConfig;
use App\Service\WebsiteConfigManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Yaml\Yaml;

final class SetupRoutesTest extends TestCase
{
    private string $directory;
    private array $environment;
    private ?SetupRoutesKernel $kernel = null;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        foreach (['APP_HOST', 'JS_NAMESPACE', 'ANONYMOUS_TRACKING_ENABLED', 'ANONYMOUS_EXCLUDED_PATHS'] as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
        }
        $this->directory = sys_get_temp_dir().'/aggregate-setup-routes-'.bin2hex(random_bytes(8));
        (new Filesystem())->mkdir($this->directory.'/config');
        file_put_contents($this->directory.'/config/aggregate.yaml', Yaml::dump([
            'installed' => true,
            'app_host' => 'https://analytics.example.test',
            'js_namespace' => 'ExampleAnalytics',
            'admin_token' => 'private-setup-route-secret',
            'tag_manager' => ['enabled' => true, 'tags' => [['id' => 'metrics', 'src' => 'https://tags.example.test/metrics.js', 'enabled' => true]]],
        ], 6));
        file_put_contents($this->directory.'/config/websites.yaml', Yaml::dump([
            'websites' => [
                ['name' => 'Example site', 'domain' => 'example.test', 'token' => 'public-site-token'],
                ['name' => 'Second site', 'domain' => 'second.test', 'token' => 'second-site-token'],
            ],
        ], 4));
        (new Filesystem())->mkdir($this->directory.'/config/tag-manager/sites');
        foreach (['public-site-token' => 'first', 'second-site-token' => 'second'] as $token => $label) {
            file_put_contents($this->directory.'/config/tag-manager/sites/'.SiteScriptConfig::idForToken($token).'.yaml', Yaml::dump([
                'tag_manager' => ['enabled' => true, 'tags' => [['id' => $label, 'src' => 'https://'.$label.'-provider.example/script.js', 'consent' => $label]]],
                'consent_manager' => ['enabled' => true, 'name' => $label.' site choices'],
                'private_note' => 'private-site-setting',
            ], 6));
        }
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        [$_ENV, $_SERVER] = $this->environment;
        (new Filesystem())->remove($this->directory);
    }

    public function testAnonymousAndOrdinaryUsersCannotReadDownloadsOrBuild(): void
    {
        $browser = $this->browser();
        $builder = $this->createMock(BrowserAssetBuilder::class);
        $builder->expects(self::never())->method('build');
        $this->container()->set(BrowserAssetBuilder::class, $builder);
        foreach ([null, 'ROLE_USER'] as $role) {
            if ($role !== null) {
                $browser->loginUser(SetupRoutesUserProvider::user($role));
            }
            foreach (['/dashboard/setup', '/dashboard/setup/download/consent', '/dashboard/setup/download/tags', '/dashboard/tag-manager'] as $path) {
                $browser->request('GET', $path);
                self::assertSame($role === null ? 302 : 403, $browser->getResponse()->getStatusCode(), $path);
            }
            $browser->request('POST', '/dashboard/setup/build-scripts', ['_token' => 'forged']);
            self::assertSame($role === null ? 302 : 403, $browser->getResponse()->getStatusCode());
        }
        $this->assertNoDatabaseConnection();
    }

    public function testAdminCanWalkThroughSetupAndDownloadConfiguredScripts(): void
    {
        $browser = $this->browser('ROLE_ADMIN');
        foreach ([1, 2, 3, 4] as $step) {
            $browser->request('GET', '/dashboard/setup', ['step' => (string) $step]);
            self::assertSame(200, $browser->getResponse()->getStatusCode());
            self::assertTrue($browser->getResponse()->headers->hasCacheControlDirective('no-store'));
        }
        foreach (['consent', 'tags', 'snippet'] as $kind) {
            $browser->request('GET', '/dashboard/setup/download/'.$kind, ['website' => 'public-site-token', 'tags' => '1']);
            self::assertSame(200, $browser->getResponse()->getStatusCode());
            self::assertTrue($browser->getResponse()->headers->hasCacheControlDirective('private'));
            self::assertStringContainsString('attachment;', $browser->getResponse()->headers->get('Content-Disposition'));
            self::assertStringNotContainsString('private-setup-route-secret', (string) $browser->getResponse()->getContent());
        }
        self::assertStringContainsString('/cmp-lite/sites/'.SiteScriptConfig::idForToken('public-site-token').'/consent.js?min=1', (string) $browser->getResponse()->getContent());
        self::assertStringContainsString('ExampleAnalytics', (string) $browser->getResponse()->getContent());
        $browser->request('GET', '/dashboard/tag-manager');
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        $this->assertNoDatabaseConnection();
    }

    public function testBuildRequiresPostAndValidCsrfBeforeRunning(): void
    {
        $browser = $this->browser('ROLE_ADMIN');
        $builder = $this->createMock(BrowserAssetBuilder::class);
        $builder->expects(self::once())->method('build');
        $this->container()->set(BrowserAssetBuilder::class, $builder);
        $browser->request('GET', '/dashboard/setup/build-scripts');
        self::assertSame(405, $browser->getResponse()->getStatusCode());
        foreach ([[], ['_token' => 'forged'], ['_token' => ['bad']]] as $form) {
            $browser->request('POST', '/dashboard/setup/build-scripts', $form);
            self::assertSame(403, $browser->getResponse()->getStatusCode());
        }
        $crawler = $browser->request('GET', '/dashboard/setup', ['step' => '3']);
        $form = $crawler->filter('form[action="/dashboard/setup/build-scripts"]')->form();
        $browser->submit($form);
        self::assertSame(303, $browser->getResponse()->getStatusCode());
        self::assertSame('/dashboard/setup?step=3', $browser->getResponse()->headers->get('Location'));
        $this->assertNoDatabaseConnection();
    }

    public function testHeadlessModeServesConfiguredScriptsAndRetainsBuildCommand(): void
    {
        $browser = $this->browser(dashboard: false);
        foreach ([SetupController::class, TagManagerController::class, BrowserAssetsController::class] as $controller) {
            self::assertFalse($this->container()->has($controller));
        }
        self::assertInstanceOf(BuildBrowserAssetsCommand::class, $this->container()->get(BuildBrowserAssetsCommand::class));
        foreach (['/dashboard/setup', '/dashboard/tag-manager'] as $path) {
            $browser->request('GET', $path);
            self::assertSame(404, $browser->getResponse()->getStatusCode());
        }
        foreach (['/consent-manager.js' => 'ExampleAnalytics', '/lib.js' => 'tags.example.test'] as $path => $expected) {
            $browser->request('GET', $path, ['min' => '1']);
            self::assertSame(200, $browser->getResponse()->getStatusCode(), $path);
            self::assertStringContainsString('application/javascript', $browser->getResponse()->headers->get('Content-Type'));
            self::assertStringContainsString($expected, (string) $browser->getResponse()->getContent());
            self::assertStringNotContainsString('private-setup-route-secret', (string) $browser->getResponse()->getContent());
            self::assertFalse($browser->getResponse()->headers->has('Set-Cookie'));
        }
        $this->assertNoDatabaseConnection();
    }

    public function testHeadlessWebsiteInstancesServeOnlyTheirOwnSettingsAndFailIndependently(): void
    {
        $browser = $this->browser(dashboard: false);
        $first = SiteScriptConfig::idForToken('public-site-token');
        $second = SiteScriptConfig::idForToken('second-site-token');
        foreach ([$first => 'first', $second => 'second'] as $id => $label) {
            foreach (['lib' => 'tms-lite', 'consent' => 'cmp-lite'] as $kind => $prefix) {
                $browser->request('GET', '/'.$prefix.'/sites/'.$id.'/'.$kind.'.js', ['min' => '1']);
                self::assertSame(200, $browser->getResponse()->getStatusCode());
                $content = (string) $browser->getResponse()->getContent();
                self::assertStringContainsString($kind === 'lib' ? $label.'-provider.example' : $label.' site choices', $content);
                self::assertStringNotContainsString(($label === 'first' ? 'second' : 'first').'-provider.example', $content);
                self::assertStringNotContainsString('private-site-setting', $content);
                self::assertFalse($browser->getResponse()->headers->has('Set-Cookie'));
            }
        }
        $browser->request('GET', '/tms-lite/sites/'.str_repeat('0', 24).'/lib.js');
        self::assertSame(404, $browser->getResponse()->getStatusCode());
        file_put_contents($this->directory.'/config/tag-manager/sites/'.$first.'.yaml', "tag_manager: [broken\n");
        foreach (['lib' => 'tms-lite', 'consent' => 'cmp-lite'] as $kind => $prefix) {
            $browser->request('GET', '/'.$prefix.'/sites/'.$first.'/'.$kind.'.js');
            self::assertSame(503, $browser->getResponse()->getStatusCode());
            $browser->request('GET', '/'.$prefix.'/sites/'.$second.'/'.$kind.'.js');
            self::assertSame(200, $browser->getResponse()->getStatusCode());
        }
        $this->assertNoDatabaseConnection();
    }

    #[DataProvider('dashboardModes')]
    public function testWebsiteScriptsUseTheirOwnPublicPrefixWithoutCompatibilityAliases(bool $dashboard): void
    {
        $browser = $this->browser(dashboard: $dashboard);
        $siteId = SiteScriptConfig::idForToken('public-site-token');
        foreach (['/cmp-lite/sites/'.$siteId.'/consent.js', '/tms-lite/sites/'.$siteId.'/lib.js'] as $path) {
            $browser->request('GET', $path);
            self::assertSame(200, $browser->getResponse()->getStatusCode(), $path);
            self::assertFalse($browser->getResponse()->headers->has('Set-Cookie'));
        }
        foreach (['/tms-lite/sites/'.$siteId.'/consent.js', '/cmp-lite/sites/'.$siteId.'/lib.js'] as $path) {
            $browser->request('GET', $path);
            self::assertSame(404, $browser->getResponse()->getStatusCode(), $path);
        }
        $this->assertNoDatabaseConnection();
    }

    public static function dashboardModes(): iterable
    {
        yield 'dashboard enabled' => [true];
        yield 'headless' => [false];
    }

    public function testWebsiteDownloadsUseSelectedScopeAndCannotExportUnrelatedSettings(): void
    {
        $browser = $this->browser('ROLE_ADMIN');
        $site = SiteScriptConfig::idForToken('second-site-token');
        $browser->request('GET', '/dashboard/setup/download/tags', ['website' => 'second-site-token']);
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        self::assertStringContainsString('/tms-lite/sites/'.$site.'/lib.js', (string) $browser->getResponse()->getContent());
        $browser->request('GET', '/dashboard/setup/download/consent', ['website' => 'second-site-token']);
        self::assertStringContainsString('second site choices', (string) $browser->getResponse()->getContent());
        self::assertStringContainsString('"siteId":"'.$site.'"', (string) $browser->getResponse()->getContent());
        $browser->request('GET', '/dashboard/tag-manager/download', ['site' => $site]);
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString('second-provider.example', $content);
        self::assertStringNotContainsString('first-provider.example', $content);
        self::assertStringNotContainsString('private-site-setting', $content);
        self::assertTrue($browser->getResponse()->headers->hasCacheControlDirective('no-store'));
        $browser->request('GET', '/dashboard/setup/download/consent', ['website' => 'unregistered-token']);
        self::assertSame(400, $browser->getResponse()->getStatusCode());
    }

    private function browser(?string $role = null, bool $dashboard = true): KernelBrowser
    {
        $_ENV['DASHBOARD_ENABLED'] = $_SERVER['DASHBOARD_ENABLED'] = $dashboard ? '1' : '0';
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        $this->kernel = new SetupRoutesKernel($this->directory);
        $this->kernel->boot();
        $browser = new KernelBrowser($this->kernel);
        $browser->disableReboot();
        if ($role !== null) {
            $browser->loginUser(SetupRoutesUserProvider::user($role));
        }

        return $browser;
    }

    private function container(): \Psr\Container\ContainerInterface
    {
        return $this->kernel->getContainer()->get('test.service_container');
    }

    private function assertNoDatabaseConnection(): void
    {
        self::assertFalse($this->container()->get('doctrine.dbal.default_connection')->isConnected());
    }
}

final class SetupRoutesKernel extends Kernel implements CompilerPassInterface
{
    public function __construct(private readonly string $directory) { parent::__construct('test', true); }
    public function getCacheDir(): string { return $this->directory.'/cache'; }
    public function getLogDir(): string { return $this->directory.'/log'; }
    protected function getContainerClass(): string { return parent::getContainerClass().'_'.md5($this->directory); }
    public function process(ContainerBuilder $container): void
    {
        $container->setDefinition('security.user.provider.concrete.app_user_provider', new Definition(SetupRoutesUserProvider::class));
        foreach ([AggregateConfigLoader::class, WebsiteConfigManager::class, SiteScriptConfig::class] as $service) {
            $container->getDefinition($service)->setArgument('$projectDir', $this->directory);
        }
        $container->getDefinition(BrowserAssetBuilder::class)->setPublic(true);
    }
}

/** @implements UserProviderInterface<User> */
final class SetupRoutesUserProvider implements UserProviderInterface
{
    public static function user(string $role): User
    {
        $user = (new User())->setUsername($role)->setRoles([$role])->setPassword('test-only');
        (new \ReflectionProperty($user, 'id'))->setValue($user, 19);

        return $user;
    }
    public function loadUserByIdentifier(string $identifier): UserInterface { return self::user($identifier); }
    public function refreshUser(UserInterface $user): UserInterface { return self::user($user->getUserIdentifier()); }
    public function supportsClass(string $class): bool { return $class === User::class; }
}
