<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Service\AggregateConfigLoader;
use App\Service\BigQuery\BigQuerySettings;
use App\Service\FeatureFlags;
use App\Security\Access\DashboardPages;
use App\Service\NavigationConditions;
use App\Service\ReportingDatabase\ReportingDatabaseSettings;
use App\Service\ViewSync\ViewSyncDestinations;
use App\Twig\FeatureFlagsExtension;
use App\Twig\NavigationExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * A link's shown_when condition keeps it out of the menu until the page is in
 * use, while config/navigation.yaml still says when it appears and quick
 * search still finds it.
 */
final class NavigationShownWhenTest extends TestCase
{
    private string $projectDir;
    private array $environment;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        foreach (['BIGQUERY_ENABLED', 'REPORTING_DATABASE_ENABLED'] as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
        }
        $this->projectDir = sys_get_temp_dir().'/aggregate-shown-when-'.bin2hex(random_bytes(6));
        mkdir($this->projectDir.'/config', 0700, true);
    }

    protected function tearDown(): void
    {
        [$_ENV, $_SERVER] = $this->environment;
        foreach (glob($this->projectDir.'/config/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->projectDir.'/config');
        rmdir($this->projectDir);
    }

    public function testSyncStaysOutOfTheMenuUntilADestinationIsOnButQuickSearchFindsIt(): void
    {
        $extension = $this->extension([]);
        $menu = $extension->menu($this->navigation());

        self::assertSame(['Reporting views'], array_column($menu['items'][0]['children'], 'label'));
        $search = $extension->quickSearchItems($menu);
        $sync = array_values(array_filter($search, static fn (array $item): bool => $item['title'] === 'Sync'));
        self::assertCount(1, $sync);
        self::assertSame('Reporting', $sync[0]['category']);
        self::assertSame('/dashboard/sync', $sync[0]['url']);
        self::assertStringContainsString('bigquery', $sync[0]['keywords']);
    }

    public function testSyncAppearsOnceEitherDestinationIsOn(): void
    {
        foreach (['bigquery_enabled', 'reporting_database_enabled'] as $key) {
            $extension = $this->extension([$key => true]);
            $menu = $extension->menu($this->navigation());

            self::assertSame(['Reporting views', 'Sync'], array_column($menu['items'][0]['children'], 'label'), $key);
            self::assertSame([], $menu['search_only']);
            self::assertCount(1, array_filter($extension->quickSearchItems($menu), static fn (array $item): bool => $item['title'] === 'Sync'), 'listed once');
        }
    }

    public function testInvalidSyncSettingsKeepTheEntrySoThePageCanRepairThem(): void
    {
        $menu = $this->extension(['bigquery_interval_minutes' => 7])->menu($this->navigation());

        self::assertContains('Sync', array_column($menu['items'][0]['children'], 'label'));
    }

    public function testAnUnknownConditionHidesTheLinkEverywhere(): void
    {
        $navigation = $this->navigation();
        $navigation['items'][0]['children'][1]['shown_when'] = 'view_sync_onn';
        $extension = $this->extension(['bigquery_enabled' => true]);
        $menu = $extension->menu($navigation);

        self::assertSame(['Reporting views'], array_column($menu['items'][0]['children'], 'label'));
        self::assertSame([], array_filter($extension->quickSearchItems($menu), static fn (array $item): bool => $item['title'] === 'Sync'));
    }

    public function testAConditionBelongsOnLinksNotGroups(): void
    {
        $navigation = $this->navigation();
        $navigation['items'][0]['shown_when'] = 'view_sync_on';

        self::assertSame([], $this->extension(['bigquery_enabled' => true])->menu($navigation)['items']);
    }

    public function testTheSyncEntryOpensTheDestinationThatIsOn(): void
    {
        self::assertSame('app_bigquery', $this->destinations([])->landingRoute());
        self::assertSame('app_reporting_database', $this->destinations(['reporting_database_enabled' => true])->landingRoute());
        self::assertSame('app_bigquery', $this->destinations(['reporting_database_enabled' => true, 'bigquery_enabled' => true])->landingRoute());
        self::assertSame(
            ['bigquery' => null, 'reporting_database' => false],
            array_map(static fn (array $destination): ?bool => $destination['on'], $this->destinations(['bigquery_interval_minutes' => 7])->all()),
        );
    }

    public function testTheShippedMenuStatesItsRules(): void
    {
        $items = Yaml::parseFile(dirname(__DIR__, 2).'/config/navigation.yaml')['parameters']['app.main_navigation']['items'];
        $links = [];
        foreach ($items as $item) {
            foreach ($item['children'] ?? [$item] as $link) {
                $links[$link['label']] = ['group' => $item['label'], ...$link];
            }
        }

        self::assertSame('view_sync_on', $links['Sync']['shown_when']);
        self::assertSame('app_sync', $links['Sync']['route']);
        self::assertSame('Reporting', $links['Sync']['group']);
        self::assertArrayNotHasKey('BigQuery sync', $links);
        self::assertArrayNotHasKey('Reporting database', $links);
        self::assertArrayNotHasKey('Reporting timezone', $links);
        self::assertSame('Help', $links['Event examples']['group']);
        // Page permissions decide who sees a dashboard link; only administrator-only pages name a role.
        self::assertArrayNotHasKey('role', $links['Event examples']);
        self::assertSame(['event_examples', DashboardPages::VIEW], DashboardPages::ROUTES['app_event_examples']);
        foreach ($links as $label => $link) {
            $adminOnly = isset($link['route'], DashboardPages::ROUTES[$link['route']]) && DashboardPages::requirement($link['route'])['kind'] === DashboardPages::ADMIN;
            if ($adminOnly) {
                self::assertSame('ROLE_ADMIN', $link['role'] ?? null, $label);
            } else {
                self::assertArrayNotHasKey('role', $link, $label);
            }
        }
        self::assertSame('ROLE_ADMIN', $links['Users']['role']);
        self::assertSame('ROLE_ADMIN', $links['Data transfer']['role']);
        foreach ($links as $label => $link) {
            if (array_key_exists('shown_when', $link)) {
                self::assertTrue(NavigationConditions::has($link['shown_when']), $label);
            }
        }
    }

    private function extension(array $config): NavigationExtension
    {
        $routes = new RouteCollection();
        $routes->add('app_data_model_reporting', new Route('/dashboard/data-model/reporting'));
        $routes->add('app_sync', new Route('/dashboard/sync'));
        $features = $this->createStub(FeatureFlags::class);
        $features->method('isEnabled')->willReturn(true);
        $features->method('isHiddenFromNavigation')->willReturn(false);
        $authorization = $this->createStub(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willReturn(true);
        $synonyms = Yaml::parseFile(dirname(__DIR__, 2).'/config/quick_search.yaml')['parameters']['app.quick_search_synonyms'];

        return new NavigationExtension(
            new FeatureFlagsExtension($features),
            $authorization,
            new UrlGenerator($routes, new RequestContext()),
            quickSearchSynonyms: $synonyms,
            conditions: new NavigationConditions($this->destinations($config)),
        );
    }

    private function destinations(array $config): ViewSyncDestinations
    {
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump($config));
        $loader = new AggregateConfigLoader($this->projectDir, 'test');

        return new ViewSyncDestinations(new BigQuerySettings($loader, $this->projectDir), new ReportingDatabaseSettings($loader));
    }

    private function navigation(): array
    {
        return ['items' => [[
            'label' => 'Reporting',
            'children' => [
                ['label' => 'Reporting views', 'route' => 'app_data_model_reporting', 'role' => 'ROLE_ADMIN'],
                ['label' => 'Sync', 'route' => 'app_sync', 'role' => 'ROLE_ADMIN', 'icon' => 'fas fa-cloud-arrow-up', 'shown_when' => 'view_sync_on'],
            ],
        ]]];
    }
}
