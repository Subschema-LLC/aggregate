<?php

declare(strict_types=1);

namespace App\Tests\Configuration;

use App\Controller\DashboardController;
use App\Entity\User;
use App\Kernel;
use App\Service\AggregateConfigLoader;
use App\Service\BrandingLogoManager;
use App\Service\SiteScriptConfig;
use App\Service\WebsiteConfigManager;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Yaml\Yaml;

final class WebsiteDomainRulesRoutesTest extends TestCase
{
    private const SAVE_PATH = '/dashboard/website/example-token/domains';

    private string $temporaryDirectory;
    private array $environment;
    private ?WebsiteDomainRulesRoutesTestKernel $kernel = null;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        foreach (['APP_HOST', 'JS_NAMESPACE'] as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
        }
        $this->temporaryDirectory = sys_get_temp_dir().'/aggregate-domain-routes-'.bin2hex(random_bytes(8));
        (new Filesystem())->mkdir($this->temporaryDirectory.'/config', 0700);
        file_put_contents($this->temporaryDirectory.'/config/aggregate.yaml', Yaml::dump([
            'installed' => true,
            'app_host' => 'https://analytics.example.test',
            'unrelated_operator_setting' => 'preserve-me',
        ]));
        $this->writeWebsites([
            'operator_metadata' => ['owner' => 'operations', 'revision' => 3],
            'websites' => [
                [
                    'name' => 'Example <site>',
                    'domain' => 'example.test',
                    'token' => 'example-token',
                    'operator_metadata' => ['team' => 'marketing'],
                ],
                [
                    'name' => 'Other website',
                    'domain' => 'other.test',
                    'token' => 'other-token',
                    'domain_policy' => ['mode' => 'restricted', 'domains' => ['other.test']],
                ],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        [$_ENV, $_SERVER] = $this->environment;
        (new Filesystem())->remove($this->temporaryDirectory);
    }

    public function testDomainMutationRequiresAuthenticationAndOnlyAcceptsPost(): void
    {
        $browser = $this->browser(authenticated: false);
        $before = $this->websitesContents();
        $route = $this->container()->get('router')->getRouteCollection()->get('app_website_domains');
        self::assertNotNull($route);
        self::assertSame('/dashboard/website/{token}/domains', $route->getPath());
        self::assertSame(['POST'], $route->getMethods());

        $browser->request('POST', self::SAVE_PATH, ['domain_mode' => 'all', '_csrf_token' => 'forged']);
        $this->assertRedirect($browser, '/login');
        $browser->loginUser(WebsiteDomainRulesTestUserProvider::user());
        $browser->request('GET', self::SAVE_PATH);
        self::assertSame(405, $browser->getResponse()->getStatusCode());
        self::assertSame($before, $this->websitesContents());
        $this->assertNoDatabaseConnection();
    }

    public function testOrdinaryUsersSeeEffectiveLegacyRulesAndAccessibleRestrictedCreationDefaults(): void
    {
        $browser = $this->browser();
        $before = $this->websitesContents();
        $crawler = $browser->request('GET', '/dashboard');
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        self::assertTrue($browser->getResponse()->headers->hasCacheControlDirective('private'));
        self::assertTrue($browser->getResponse()->headers->hasCacheControlDirective('no-store'));

        $domainForm = $crawler->filter('form[action="'.self::SAVE_PATH.'"]');
        $form = $domainForm->selectButton('Save domain rules')->form();
        self::assertSame('restricted', $form['domain_mode']->getValue());
        self::assertSame("example.test\n*.example.test", trim($form['allowed_domains']->getValue()));
        $this->assertFieldsHaveLabels($domainForm);

        $createForm = $crawler->filter('form[action="/dashboard/website/create"]');
        $form = $createForm->selectButton('Create Website')->form();
        self::assertSame('restricted', $form['domain_mode']->getValue());
        self::assertSame('', trim($form['allowed_domains']->getValue()));
        $this->assertFieldsHaveLabels($createForm);
        self::assertStringContainsString('subdomain', strtolower($crawler->text()));
        self::assertSame($before, $this->websitesContents(), 'Reading legacy rules must not migrate operator YAML.');
        $this->assertNoDatabaseConnection();
    }

    public function testOrdinaryUsersCanSaveRestrictedRulesWhilePreservingOtherConfiguration(): void
    {
        $browser = $this->browser();
        $expected = $this->readWebsites();
        $aggregateBefore = file_get_contents($this->temporaryDirectory.'/config/aggregate.yaml');
        $form = $this->domainForm($browser);
        $form['domain_mode'] = 'restricted';
        $form['allowed_domains'] = " Shop.Example.test \r\n*.campaign.example.test\nshop.example.test";
        $browser->submit($form);
        $this->assertRedirect($browser);

        $expected['websites'][0]['domain_policy'] = [
            'mode' => 'restricted',
            'domains' => ['shop.example.test', '*.campaign.example.test'],
        ];
        self::assertSame($expected, $this->readWebsites());
        self::assertSame($aggregateBefore, file_get_contents($this->temporaryDirectory.'/config/aggregate.yaml'));
        self::assertSame("shop.example.test\n*.campaign.example.test", trim($this->domainForm($browser)['allowed_domains']->getValue()));
        $this->assertNoDatabaseConnection();
    }

    public function testBrokenScriptSettingsOnlyHideTheAffectedWebsitesInstallationCode(): void
    {
        $directory = $this->temporaryDirectory.'/config/tag-manager/sites';
        (new Filesystem())->mkdir($directory);
        $siteFile = $directory.'/'.SiteScriptConfig::idForToken('example-token').'.yaml';
        file_put_contents($siteFile, "consent_manager: [broken\n");
        $browser = $this->browser();
        $before = $this->websitesContents();
        foreach ([[], ['format' => 'query'], ['tags' => '1']] as $query) {
            $crawler = $browser->request('GET', '/dashboard', $query);
            self::assertSame(200, $browser->getResponse()->getStatusCode());
            self::assertCount(0, $crawler->filter('#code-1'));
            self::assertCount(1, $crawler->filter('#code-2'));
            self::assertStringContainsString('Installation code is unavailable for this website.', $crawler->text());
            self::assertCount(1, $crawler->filter('form[action="'.self::SAVE_PATH.'"]'));
            self::assertCount(1, $crawler->filter('form[action="/dashboard/website/other-token/domains"]'));
            self::assertSame($before, $this->websitesContents());
            self::assertSame("consent_manager: [broken\n", file_get_contents($siteFile));
        }
        $this->assertNoDatabaseConnection();
    }

    public function testInstallationCodeRespectsEachWebsitesConsentSetting(): void
    {
        $directory = $this->temporaryDirectory.'/config/tag-manager/sites';
        (new Filesystem())->mkdir($directory);
        file_put_contents($directory.'/'.SiteScriptConfig::idForToken('example-token').'.yaml', "consent_manager:\n  enabled: false\n");
        $browser = $this->browser();
        foreach ([[], ['format' => 'query'], ['tags' => '1']] as $query) {
            $crawler = $browser->request('GET', '/dashboard', $query);
            self::assertSame(200, $browser->getResponse()->getStatusCode());
            self::assertStringNotContainsString('/cmp-lite/', $crawler->filter('#code-1')->text());
            self::assertStringContainsString('/cmp-lite/sites/'.SiteScriptConfig::idForToken('other-token').'/consent.js', $crawler->filter('#code-2')->text());
        }
        $this->assertNoDatabaseConnection();
    }

    public function testAllowAllCanRetainRulesOrUseAnEmptyList(): void
    {
        $browser = $this->browser();
        foreach (["example.test\n*.example.test" => ['example.test', '*.example.test'], '' => []] as $text => $domains) {
            $form = $this->domainForm($browser);
            $form['domain_mode'] = 'all';
            $form['allowed_domains'] = $text;
            $browser->submit($form);
            $this->assertRedirect($browser);
            self::assertSame(['mode' => 'all', 'domains' => $domains], $this->readWebsites()['websites'][0]['domain_policy']);
            self::assertSame('all', $this->domainForm($browser)['domain_mode']->getValue());
        }
        $this->assertNoDatabaseConnection();
    }

    public function testMissingForgedAndAnotherWebsitesCsrfTokensCannotChangeRules(): void
    {
        $browser = $this->browser();
        $before = $this->websitesContents();
        $validToken = $this->domainForm($browser)['_csrf_token']->getValue();
        foreach ([
            [self::SAVE_PATH, []],
            [self::SAVE_PATH, ['_csrf_token' => 'forged']],
            ['/dashboard/website/other-token/domains', ['_csrf_token' => $validToken]],
        ] as [$path, $parameters]) {
            $browser->request('POST', $path, $parameters + ['domain_mode' => 'all', 'allowed_domains' => ''], [], ['HTTP_ORIGIN' => 'http://localhost']);
            $this->assertRedirect($browser);
            self::assertContains('Invalid security token. Please try again.', $browser->getRequest()->getSession()->getFlashBag()->peek('error'));
            self::assertSame($before, $this->websitesContents());
        }
        $this->assertNoDatabaseConnection();
    }

    public function testInvalidAndMalformedRuleSubmissionsDoNotWriteYaml(): void
    {
        $browser = $this->browser();
        $before = $this->websitesContents();
        foreach ([
            ['domain_mode' => 'unexpected', 'allowed_domains' => 'example.test'],
            ['domain_mode' => ['all'], 'allowed_domains' => 'example.test'],
            ['domain_mode' => 'restricted', 'allowed_domains' => ['example.test']],
            ['domain_mode' => 'restricted', 'allowed_domains' => ''],
            ['domain_mode' => 'restricted', 'allowed_domains' => 'https://example.test/path'],
            ['domain_mode' => 'restricted', 'allowed_domains' => 'shop.*.example.test'],
            ['domain_mode' => 'restricted', 'allowed_domains' => '/.*\\.example\\.test$/'],
            ['domain_mode' => 'all', 'allowed_domains' => '*'],
        ] as $parameters) {
            $values = $this->domainForm($browser)->getPhpValues();
            $browser->request('POST', self::SAVE_PATH, array_replace($values, $parameters));
            $this->assertRedirect($browser);
            self::assertNotEmpty($browser->getRequest()->getSession()->getFlashBag()->peek('error'));
            self::assertSame($before, $this->websitesContents(), json_encode($parameters, JSON_THROW_ON_ERROR));
        }
        $this->assertNoDatabaseConnection();
    }

    public function testCreateFormDefaultsToTheExactPrimaryHostname(): void
    {
        $browser = $this->browser();
        $before = $this->readWebsites();
        $form = $browser->request('GET', '/dashboard')->selectButton('Create Website')->form([
            'name' => 'New website',
            'domain' => 'new.example.test',
        ]);
        $browser->submit($form);
        $this->assertRedirect($browser);

        $saved = $this->readWebsites();
        $website = array_pop($saved['websites']);
        self::assertSame($before, $saved);
        self::assertSame('New website', $website['name']);
        self::assertSame('new.example.test', $website['domain']);
        self::assertNotEmpty($website['token']);
        self::assertSame(['mode' => 'restricted', 'domains' => ['new.example.test']], $website['domain_policy']);
        $this->assertNoDatabaseConnection();
    }

    public function testNewWebsitesCanChooseExplicitRestrictionsOrAllowAll(): void
    {
        $browser = $this->browser();
        foreach ([
            ['restricted', "shop.example.test\n*.campaign.example.test", ['shop.example.test', '*.campaign.example.test']],
            ['all', '', []],
        ] as [$mode, $text, $domains]) {
            $form = $browser->request('GET', '/dashboard')->selectButton('Create Website')->form([
                'name' => 'New '.$mode.' website',
                'domain' => 'new-'.$mode.'.example.test',
                'domain_mode' => $mode,
                'allowed_domains' => $text,
            ]);
            $browser->submit($form);
            $this->assertRedirect($browser);
            $websites = $this->readWebsites()['websites'];
            self::assertSame(['mode' => $mode, 'domains' => $domains], end($websites)['domain_policy']);
        }
        $this->assertNoDatabaseConnection();
    }

    public function testLegacyCreateSubmissionsStillUseLegacyDomainSemantics(): void
    {
        $browser = $this->browser();
        $form = $browser->request('GET', '/dashboard')->selectButton('Create Website')->form();
        $browser->request('POST', '/dashboard/website/create', [
            '_csrf_token' => $form['_csrf_token']->getValue(),
            'name' => 'Legacy client',
            'domain' => 'legacy.example.test',
        ]);
        $this->assertRedirect($browser);
        $websites = $this->readWebsites()['websites'];
        $website = end($websites);
        self::assertSame('legacy.example.test', $website['domain']);
        self::assertArrayNotHasKey('domain_policy', $website);
        $this->assertNoDatabaseConnection();
    }

    public function testInvalidDomainRulesCannotCreateAWebsite(): void
    {
        $browser = $this->browser();
        $before = $this->websitesContents();
        foreach ([
            ['domain_mode' => 'restricted', 'allowed_domains' => 'prefix*.example.test'],
            ['domain_mode' => ['all'], 'allowed_domains' => ''],
            ['domain_mode' => 'restricted', 'allowed_domains' => ['example.test']],
        ] as $parameters) {
            $values = $browser->request('GET', '/dashboard')->selectButton('Create Website')->form([
                'name' => 'Invalid website',
                'domain' => 'new.example.test',
            ])->getPhpValues();
            $browser->request('POST', '/dashboard/website/create', array_replace($values, $parameters));
            $this->assertRedirect($browser);
            self::assertNotEmpty($browser->getRequest()->getSession()->getFlashBag()->peek('error'));
            self::assertSame($before, $this->websitesContents());
        }
        $this->assertNoDatabaseConnection();
    }

    public function testInvalidSavedRulesRemainVisibleWithAWarningAndCanBeCorrected(): void
    {
        $config = $this->readWebsites();
        $config['websites'][0]['domain_policy'] = ['mode' => 'restricted', 'domains' => 'example.test'];
        $this->writeWebsites($config);
        $before = $this->websitesContents();
        $browser = $this->browser();
        $crawler = $browser->request('GET', '/dashboard');
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        self::assertGreaterThan(0, $crawler->filter('.notification.is-warning')->count());
        self::assertStringContainsString('invalid', strtolower($crawler->filter('.notification.is-warning')->text()));
        self::assertSame($before, $this->websitesContents());

        $form = $crawler->filter('form[action="'.self::SAVE_PATH.'"]')->selectButton('Save domain rules')->form([
            'domain_mode' => 'restricted',
            'allowed_domains' => 'example.test',
        ]);
        $browser->submit($form);
        $this->assertRedirect($browser);
        self::assertSame(['mode' => 'restricted', 'domains' => ['example.test']], $this->readWebsites()['websites'][0]['domain_policy']);
        $this->assertNoDatabaseConnection();
    }

    public function testHeadlessBootOmitsDomainFormsAndMutation(): void
    {
        $browser = $this->browser(authenticated: false, dashboardEnabled: false);
        $before = $this->websitesContents();
        self::assertFalse($this->container()->has(DashboardController::class));
        self::assertNull($this->container()->get('router')->getRouteCollection()->get('app_website_domains'));
        foreach ([['GET', '/dashboard'], ['GET', self::SAVE_PATH], ['POST', self::SAVE_PATH]] as [$method, $path]) {
            $browser->request($method, $path, ['domain_mode' => 'all', '_csrf_token' => 'forged']);
            self::assertSame(404, $browser->getResponse()->getStatusCode(), $method.' '.$path);
        }
        self::assertSame($before, $this->websitesContents());
        $this->assertNoDatabaseConnection();
    }

    private function browser(bool $authenticated = true, bool $dashboardEnabled = true): KernelBrowser
    {
        $_ENV['DASHBOARD_ENABLED'] = $_SERVER['DASHBOARD_ENABLED'] = $dashboardEnabled ? '1' : '0';
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        $_ENV['TRUSTED_PROXIES'] = $_SERVER['TRUSTED_PROXIES'] = '';
        $this->kernel = new WebsiteDomainRulesRoutesTestKernel($this->temporaryDirectory);
        $this->kernel->boot();
        $browser = new KernelBrowser($this->kernel);
        $browser->disableReboot();
        if ($authenticated) {
            $browser->loginUser(WebsiteDomainRulesTestUserProvider::user());
        }

        return $browser;
    }

    private function container(): \Psr\Container\ContainerInterface
    {
        return $this->kernel->getContainer()->get('test.service_container');
    }

    private function domainForm(KernelBrowser $browser): Form
    {
        $crawler = $browser->request('GET', '/dashboard');
        self::assertSame(200, $browser->getResponse()->getStatusCode());

        return $crawler->filter('form[action="'.self::SAVE_PATH.'"]')->selectButton('Save domain rules')->form();
    }

    private function assertFieldsHaveLabels(Crawler $form): void
    {
        foreach (['domain_mode', 'allowed_domains'] as $name) {
            $field = $form->filter('[name="'.$name.'"]');
            self::assertCount(1, $field);
            $id = $field->attr('id');
            self::assertNotEmpty($id);
            self::assertCount(1, $form->filter('label[for="'.$id.'"]'));
        }
    }

    private function assertRedirect(KernelBrowser $browser, string $path = '/dashboard'): void
    {
        self::assertSame(302, $browser->getResponse()->getStatusCode());
        self::assertSame($path, parse_url((string) $browser->getResponse()->headers->get('Location'), PHP_URL_PATH));
    }

    private function assertNoDatabaseConnection(): void
    {
        self::assertFalse($this->container()->get('doctrine.dbal.default_connection')->isConnected());
    }

    private function websitesContents(): string
    {
        return file_get_contents($this->temporaryDirectory.'/config/websites.yaml');
    }

    private function readWebsites(): array
    {
        return Yaml::parse($this->websitesContents());
    }

    private function writeWebsites(array $config): void
    {
        file_put_contents($this->temporaryDirectory.'/config/websites.yaml', Yaml::dump($config, 8, 2));
    }
}

/** Exercise the real firewall, controller, and templates with isolated YAML. */
final class WebsiteDomainRulesRoutesTestKernel extends Kernel implements CompilerPassInterface
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
        $container->setDefinition('security.user.provider.concrete.app_user_provider', new Definition(WebsiteDomainRulesTestUserProvider::class));
        foreach ([AggregateConfigLoader::class, WebsiteConfigManager::class, BrandingLogoManager::class, SiteScriptConfig::class] as $service) {
            $container->getDefinition($service)->setArgument('$projectDir', $this->temporaryDirectory);
        }
    }
}

/** @implements UserProviderInterface<User> */
final class WebsiteDomainRulesTestUserProvider implements UserProviderInterface
{
    public static function user(): User
    {
        $user = (new User())->setUsername('website-domain-user')->setRoles(['ROLE_USER'])->setPassword('test-only');
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
