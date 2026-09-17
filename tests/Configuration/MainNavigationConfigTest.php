<?php

declare(strict_types=1);

namespace App\Tests\Configuration;

use App\Service\ApplicationUpdateService;
use App\Service\ReportingViewManager;
use App\Twig\FeatureFlagsExtension;
use App\Twig\NavigationExtension;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;
use Twig\Environment;

final class MainNavigationConfigTest extends KernelTestCase
{
    public function testDataModelNavigationAndRoutesExposeOnlyAdministratorPagesAndPostMutations(): void
    {
        self::bootKernel();

        $container = self::getContainer();
        $navigation = $container->getParameter('app.main_navigation');
        $links = array_values(array_filter(
            $this->links($navigation['items']),
            static fn (mixed $item): bool => is_array($item) && ($item['route'] ?? null) === 'app_data_model',
        ));
        self::assertCount(1, $links);
        self::assertSame('Data model', $links[0]['label']);
        self::assertSame('ROLE_ADMIN', $links[0]['role']);

        $routes = $container->get('router')->getRouteCollection();
        foreach ([
            'app_data_model' => ['/dashboard/data-model', 'GET'],
            'app_data_model_download' => ['/dashboard/data-model/download', 'GET'],
            'app_data_model_save' => ['/dashboard/data-model/save', 'POST'],
            'app_data_model_regenerate' => ['/dashboard/data-model/regenerate', 'POST'],
        ] as $name => [$path, $method]) {
            $route = $routes->get($name);
            self::assertNotNull($route);
            self::assertSame($path, $route->getPath());
            self::assertSame([$method], $route->getMethods());
        }
    }

    public function testDataModelRoutesRequireAuthenticationBeforeReadingOrMutatingReportingViews(): void
    {
        self::bootKernel();

        $views = $this->createMock(ReportingViewManager::class);
        $views->expects(self::never())->method('previewSql');
        $views->expects(self::never())->method('discoverProperties');
        $views->expects(self::never())->method('regenerate');
        self::getContainer()->set(ReportingViewManager::class, $views);
        $browser = new KernelBrowser(self::$kernel);
        $browser->disableReboot();
        foreach ([
            '/dashboard/data-model?discover=1' => 'GET',
            '/dashboard/data-model/download' => 'GET',
            '/dashboard/data-model/save' => 'POST',
            '/dashboard/data-model/regenerate' => 'POST',
        ] as $path => $method) {
            $browser->request($method, $path);

            self::assertSame(302, $browser->getResponse()->getStatusCode());
            self::assertSame('/login', parse_url((string) $browser->getResponse()->headers->get('Location'), PHP_URL_PATH));
        }
    }

    public function testNavigationIsAvailableToTwigAndReferencesExistingRoutes(): void
    {
        self::bootKernel();

        $container = self::getContainer();
        $navigation = $container->getParameter('app.main_navigation');

        self::assertIsArray($navigation);
        self::assertArrayHasKey('brand', $navigation);
        self::assertArrayHasKey('items', $navigation);
        self::assertArrayHasKey('account', $navigation);
        self::assertIsArray($navigation['brand']);
        self::assertIsArray($navigation['items']);
        self::assertIsArray($navigation['account']);
        self::assertIsArray($navigation['account']['logout'] ?? null);

        $twig = $container->get('twig');
        self::assertInstanceOf(Environment::class, $twig);
        self::assertSame($navigation, $twig->getGlobals()['main_navigation'] ?? null);
        self::assertTrue($twig->hasExtension(FeatureFlagsExtension::class));
        self::assertTrue($twig->hasExtension(NavigationExtension::class));
        foreach (['feature_enabled', 'feature_hidden_from_navigation', 'navigation_feature_state', 'navigation_menu'] as $function) {
            self::assertNotNull($twig->getFunction($function));
        }

        $router = $container->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        $links = [
            $navigation['brand'],
            ...$this->links($navigation['items']),
            $navigation['account']['logout'],
        ];

        foreach ($links as $link) {
            self::assertIsArray($link);
            self::assertIsString($link['label'] ?? null);
            self::assertNotSame('', trim($link['label']));

            $hasRoute = array_key_exists('route', $link);
            $hasUrl = array_key_exists('url', $link);
            self::assertNotSame($hasRoute, $hasUrl, 'A navigation link must define exactly one of "route" or "url".');

            if ($hasRoute) {
                self::assertIsString($link['route']);
                self::assertNotNull(
                    $router->getRouteCollection()->get($link['route']),
                    sprintf('Navigation route "%s" does not exist.', $link['route']),
                );
                $routeParameters = $link['route_parameters'] ?? [];
                self::assertIsArray($routeParameters);
                self::assertNotSame('', $router->generate($link['route'], $routeParameters));

                continue;
            }

            self::assertIsString($link['url']);
            self::assertNotSame('', trim($link['url']));
        }
    }

