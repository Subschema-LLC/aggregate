<?php

declare(strict_types=1);

namespace App\Tests\Configuration;

use App\Entity\User;
use App\Kernel;
use App\Repository\UserRepository;
use App\Security\Access\PermissionGroupCatalog;
use App\Service\AggregateConfigLoader;
use App\Service\BrandingLogoManager;
use App\Service\Operations\AdminAudit;
use App\Service\SiteScriptConfig;
use App\Service\WebsiteConfigManager;
use App\Service\WebsiteScopeCatalog;
use App\Tests\Support\RecordingAdminAudit;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Standard users against the real firewall, routes, controllers and
 * templates: what the menu shows, what each page lets them see and change,
 * and what is refused.
 */
final class DashboardPermissionsRoutesTest extends TestCase
{
    private string $temporaryDirectory;
    private array $environment;
    private ?DashboardPermissionsRoutesTestKernel $kernel = null;
    private string $siteA;
    private string $siteB;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        RecordingAdminAudit::$entries = [];
        foreach (['APP_HOST', 'JS_NAMESPACE', 'DOCUMENTATION_URL'] as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
        }
        $this->temporaryDirectory = sys_get_temp_dir().'/aggregate-permissions-routes-'.bin2hex(random_bytes(8));
        (new Filesystem())->mkdir($this->temporaryDirectory.'/config', 0700);
        file_put_contents($this->temporaryDirectory.'/config/aggregate.yaml', Yaml::dump(['installed' => true, 'app_host' => 'https://analytics.example.test']));
        file_put_contents($this->temporaryDirectory.'/config/websites.yaml', Yaml::dump(['websites' => [
            ['name' => 'Shop A', 'domain' => 'a.example.test', 'token' => 'token-a'],
            ['name' => 'Shop B', 'domain' => 'b.example.test', 'token' => 'token-b'],
        ]]));
        file_put_contents($this->temporaryDirectory.'/config/website-scopes.yaml', Yaml::dump(['groups' => ['brand-a' => ['name' => 'Brand A', 'members' => ['token-a']]]], 4));
        file_put_contents($this->temporaryDirectory.'/config/permission-groups.yaml', Yaml::dump(['permission_groups' => ['editors' => [
            'name' => 'Editors',
            'websites' => ['groups' => ['brand-a']],
            'pages' => ['websites' => 'view', 'tag_manager' => 'edit', 'consent_manager' => 'view', 'collection_controls' => 'view'],
        ]]], 5));
        $this->siteA = SiteScriptConfig::idForToken('token-a');
        $this->siteB = SiteScriptConfig::idForToken('token-b');
        DashboardPermissionsTestUserProvider::$admin = false;
        DashboardPermissionsTestUserProvider::$access = [];
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        [$_ENV, $_SERVER] = $this->environment;
        (new Filesystem())->remove($this->temporaryDirectory);
    }

    public function testAStandardUserWithoutPagesLandsOnTheirProfile(): void
    {
        $browser = $this->browser();
        $browser->request('GET', '/dashboard');
        self::assertSame(303, $browser->getResponse()->getStatusCode());
        self::assertSame('/dashboard/profile', $browser->getResponse()->headers->get('Location'));

        $crawler = $browser->request('GET', '/dashboard/profile');
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        self::assertStringContainsString('No pages are granted to you yet', $crawler->filter('#your-access')->text());
        self::assertStringContainsString('No websites are assigned to you yet', $crawler->filter('#your-access')->text());
        self::assertSame('/dashboard/profile', $crawler->filter('a.app-navigation-user')->attr('href'));
        self::assertSame([], $this->menuLinks($crawler), 'no dashboard page in the menu');

        foreach (['/dashboard/settings', '/dashboard/users', '/dashboard/users/permission-groups', '/dashboard/tag-manager', '/dashboard/audit-trail'] as $path) {
            $browser->request('GET', $path);
            self::assertSame(403, $browser->getResponse()->getStatusCode(), $path);
        }
    }

    public function testAPermissionGroupGrantsItsPagesForItsWebsitesOnly(): void
    {
        DashboardPermissionsTestUserProvider::$access = ['permission_groups' => ['editors']];
        $browser = $this->browser();

        $crawler = $browser->request('GET', '/dashboard/tag-manager');
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        self::assertSame(['Websites', 'Tag manager lite', 'Consent manager lite', 'Collection controls'], $this->menuLinks($crawler));
        self::assertSame([$this->siteA], $crawler->filter('select[name="site"] option')->each(static fn (Crawler $option): string => (string) $option->attr('value')), 'only Shop A, and no shared configuration');
        self::assertCount(0, $crawler->filter('.view-only-notice'), 'tag manager is editable');

        $browser->request('GET', '/dashboard/tag-manager', ['site' => $this->siteB]);
        self::assertSame(404, $browser->getResponse()->getStatusCode(), 'another website');
        $browser->request('GET', '/dashboard/tag-manager', ['site' => '']);
        self::assertSame(403, $browser->getResponse()->getStatusCode(), 'the shared configuration needs every website');
        $browser->request('POST', '/dashboard/tag-manager/save', ['site' => $this->siteB, '_csrf_token' => 'forged']);
        self::assertSame(404, $browser->getResponse()->getStatusCode());
        self::assertDirectoryDoesNotExist($this->temporaryDirectory.'/config/tag-manager');

        // Consent manager is view-only: the notice shows and a save is sent back.
        $crawler = $browser->request('GET', '/dashboard/consent-manager');
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        self::assertStringContainsString('view-only access to Consent manager lite', $crawler->filter('.view-only-notice')->text());
        self::assertCount(1, $crawler->filter('[data-controller="components--layout--view-only"] form[action="/dashboard/consent-manager/save"]'));
        $browser->request('POST', '/dashboard/consent-manager/save', ['site' => $this->siteA, '_csrf_token' => 'forged']);
        self::assertSame(303, $browser->getResponse()->getStatusCode());
        self::assertSame('/dashboard/consent-manager', parse_url((string) $browser->getResponse()->headers->get('Location'), PHP_URL_PATH));
        self::assertContains('You have view-only access to Consent manager lite. Ask an administrator for edit access.', $browser->getRequest()->getSession()->getFlashBag()->peek('error'));
        // The save for another website answered not found; the view-only save was refused.
        self::assertSame([['tag_manager_saved', 'failed', 'standard-user'], ['consent_manager_saved', 'denied', 'standard-user']], array_map(
            static fn (array $entry): array => [$entry['action'], $entry['outcome'], $entry['actor']],
            RecordingAdminAudit::$entries,
        ));

        // Collection controls opens on the group, offers only the group and its website, and refuses the rest.
        $crawler = $browser->request('GET', '/dashboard/collection');
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        $choices = $crawler->filter('select[name="scope"] option')->each(static fn (Crawler $option): string => (string) $option->attr('value'));
        self::assertSame(['group:brand-a', 'website:'.$this->siteA], $choices);
        foreach (['deployment', 'website:'.$this->siteB, 'group:brand-b'] as $scope) {
            $browser->request('GET', '/dashboard/collection', ['scope' => $scope]);
            self::assertSame(404, $browser->getResponse()->getStatusCode(), $scope);
        }
        $browser->request('GET', '/dashboard/collection/tracking-status');
        self::assertSame(403, $browser->getResponse()->getStatusCode(), 'tracking failures are shared by every website');

        $browser->request('GET', '/dashboard/settings');
        self::assertSame(403, $browser->getResponse()->getStatusCode());
    }

    public function testTheProfileListsEachGrantAndWhereItComesFrom(): void
    {
        DashboardPermissionsTestUserProvider::$access = ['permission_groups' => ['editors'], 'websites' => ['sites' => ['token-b']], 'pages' => ['consent_manager' => 'edit']];
        $browser = $this->browser();
        $crawler = $browser->request('GET', '/dashboard/profile');
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        $access = $crawler->filter('#your-access');
        self::assertStringContainsString('Brand A (1 website), all its websites', $access->text());
        self::assertStringContainsString('Shop B (b.example.test)', $access->text());
        $rows = [];
        foreach ($access->filter('table.access-summary tbody tr') as $row) {
            $row = new Crawler($row);
            $rows[trim($row->filter('th')->text())] = [trim($row->filter('td')->eq(0)->text()), trim($row->filter('td')->eq(1)->text())];
        }
        self::assertSame([
            'Websites Website' => ['View only', 'Permission group: Editors'],
            'Tag manager lite Website' => ['View and edit', 'Permission group: Editors'],
            'Consent manager lite Website' => ['View and edit', 'Assigned directly (edit) Permission group: Editors (view)'],
            'Collection controls Website' => ['View only', 'Permission group: Editors'],
        ], $rows);
        self::assertCount(0, $crawler->filter('input[name="username"]'), 'only administrators change usernames');
    }

    public function testStandardUsersCannotManageUsersOrPermissionGroups(): void
    {
        DashboardPermissionsTestUserProvider::$access = ['websites' => ['all' => true], 'pages' => ['general_settings' => 'edit', 'audit_trail' => 'edit']];
        $browser = $this->browser();
        foreach ([['GET', '/dashboard/users'], ['POST', '/dashboard/users/create'], ['POST', '/dashboard/users/7/update'], ['POST', '/dashboard/users/7/delete'], ['POST', '/dashboard/users/permission-groups/create']] as [$method, $path]) {
            $browser->request($method, $path, ['_csrf_token' => 'forged', 'name' => 'Sneaky']);
            self::assertSame(403, $browser->getResponse()->getStatusCode(), $path);
        }
        self::assertSame(['editors'], array_keys(Yaml::parseFile($this->temporaryDirectory.'/config/permission-groups.yaml')['permission_groups']));
    }

    public function testAdministratorsCreatePermissionGroupsFromTheUsersPage(): void
    {
        DashboardPermissionsTestUserProvider::$admin = true;
        $browser = $this->browser();
        $repository = $this->createStub(UserRepository::class);
        $repository->method('findAll')->willReturn([]);
        $this->container()->set(UserRepository::class, $repository);
        $crawler = $browser->request('GET', '/dashboard/users/permission-groups');
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        self::assertSame(['Users', 'Permission groups'], $crawler->filter('nav[aria-label="Users pages"] a')->each(static fn (Crawler $link): string => trim($link->text())));
        self::assertCount(1, $crawler->filter('#group-editors'));
        $form = $crawler->selectButton('Create permission group')->form([
            'name' => 'Analysts',
            'description' => 'Read models',
            'website_mode' => 'all',
        ]);
        $form['pages[data_model]']->select('view');
        $form['pages[bi_glossary]']->select('edit');
        $browser->submit($form);
        self::assertSame(302, $browser->getResponse()->getStatusCode());
        self::assertSame(['name' => 'Analysts', 'description' => 'Read models', 'websites' => ['all' => true], 'pages' => ['data_model' => 'view', 'bi_glossary' => 'edit']],
            Yaml::parseFile($this->temporaryDirectory.'/config/permission-groups.yaml')['permission_groups']['analysts']);
        $entry = array_pop(RecordingAdminAudit::$entries);
        self::assertSame(['users', 'permission_group_created', 'succeeded', 'Analysts'], [$entry['area'], $entry['action'], $entry['outcome'], $entry['subject']]);
    }

    /** @return list<string> dashboard page links in the main menu, without Help */
    private function menuLinks(Crawler $crawler): array
    {
        return array_values(array_filter(
            $crawler->filter('#app-navigation-items a.navbar-item')->each(static fn (Crawler $link): string => trim($link->text())),
            static fn (string $label): bool => !in_array($label, ['How it works', 'BI connection guide', 'Documentation (opens in a new tab)'], true),
        ));
    }

    private function browser(): KernelBrowser
    {
        $_ENV['DASHBOARD_ENABLED'] = $_SERVER['DASHBOARD_ENABLED'] = '1';
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        $_ENV['TRUSTED_PROXIES'] = $_SERVER['TRUSTED_PROXIES'] = '';
        $this->kernel = new DashboardPermissionsRoutesTestKernel($this->temporaryDirectory);
        $this->kernel->boot();
        $browser = new KernelBrowser($this->kernel);
        $browser->disableReboot();
        $browser->loginUser(DashboardPermissionsTestUserProvider::user());

        return $browser;
    }

    private function container(): \Psr\Container\ContainerInterface
    {
        return $this->kernel->getContainer()->get('test.service_container');
    }
}

