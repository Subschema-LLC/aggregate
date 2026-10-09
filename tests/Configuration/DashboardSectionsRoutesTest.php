<?php

declare(strict_types=1);

namespace App\Tests\Configuration;

use App\Controller\DashboardController;
use App\Entity\User;
use App\Kernel;
use App\Repository\UserRepository;
use App\Service\AggregateConfigLoader;
use App\Service\AnalyticsPrivacySettings;
use App\Service\BrandingLogoManager;
use App\Service\DocumentationLinks;
use App\Service\SiteScriptConfig;
use App\Service\WebsiteConfigManager;
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

final class DashboardSectionsRoutesTest extends TestCase
{
    private const PAGES = [
        'app_dashboard' => ['/dashboard', 'Websites'],
        'app_application_settings' => ['/dashboard/settings', 'General settings'],
        'app_branding_settings' => ['/dashboard/branding', 'Branding'],
        'app_collection_settings' => ['/dashboard/collection', 'Collection controls'],
        'app_privacy_settings' => ['/dashboard/privacy', 'BI disclosure'],
        'app_users' => ['/dashboard/users', 'Users'],
    ];

    private const MUTATIONS = [
        '/dashboard/website/create' => '/dashboard',
        '/dashboard/website/delete/example-token' => '/dashboard',
        '/dashboard/settings/save' => '/dashboard/settings',
        '/dashboard/settings/page-speed' => '/dashboard/settings',
        '/dashboard/settings/branding' => '/dashboard/branding',
        '/dashboard/settings/anonymous' => '/dashboard/collection',
        '/dashboard/settings/tracking/retry' => '/dashboard/collection',
        '/dashboard/settings/analytics-privacy' => '/dashboard/privacy',
        '/dashboard/users/create' => '/dashboard/users',
        '/dashboard/users/7/password' => '/dashboard/users',
    ];