    public function testDataLifecycleSettingsHaveADedicatedNavigationDestination(): void
    {
        self::bootKernel();

        $container = self::getContainer();
        $navigation = $container->getParameter('app.main_navigation');
        self::assertIsArray($navigation);
        self::assertIsArray($navigation['items'] ?? null);

        $lifecycleLinks = array_values(array_filter(
            $this->links($navigation['items']),
            static fn (mixed $item): bool => is_array($item)
                && ($item['route'] ?? null) === 'app_data_lifecycle',
        ));
        self::assertCount(1, $lifecycleLinks);
        self::assertNotSame('', trim((string) ($lifecycleLinks[0]['label'] ?? '')));
        self::assertSame('ROLE_ADMIN', $lifecycleLinks[0]['role'] ?? null);

        $router = $container->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        $indexRoute = $router->getRouteCollection()->get('app_data_lifecycle');
        $saveRoute = $router->getRouteCollection()->get('app_data_lifecycle_save');
        self::assertNotNull($indexRoute);
        self::assertNotNull($saveRoute);
        self::assertSame(['GET'], $indexRoute->getMethods());
        self::assertSame(['POST'], $saveRoute->getMethods());
    }

    public function testUpdatesHaveAnAdministratorNavigationDestinationAndPostOnlyRefresh(): void
    {
        self::bootKernel();

        $container = self::getContainer();
        $navigation = $container->getParameter('app.main_navigation');
        $updateLinks = array_values(array_filter(
            $this->links($navigation['items']),
            static fn (mixed $item): bool => is_array($item)
                && ($item['route'] ?? null) === 'app_updates',
        ));
        self::assertCount(1, $updateLinks);
        self::assertSame('Updates', $updateLinks[0]['label']);
        self::assertSame('ROLE_ADMIN', $updateLinks[0]['role']);
        self::assertSame('updates', $updateLinks[0]['feature']);

        $router = $container->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);
        $indexRoute = $router->getRouteCollection()->get('app_updates');
        $refreshRoute = $router->getRouteCollection()->get('app_updates_refresh');
        self::assertNotNull($indexRoute);
        self::assertNotNull($refreshRoute);
        self::assertSame('/dashboard/updates', $indexRoute->getPath());
        self::assertSame(['GET'], $indexRoute->getMethods());
        self::assertSame(['POST'], $refreshRoute->getMethods());
    }

    public function testFeatureFlagsHaveAnAdministratorNavigationDestination(): void
    {
        self::bootKernel();

        $container = self::getContainer();
        $links = array_values(array_filter(
            $this->links($container->getParameter('app.main_navigation')['items']),
            static fn (mixed $item): bool => is_array($item)
                && ($item['route'] ?? null) === 'app_feature_flags',
        ));
        self::assertCount(1, $links);
        self::assertSame('ROLE_ADMIN', $links[0]['role']);
        self::assertArrayNotHasKey('feature', $links[0], 'Feature management must remain accessible when other features are disabled.');
        $route = $container->get('router')->getRouteCollection()->get('app_feature_flags');
        self::assertNotNull($route);
        self::assertSame('/dashboard/feature-flags', $route->getPath());
        self::assertSame(['GET'], $route->getMethods());
    }

    public function testUpdateRoutesRequireAuthenticationBeforeCheckingGithub(): void
    {
        self::bootKernel();

        $updates = $this->createMock(ApplicationUpdateService::class);
        $updates->expects(self::never())->method('check');
        self::getContainer()->set(ApplicationUpdateService::class, $updates);

        $browser = new KernelBrowser(self::$kernel);
        $browser->disableReboot();
        foreach (['/dashboard/updates' => 'GET', '/dashboard/updates/refresh' => 'POST'] as $path => $method) {
            $browser->request($method, $path);

            self::assertSame(302, $browser->getResponse()->getStatusCode());
            self::assertSame('/login', parse_url(
                (string) $browser->getResponse()->headers->get('Location'),
                PHP_URL_PATH,
            ));
        }
    }
    /** @return list<array> */
    private function links(array $items): array
    {
        $links = [];
        foreach ($items as $item) {
            self::assertIsArray($item);
            if (array_key_exists('children', $item)) {
                self::assertIsString($item['label'] ?? null);
                self::assertNotSame('', trim($item['label']));
                self::assertArrayNotHasKey('route', $item);
                self::assertArrayNotHasKey('url', $item);
                self::assertIsArray($item['children']);
                self::assertNotEmpty($item['children']);
                foreach ($item['children'] as $child) {
                    self::assertIsArray($child);
                    self::assertArrayNotHasKey('children', $child, 'Navigation supports one submenu level.');
                    $links[] = $child;
                }
            } else {
                $links[] = $item;
            }
        }

        return $links;
    }

}