final class DashboardPermissionsRoutesTestKernel extends Kernel implements CompilerPassInterface
{
    public function __construct(private readonly string $temporaryDirectory)
    {
        parent::__construct('test', true);
    }

    public function getCacheDir(): string
    {
        return $this->temporaryDirectory.'/cache';
    }

    public function getLogDir(): string
    {
        return $this->temporaryDirectory.'/log';
    }

    protected function getContainerClass(): string
    {
        return parent::getContainerClass().'_'.md5($this->temporaryDirectory);
    }

    public function process(ContainerBuilder $container): void
    {
        $container->setDefinition('security.user.provider.concrete.app_user_provider', new Definition(DashboardPermissionsTestUserProvider::class));
        $container->setDefinition(AdminAudit::class, new Definition(RecordingAdminAudit::class));
        foreach ([AggregateConfigLoader::class, WebsiteConfigManager::class, BrandingLogoManager::class, SiteScriptConfig::class, WebsiteScopeCatalog::class, PermissionGroupCatalog::class] as $service) {
            $container->getDefinition($service)->setArgument('$projectDir', $this->temporaryDirectory);
        }
    }
}

/**
 * One signed-in account whose type and grants each test sets; reread on every request.
 *
 * @implements UserProviderInterface<User>
 */
final class DashboardPermissionsTestUserProvider implements UserProviderInterface
{
    public static bool $admin = false;
    /** @var array<string, mixed> */
    public static array $access = [];

    public static function user(): User
    {
        $user = (new User())->setUsername('standard-user')->setRoles([])->setAdmin(self::$admin)->setPassword('test-only')->setAccess(self::$access);
        (new \ReflectionProperty($user, 'id'))->setValue($user, 7);

        return $user;
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        return self::user();
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException();
        }

        return self::user();
    }

    public function supportsClass(string $class): bool
    {
        return $class === User::class;
    }
}