    private string $temporaryDirectory;
    private array $environment;
    private ?DashboardSectionsRoutesTestKernel $kernel = null;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        foreach (['APP_HOST', 'JS_NAMESPACE', 'DOCUMENTATION_URL', 'TRACKER_OMIT_UNUSED_FEATURES'] as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
        }
        $this->temporaryDirectory = sys_get_temp_dir().'/aggregate-dashboard-sections-'.bin2hex(random_bytes(8));
        (new Filesystem())->mkdir($this->temporaryDirectory.'/config', 0700);
        file_put_contents($this->temporaryDirectory.'/config/aggregate.yaml', Yaml::dump([
            'installed' => true,
            'app_host' => 'https://analytics.example.test',
            'js_namespace' => 'ExampleAnalytics',
            'rate_limit_per_minute' => 100,
            'anonymous_tracking_enabled' => true,
            'anonymous_excluded_paths' => ['/account/**'],
            'unrelated_operator_setting' => 'preserve-me',
        ]));
        file_put_contents($this->temporaryDirectory.'/config/websites.yaml', Yaml::dump([
            'websites' => [['name' => 'Example <site>', 'domain' => 'example.test', 'token' => 'example-token']],
        ]));
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        [$_ENV, $_SERVER] = $this->environment;
        (new Filesystem())->remove($this->temporaryDirectory);
    }

    public function testDashboardSectionsAreGetOnlyAndUnavailableWithoutAuthentication(): void
    {
        $browser = $this->browser();
        $routes = $this->container()->get('router')->getRouteCollection();
        foreach (self::PAGES as $name => [$path]) {
            $route = $routes->get($name);
            self::assertNotNull($route);
            self::assertSame($path, $route->getPath());
            self::assertSame(['GET'], $route->getMethods());
            $browser->request('GET', $path);
            $this->assertRedirect($browser, '/login');
        }
        foreach (self::MUTATIONS as $path => $target) {
            $browser->request('POST', $path, ['_csrf_token' => 'forged']);
            $this->assertRedirect($browser, '/login');
        }
        $this->assertNoDatabaseConnection();
    }

    public function testOrdinaryUsersCannotReadOrWriteAdministratorSettings(): void
    {
        $browser = $this->browser('ROLE_USER');
        $this->preventDatabaseReadsAndWrites();
        $configBefore = file_get_contents($this->temporaryDirectory.'/config/aggregate.yaml');
        foreach (self::PAGES as $name => [$path]) {
            if ($name === 'app_dashboard') {
                continue;
            }
            $browser->request('GET', $path);
            self::assertSame(403, $browser->getResponse()->getStatusCode(), $path);
        }
        foreach (self::MUTATIONS as $path => $target) {
            if ($target === '/dashboard') {
                continue;
            }
            $browser->request('POST', $path, ['_csrf_token' => 'forged']);
            self::assertSame(403, $browser->getResponse()->getStatusCode(), $path);
        }
        self::assertSame($configBefore, file_get_contents($this->temporaryDirectory.'/config/aggregate.yaml'));
        $this->assertNoDatabaseConnection();
    }

    public function testWebsitesAndYamlSettingsRenderWithoutReadingUsersOrBiSettings(): void
    {
        $browser = $this->browser('ROLE_ADMIN');
        $this->preventDatabaseReadsAndWrites();
        foreach (array_slice(self::PAGES, 0, 4, true) as [$path, $title]) {
            $crawler = $browser->request('GET', $path);
            self::assertSame(200, $browser->getResponse()->getStatusCode(), $path);
            self::assertSame($title, $crawler->filter('.container h1')->text());
            $this->assertPrivate($browser);
            self::assertCount(0, $crawler->filter('form[action="/dashboard/users/create"], form[action="/dashboard/settings/analytics-privacy"]'));
        }
        $crawler = $browser->request('GET', '/dashboard');
        self::assertCount(0, $crawler->filter('form[action^="/dashboard/settings/"]'));
        self::assertCount(1, $crawler->filter('dialog[aria-labelledby="add-website-title"] form[action="/dashboard/website/create"]'));
        self::assertStringContainsString('Example <site>', $crawler->filter('.container details summary')->first()->text());
        self::assertStringContainsString('window["ExampleAnalytics"]', $crawler->filter('#code-1')->text());
        self::assertStringContainsString('"websiteToken":"example-token"', $crawler->filter('#code-1')->text());
        self::assertStringContainsString('"consent":false', $crawler->filter('#code-1')->text());
        self::assertCount(0, $crawler->filter('script[src*="/aggregate.js"], script[src*="/cmp-lite/"]'));
        $this->assertNoDatabaseConnection();
    }

    public function testCollapsibleHelpPanelsShowAToggleAndBrandingYamlStartsOpen(): void
    {
        $browser = $this->browser('ROLE_ADMIN');
        $this->preventDatabaseReadsAndWrites();

        $branding = $browser->request('GET', '/dashboard/branding');
        $yaml = $branding->filter('details.message.is-info');
        self::assertCount(1, $yaml);
        self::assertNotNull($yaml->attr('open'), 'The YAML panel starts expanded.');
        self::assertSame('YAML configuration', trim($yaml->filter('summary.message-header > span')->first()->text()));
        self::assertCount(1, $yaml->filter('summary.message-header > .message-toggle[aria-hidden="true"] > svg'));

        $collection = $browser->request('GET', '/dashboard/collection');
        self::assertCount(1, $collection->filter('details.message.is-warning:not([open]) > summary.message-header > .message-toggle[aria-hidden="true"] > svg'));
        $this->assertNoDatabaseConnection();
    }

    public function testInstallationChoicesRenderOnGetAndSetupKeepsTheSelectedMethod(): void
    {
        $browser = $this->browser('ROLE_ADMIN');
        $this->preventDatabaseReadsAndWrites();
        $websitesBefore = file_get_contents($this->temporaryDirectory.'/config/websites.yaml');
        $configBefore = file_get_contents($this->temporaryDirectory.'/config/aggregate.yaml');
        $site = SiteScriptConfig::idForToken('example-token');
        $crawler = $browser->request('GET', '/dashboard');
        self::assertSame('Window configuration', $crawler->filter('nav[aria-labelledby="website-installation-method"] [aria-current="true"]')->text());
        self::assertCount(3, $crawler->filter('nav[aria-labelledby="website-installation-method"] a'));
        $crawler = $browser->click($crawler->selectLink('URL query parameters')->link());
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        self::assertSame('URL query parameters', $crawler->filter('nav[aria-labelledby="website-installation-method"] [aria-current="true"]')->text());
        $snippet = $crawler->filter('#code-1')->text();
        self::assertStringNotContainsString('window[', $snippet);
        $scripts = new Crawler($snippet);
        self::assertCount(2, $scripts->filter('script'));
        self::assertStringContainsString('/cmp-lite/sites/'.$site.'/consent.js', $scripts->filter('script')->eq(0)->attr('src'));
        parse_str((string) parse_url($scripts->filter('script')->eq(1)->attr('src'), PHP_URL_QUERY), $tracker);
        self::assertSame(['min' => '1', 'endpoint' => 'https://analytics.example.test/api/receive', 'token' => 'example-token', 'consent' => '0'], $tracker);
        parse_str((string) parse_url($crawler->selectLink('Open setup and downloads for this website')->link()->getUri(), PHP_URL_QUERY), $setup);
        self::assertSame(['website' => 'example-token', 'step' => '3', 'format' => 'query', 'tags' => '0'], $setup);

        $crawler = $browser->click($crawler->selectLink('Tag manager')->last()->link());
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        self::assertSame('Tag manager', $crawler->filter('nav[aria-labelledby="website-installation-method"] [aria-current="true"]')->text());
        $snippet = $crawler->filter('#code-1')->text();
        self::assertStringNotContainsString('window[', $snippet);
        self::assertStringNotContainsString('/aggregate.js', $snippet);
        $scripts = new Crawler($snippet);
        self::assertCount(2, $scripts->filter('script'));
        self::assertStringContainsString('/cmp-lite/sites/'.$site.'/consent.js', $scripts->filter('script')->eq(0)->attr('src'));
        self::assertStringContainsString('/tms-lite/sites/'.$site.'/lib.js', $scripts->filter('script')->eq(1)->attr('src'));
        parse_str((string) parse_url($crawler->filter('#tracker-url-1')->text(), PHP_URL_QUERY), $tracker);
        self::assertSame('example-token', $tracker['token']);
        self::assertSame('0', $tracker['consent']);
        self::assertCount(1, $crawler->filter('button[data-controller~="components--ui--copy-button"][data-components--ui--copy-button-source-value="tracker-url-1"]'));
        parse_str((string) parse_url($crawler->selectLink('Open setup and downloads for this website')->link()->getUri(), PHP_URL_QUERY), $setup);
        self::assertSame(['website' => 'example-token', 'step' => '3', 'format' => 'window', 'tags' => '1'], $setup);
        self::assertSame($websitesBefore, file_get_contents($this->temporaryDirectory.'/config/websites.yaml'));
        self::assertSame($configBefore, file_get_contents($this->temporaryDirectory.'/config/aggregate.yaml'));
        self::assertDirectoryDoesNotExist($this->temporaryDirectory.'/config/tag-manager');
        $this->assertPrivate($browser);
        $this->assertNoDatabaseConnection();
    }

    public function testMalformedInstallationOptionsFallBackToTheDefaultSnippet(): void
    {
        $browser = $this->browser('ROLE_USER');
        foreach ([['format' => ['query'], 'tags' => ['1']], ['format' => 'unexpected', 'tags' => 'false']] as $query) {
            $crawler = $browser->request('GET', '/dashboard', $query);
            self::assertSame(200, $browser->getResponse()->getStatusCode());
            self::assertStringContainsString('window["ExampleAnalytics"]', $crawler->filter('#code-1')->text());
            self::assertStringNotContainsString('/tms-lite/', $crawler->filter('#code-1')->text());
        }
        $this->assertNoDatabaseConnection();
    }

    public function testInstallationCodeConsentToggleSwitchesCmpLite(): void
    {
        $browser = $this->browser('ROLE_ADMIN');
        $this->preventDatabaseReadsAndWrites();
        $site = SiteScriptConfig::idForToken('example-token');

        // Default: CMP is enabled
        $crawler = $browser->request('GET', '/dashboard');
        self::assertSame('Include built-in banner (cmp-lite)', $crawler->filter('nav[aria-labelledby="website-consent-controls"] [aria-current="true"]')->text());
        self::assertStringContainsString('/cmp-lite/sites/'.$site.'/consent.js', $crawler->filter('#code-1')->text());

        // Switch to without CMP
        $crawler = $browser->click($crawler->selectLink('Without banner / My own CMP')->link());
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        self::assertSame('Without banner / My own CMP', $crawler->filter('nav[aria-labelledby="website-consent-controls"] [aria-current="true"]')->text());
        self::assertStringNotContainsString('/cmp-lite/', $crawler->filter('#code-1')->text());
        self::assertStringContainsString('window["ExampleAnalytics"]', $crawler->filter('#code-1')->text());
        parse_str((string) parse_url($crawler->selectLink('Open setup and downloads for this website')->link()->getUri(), PHP_URL_QUERY), $setup);
        self::assertSame('external', $setup['consent_option'] ?? null);

        // Switch back to with CMP
        $crawler = $browser->click($crawler->selectLink('Include built-in banner (cmp-lite)')->link());
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        self::assertSame('Include built-in banner (cmp-lite)', $crawler->filter('nav[aria-labelledby="website-consent-controls"] [aria-current="true"]')->text());
        self::assertStringContainsString('/cmp-lite/sites/'.$site.'/consent.js', $crawler->filter('#code-1')->text());
        $this->assertNoDatabaseConnection();
    }

    public function testInstallationCodeRespectsAggregateYamlConsentManagerDefault(): void
    {
        file_put_contents($this->temporaryDirectory.'/config/aggregate.yaml', Yaml::dump([
            'installed' => true,
            'app_host' => 'https://analytics.example.test',
            'js_namespace' => 'ExampleAnalytics',
            'consent_manager' => ['enabled' => false],
        ]));
        $browser = $this->browser('ROLE_ADMIN');
        $this->preventDatabaseReadsAndWrites();

        // When consent_manager.enabled is false in aggregate.yaml, default is without CMP
        $crawler = $browser->request('GET', '/dashboard');
        self::assertSame('Without banner / My own CMP', $crawler->filter('nav[aria-labelledby="website-consent-controls"] [aria-current="true"]')->text());
        self::assertStringNotContainsString('/cmp-lite/', $crawler->filter('#code-1')->text());

        // Site-specific override in tag-manager/sites/<id>.yaml can still enable it
        $directory = $this->temporaryDirectory.'/config/tag-manager/sites';
        (new Filesystem())->mkdir($directory);
        $site = SiteScriptConfig::idForToken('example-token');
        file_put_contents($directory.'/'.$site.'.yaml', "consent_manager:
  enabled: true
");

        $crawler = $browser->request('GET', '/dashboard', ['cmp' => '1']);
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        self::assertSame('Include built-in banner (cmp-lite)', $crawler->filter('nav[aria-labelledby="website-consent-controls"] [aria-current="true"]')->text());
        self::assertStringContainsString('/cmp-lite/sites/'.$site.'/consent.js', $crawler->filter('#code-1')->text());
        $this->assertNoDatabaseConnection();
    }

    public function testOrdinaryUsersRetainWebsiteAccessWithoutAdministratorForms(): void
    {
        $browser = $this->browser('ROLE_USER');
        $this->preventDatabaseReadsAndWrites();
        $crawler = $browser->request('GET', '/dashboard');
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        self::assertCount(1, $crawler->filter('form[action="/dashboard/website/create"]'));
        self::assertCount(0, $crawler->filter('form[action^="/dashboard/settings/"]'));
        self::assertCount(0, $crawler->selectLink('Open setup and downloads for this website'));
        self::assertCount(1, $crawler->filter('#code-1'));
        $crawler = $browser->request('GET', '/dashboard', ['tags' => '1', 'format' => 'query']);
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        self::assertStringNotContainsString('/aggregate.js', $crawler->filter('#code-1')->text());
        self::assertCount(1, $crawler->filter('#tracker-url-1'));
        self::assertCount(0, $crawler->selectLink('Open setup and downloads for this website'));
        $this->assertPrivate($browser);
        $this->assertNoDatabaseConnection();
    }

    public function testDisclosureAndUsersHaveIndependentFormsAndPrivateResponses(): void
    {
        $browser = $this->browser('ROLE_ADMIN');
        $config = $this->createMock(AggregateConfigLoader::class);
        $config->expects(self::exactly(2))
            ->method('getWithEnvFallback')
            ->willReturnCallback(static fn (string $key, mixed $default = null): mixed => match ($key) {
                AnalyticsPrivacySettings::ANONYMOUS_MINIMUM_KEY => 12,
                AnalyticsPrivacySettings::GEO_MINIMUM_KEY => 30,
                default => $default,
            });
        $this->container()->set(AnalyticsPrivacySettings::class, new AnalyticsPrivacySettings($config));
        $repository = $this->createMock(UserRepository::class);
        $repository->expects(self::once())->method('findBy')->willReturn([DashboardSectionsTestUserProvider::user('ROLE_USER')]);
        $this->container()->set(UserRepository::class, $repository);

        $crawler = $browser->request('GET', '/dashboard/privacy');
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        self::assertSame('12', $crawler->filter('input[name="anonymous_min_cell_count"]')->attr('value'));
        self::assertCount(0, $crawler->filter('form[action="/dashboard/settings/anonymous"]'));
        $this->assertPrivate($browser);
        $crawler = $browser->request('GET', '/dashboard/users');
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        self::assertCount(1, $crawler->filter('form[action="/dashboard/users/7/password"]'));
        self::assertCount(0, $crawler->filter('form[action^="/dashboard/settings/"]'));
        $this->assertPrivate($browser);
        $this->assertNoDatabaseConnection();
    }

    public function testForgedCsrfCannotMutateAndReturnsToThePageOwningEachForm(): void
    {
        $browser = $this->browser('ROLE_ADMIN');
        $this->preventDatabaseReadsAndWrites();
        $configBefore = file_get_contents($this->temporaryDirectory.'/config/aggregate.yaml');
        $websitesBefore = file_get_contents($this->temporaryDirectory.'/config/websites.yaml');
        foreach (self::MUTATIONS as $path => $target) {
            foreach ([[], ['_csrf_token' => 'forged']] as $parameters) {
                $browser->request('POST', $path, $parameters, [], ['HTTP_ORIGIN' => 'http://localhost']);
                $this->assertRedirect($browser, $target);
                self::assertContains('Invalid security token. Please try again.', $browser->getRequest()->getSession()->getFlashBag()->peek('error'));
            }
        }
        self::assertSame($configBefore, file_get_contents($this->temporaryDirectory.'/config/aggregate.yaml'));
        self::assertSame($websitesBefore, file_get_contents($this->temporaryDirectory.'/config/websites.yaml'));
        $this->assertNoDatabaseConnection();
    }

    public function testRenderedGeneralAndCollectionFormsSaveOnlyTheirOwnSettings(): void
    {
        $browser = $this->browser('ROLE_ADMIN');
        $this->preventDatabaseReadsAndWrites();
        $form = $browser->request('GET', '/dashboard/settings')->selectButton('Save Settings')->form([
            'app_host' => 'https://new-analytics.example.test',
            'js_namespace' => 'NewAnalytics',
            'rate_limit' => '120',
        ]);
        $browser->submit($form);
        $this->assertRedirect($browser, '/dashboard/settings');
        $saved = Yaml::parseFile($this->temporaryDirectory.'/config/aggregate.yaml');
        self::assertSame('NewAnalytics', $saved['js_namespace']);
        self::assertSame(120, $saved['rate_limit_per_minute']);
        self::assertSame(['/account/**'], $saved['anonymous_excluded_paths']);
        self::assertSame('preserve-me', $saved['unrelated_operator_setting']);
        // An unchanged documentation address is not written, so the default is not pinned.
        self::assertArrayNotHasKey('documentation_url', $saved);

        $collection = $browser->request('GET', '/dashboard/collection');
        // The failure status is read from the database after the page loads.
        self::assertSame('/dashboard/collection/tracking-status', $collection->filter('[data-controller="pages--settings--tracking-status"]')->attr('data-pages--settings--tracking-status-url-value'));
        $form = $collection->selectButton('Save Collection Settings')->form([
            'anonymous_excluded_paths' => "/account/**\n/checkout/**",
            'anonymous_geo_level' => 'macro_region',
            'tracking_retry_enabled' => '0',
            'tracking_retry_batch_size' => '25',
        ]);
        $form['anonymous_tracking_enabled']->untick();
        $browser->submit($form);
        $this->assertRedirect($browser, '/dashboard/collection');
        $saved = Yaml::parseFile($this->temporaryDirectory.'/config/aggregate.yaml');
        self::assertFalse($saved['anonymous_tracking_enabled']);
        self::assertFalse($saved['tracking_retry_enabled']);
        self::assertSame(25, $saved['tracking_retry_batch_size']);
        self::assertSame(['/account/**', '/checkout/**'], $saved['anonymous_excluded_paths']);
        self::assertSame('standard', $saved['collection_profile']);
        self::assertSame('NewAnalytics', $saved['js_namespace']);
        self::assertSame('preserve-me', $saved['unrelated_operator_setting']);
        $this->assertNoDatabaseConnection();
    }

    public function testDocumentationUrlControlsEveryDocumentationLink(): void
    {
        $browser = $this->browser('ROLE_ADMIN');
        $this->preventDatabaseReadsAndWrites();
        $crawler = $browser->request('GET', '/dashboard/settings');
        self::assertSame(DocumentationLinks::DEFAULT_URL, $crawler->filter('input[name="documentation_url"]')->attr('value'));
        self::assertSame(DocumentationLinks::DEFAULT_URL.'configure/configuration#application-settings', $crawler->filter('.docs-link a')->first()->attr('href'));
        $navigation = $crawler->filter('nav.app-navigation a[href="'.DocumentationLinks::DEFAULT_URL.'"]');
        self::assertCount(1, $navigation);
        self::assertSame('_blank', $navigation->attr('target'));
        self::assertSame('noopener noreferrer', $navigation->attr('rel'));
        self::assertCount(1, $crawler->filter('nav.app-navigation a[href="/how-it-works"]'));

        $browser->submit($crawler->selectButton('Save Settings')->form(['documentation_url' => 'https://docs.example.test/analytics']));
        $this->assertRedirect($browser, '/dashboard/settings');
        $saved = Yaml::parseFile($this->temporaryDirectory.'/config/aggregate.yaml');
        self::assertSame('https://docs.example.test/analytics/', $saved['documentation_url']);
        self::assertSame('preserve-me', $saved['unrelated_operator_setting']);
        $crawler = $browser->request('GET', '/dashboard/collection');
        self::assertSame('https://docs.example.test/analytics/privacy/compliance#administrative-controls', $crawler->filter('.docs-link a')->attr('href'));

        $before = file_get_contents($this->temporaryDirectory.'/config/aggregate.yaml');
        $values = $browser->request('GET', '/dashboard/settings')->selectButton('Save Settings')->form()->getPhpValues();
        $values['documentation_url'] = 'javascript:alert(1)';
        $browser->request('POST', '/dashboard/settings/save', $values, [], ['HTTP_ORIGIN' => 'http://localhost']);
        $this->assertRedirect($browser, '/dashboard/settings');
        self::assertStringContainsString('full web address', implode(' ', $browser->getRequest()->getSession()->getFlashBag()->peek('error')));
        self::assertSame($before, file_get_contents($this->temporaryDirectory.'/config/aggregate.yaml'));

        $browser->submit($browser->request('GET', '/dashboard/settings')->selectButton('Save Settings')->form(['documentation_url' => '']));
        self::assertSame('', Yaml::parseFile($this->temporaryDirectory.'/config/aggregate.yaml')['documentation_url']);
        $crawler = $browser->request('GET', '/dashboard/settings');
        self::assertCount(0, $crawler->filter('.docs-link'));
        self::assertCount(0, $crawler->filter('a[target="_blank"][href*="docs.example.test"], a[href^="'.DocumentationLinks::DEFAULT_URL.'"]'));
        self::assertCount(1, $crawler->filter('nav.app-navigation a[href="/how-it-works"]'));

        // DOCUMENTATION_URL takes precedence: the field is disabled and a submitted value is ignored.
        $_ENV['DOCUMENTATION_URL'] = $_SERVER['DOCUMENTATION_URL'] = 'https://env-docs.example.test/';
        $crawler = $browser->request('GET', '/dashboard/settings');
        self::assertNotNull($crawler->filter('input[name="documentation_url"]')->attr('disabled'));
        self::assertSame('https://env-docs.example.test/configure/configuration#application-settings', $crawler->filter('.docs-link a')->first()->attr('href'));
        $values = $crawler->selectButton('Save Settings')->form()->getPhpValues();
        $values['documentation_url'] = 'https://other.example.test/';
        $browser->request('POST', '/dashboard/settings/save', $values, [], ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertSame('', Yaml::parseFile($this->temporaryDirectory.'/config/aggregate.yaml')['documentation_url']);
        $this->assertNoDatabaseConnection();
    }

    public function testPageSpeedPanelShowsEachSaverAndSavesItsSetting(): void
    {
        $browser = $this->browser('ROLE_ADMIN');
        $this->preventDatabaseReadsAndWrites();
        $crawler = $browser->request('GET', '/dashboard/settings');
        $panel = $crawler->filter('#page-speed');
        self::assertCount(1, $panel);
        self::assertSame('/aggregate.js?min=1', $panel->attr('data-pages--settings--page-speed-url-value'));
        self::assertNotNull($panel->filter('input[name="tracker_omit_unused_features"]')->attr('checked'), 'on by default');
        $savers = $panel->filter('.page-speed-savers tbody th')->each(static fn (Crawler $cell): string => $cell->text());
        self::assertSame(['Leave out unused tracker features', 'Minified scripts', 'Shorter internal names', 'Compression', 'Browser caching', 'Loads without blocking'], $savers);
        // Page depth is off here, so the tracker without it is sent and the summary shows the saving.
        self::assertStringContainsString('Page depth is off, so visitors get the tracker without it.', $panel->filter('.page-speed-savers')->text());
        self::assertMatchesRegularExpression('/Visitors download the tracker in [0-9.]+ KB compressed, instead of [0-9.]+ KB for the full tracker/', $panel->filter('.page-speed-summary')->text());
        self::assertStringEndsWith('tracking/page-speed', (string) $panel->filter('.docs-link a')->last()->attr('href'));

        $form = $panel->selectButton('Save page speed setting')->form();
        $form['tracker_omit_unused_features']->untick();
        $browser->submit($form);
        $this->assertRedirect($browser, '/dashboard/settings');
        $saved = Yaml::parseFile($this->temporaryDirectory.'/config/aggregate.yaml');
        self::assertFalse($saved['tracker_omit_unused_features'], 'an unticked box turns it off');
        self::assertSame('preserve-me', $saved['unrelated_operator_setting']);
        self::assertSame('ExampleAnalytics', $saved['js_namespace']);
        $panel = $browser->request('GET', '/dashboard/settings')->filter('#page-speed');
        self::assertNull($panel->filter('input[name="tracker_omit_unused_features"]')->attr('checked'));
        self::assertStringContainsString('Every visitor gets the full tracker.', $panel->text());
        self::assertStringNotContainsString('instead of', $panel->filter('.page-speed-summary')->text());

        $form = $panel->selectButton('Save page speed setting')->form();
        $form['tracker_omit_unused_features']->tick();
        $browser->submit($form);
        self::assertTrue(Yaml::parseFile($this->temporaryDirectory.'/config/aggregate.yaml')['tracker_omit_unused_features']);

        // The environment variable takes precedence: the box is disabled and saving is refused.
        $_ENV['TRACKER_OMIT_UNUSED_FEATURES'] = $_SERVER['TRACKER_OMIT_UNUSED_FEATURES'] = 'off';
        $panel = $browser->request('GET', '/dashboard/settings')->filter('#page-speed');
        self::assertNotNull($panel->filter('input[name="tracker_omit_unused_features"]')->attr('disabled'));
        self::assertCount(0, $panel->selectButton('Save page speed setting'));
        $before = file_get_contents($this->temporaryDirectory.'/config/aggregate.yaml');
        $browser->request('POST', '/dashboard/settings/page-speed', ['_csrf_token' => $panel->filter('input[name="_csrf_token"]')->attr('value'), 'tracker_omit_unused_features' => '1'], [], ['HTTP_ORIGIN' => 'http://localhost']);
        $this->assertRedirect($browser, '/dashboard/settings');
        self::assertStringContainsString('TRACKER_OMIT_UNUSED_FEATURES', implode(' ', $browser->getRequest()->getSession()->getFlashBag()->peek('error')));
        self::assertSame($before, file_get_contents($this->temporaryDirectory.'/config/aggregate.yaml'));

        // Callouts beside the settings that change the tracker's size.
        self::assertStringContainsString('visitors get a tracker about half the size', $browser->request('GET', '/dashboard/collection')->filter('.page-speed-note')->text());
        $this->assertNoDatabaseConnection();
    }

    public function testCollectionProfileIsSavedFromTheRenderedFormAndInvalidValuesAreRejected(): void
    {
        $browser = $this->browser('ROLE_ADMIN');
        $this->preventDatabaseReadsAndWrites();
        $crawler = $browser->request('GET', '/dashboard/collection');
        self::assertCount(1, $crawler->filter('fieldset legend:contains("Collection profile")'));
        self::assertCount(1, $crawler->filter('input[name="collection_profile"][value="standard"][checked]'));

        $form = $crawler->selectButton('Save Collection Settings')->form(['collection_profile' => 'strict']);
        $browser->submit($form);
        $this->assertRedirect($browser, '/dashboard/collection');
        $saved = Yaml::parseFile($this->temporaryDirectory.'/config/aggregate.yaml');
        self::assertSame('strict', $saved['collection_profile']);
        self::assertTrue($saved['anonymous_tracking_enabled']);
        self::assertSame('preserve-me', $saved['unrelated_operator_setting']);
        self::assertCount(1, $browser->request('GET', '/dashboard/collection')->filter('input[name="collection_profile"][value="strict"][checked]'));

        $before = file_get_contents($this->temporaryDirectory.'/config/aggregate.yaml');
        $values = $browser->request('GET', '/dashboard/collection')->selectButton('Save Collection Settings')->form()->getPhpValues();
        $values['collection_profile'] = 'relaxed';
        $browser->request('POST', '/dashboard/settings/anonymous', $values, [], ['HTTP_ORIGIN' => 'http://localhost']);
        $this->assertRedirect($browser, '/dashboard/collection');
        self::assertContains('Collection profile must be standard or strict.', $browser->getRequest()->getSession()->getFlashBag()->peek('error'));
        self::assertSame($before, file_get_contents($this->temporaryDirectory.'/config/aggregate.yaml'));
        $this->assertNoDatabaseConnection();
    }

    public function testHeadlessBootOmitsEveryDashboardSectionAndMutation(): void
    {
        $browser = $this->browser(dashboardEnabled: false);
        self::assertFalse($this->container()->has(DashboardController::class));
        $routes = $this->container()->get('router')->getRouteCollection();
        foreach (self::PAGES as $name => [$path]) {
            self::assertNull($routes->get($name));
            $browser->request('GET', $path);
            self::assertSame(404, $browser->getResponse()->getStatusCode(), $path);
        }
        foreach (self::MUTATIONS as $path => $target) {
            $browser->request('POST', $path, ['_csrf_token' => 'forged']);
            self::assertSame(404, $browser->getResponse()->getStatusCode(), $path);
        }
        $this->assertNoDatabaseConnection();
    }

    private function browser(?string $role = null, bool $dashboardEnabled = true): KernelBrowser
    {
        $_ENV['DASHBOARD_ENABLED'] = $_SERVER['DASHBOARD_ENABLED'] = $dashboardEnabled ? '1' : '0';
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        $_ENV['TRUSTED_PROXIES'] = $_SERVER['TRUSTED_PROXIES'] = '';
        $this->kernel = new DashboardSectionsRoutesTestKernel($this->temporaryDirectory);
        $this->kernel->boot();
        $browser = new KernelBrowser($this->kernel);
        $browser->disableReboot();
        if ($role !== null) {
            $browser->loginUser(DashboardSectionsTestUserProvider::user($role));
        }

        return $browser;
    }

    private function container(): \Psr\Container\ContainerInterface
    {
        return $this->kernel->getContainer()->get('test.service_container');
    }

    private function preventDatabaseReadsAndWrites(): void
    {
        $config = $this->createMock(AggregateConfigLoader::class);
        $config->expects(self::never())->method('setMany');
        $this->container()->set(AnalyticsPrivacySettings::class, new AnalyticsPrivacySettings($config));
        $repository = $this->createMock(UserRepository::class);
        foreach (['findBy', 'findOneBy', 'find'] as $method) {
            $repository->expects(self::never())->method($method);
        }
        $this->container()->set(UserRepository::class, $repository);
    }

    private function assertPrivate(KernelBrowser $browser): void
    {
        self::assertTrue($browser->getResponse()->headers->hasCacheControlDirective('private'));
        self::assertTrue($browser->getResponse()->headers->hasCacheControlDirective('no-store'));
    }

    private function assertRedirect(KernelBrowser $browser, string $path): void
    {
        self::assertSame(302, $browser->getResponse()->getStatusCode());
        self::assertSame($path, parse_url((string) $browser->getResponse()->headers->get('Location'), PHP_URL_PATH));
    }

    private function assertNoDatabaseConnection(): void
    {
        self::assertFalse($this->container()->get('doctrine.dbal.default_connection')->isConnected());
    }
}

/** Exercise the real firewall and templates with isolated configuration and accounts. */
final class DashboardSectionsRoutesTestKernel extends Kernel implements CompilerPassInterface
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
        $container->setDefinition('security.user.provider.concrete.app_user_provider', new Definition(DashboardSectionsTestUserProvider::class));
        foreach ([AggregateConfigLoader::class, WebsiteConfigManager::class, BrandingLogoManager::class, SiteScriptConfig::class] as $service) {
            $container->getDefinition($service)->setArgument('$projectDir', $this->temporaryDirectory);
        }
    }
}

/** @implements UserProviderInterface<User> */
final class DashboardSectionsTestUserProvider implements UserProviderInterface
{
    public static function user(string $role): User
    {
        $user = (new User())->setUsername($role === 'ROLE_ADMIN' ? 'section-admin' : 'section-user')->setRoles([$role])->setPassword('test-only');
        (new \ReflectionProperty($user, 'id'))->setValue($user, 7);

        return $user;
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        return self::user($identifier === 'section-admin' ? 'ROLE_ADMIN' : 'ROLE_USER');
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException();
        }

        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return $class === User::class;
    }
}
