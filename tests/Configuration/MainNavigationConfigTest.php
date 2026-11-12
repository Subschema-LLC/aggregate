<?php

declare(strict_types=1);

namespace App\Tests\Configuration;

use App\Security\Access\DashboardPages;
use App\Service\ApplicationUpdateService;
use App\Service\DocumentationLinks;
use App\Service\ReportingViewManager;
use App\Twig\FeatureFlagsExtension;
use App\Twig\NavigationExtension;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;
use Twig\Environment;

final class MainNavigationConfigTest extends KernelTestCase
{
    public function testDataModelRoutesExposeOnlyPermittedPagesAndPostMutations(): void
    {
        self::bootKernel();

        $container = self::getContainer();
        self::assertSame('data_model', DashboardPages::requirement('app_data_model')['page']);

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

    /**
     * The brand (logo) and account links of the shipped menu, which every
     * signed-in user needs. The groups and links inside them are the
     * operator's to arrange, so no test pins them.
     */
    public function testNavigationIsAvailableToTwigWithAWorkingBrandAndLogout(): void
    {
        self::bootKernel();

        $container = self::getContainer();
        $navigation = $container->getParameter('app.main_navigation');

        self::assertIsArray($navigation);
        self::assertArrayHasKey('brand', $navigation);
        self::assertArrayHasKey('account', $navigation);
        self::assertIsArray($navigation['brand']);
        self::assertIsArray($navigation['account']);
        self::assertIsArray($navigation['account']['logout'] ?? null);
        self::assertSame('/logout', $navigation['account']['logout']['url'] ?? null);

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
            $navigation['account']['logout'],
        ];

        foreach ($links as $link) {
            self::assertIsArray($link);
            self::assertIsString($link['label'] ?? null);
            self::assertNotSame('', trim($link['label']));

            $hasRoute = array_key_exists('route', $link);
            $hasUrl = array_key_exists('url', $link);
            $hasDocs = array_key_exists('docs', $link);
            self::assertSame(1, (int) $hasRoute + (int) $hasUrl + (int) $hasDocs, 'A navigation link must define exactly one of "route", "url" or "docs".');

            if ($hasDocs) {
                self::assertTrue(DocumentationLinks::hasTopic($link['docs']), sprintf('Navigation documentation topic "%s" does not exist.', $link['docs']));

                continue;
            }

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

    public function testDataLifecycleSettingsHaveTheirOwnPage(): void
    {
        self::bootKernel();

        $container = self::getContainer();
        self::assertSame('data_lifecycle', DashboardPages::requirement('app_data_lifecycle')['page']);

        $router = $container->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        $indexRoute = $router->getRouteCollection()->get('app_data_lifecycle');
        $saveRoute = $router->getRouteCollection()->get('app_data_lifecycle_save');
        self::assertNotNull($indexRoute);
        self::assertNotNull($saveRoute);
        self::assertSame(['GET'], $indexRoute->getMethods());
        self::assertSame(['POST'], $saveRoute->getMethods());
    }

    public function testUpdatesHaveTheirOwnPageAndPostOnlyRefresh(): void
    {
        self::bootKernel();

        $container = self::getContainer();
        self::assertSame('updates', DashboardPages::requirement('app_updates')['page']);

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

    public function testFeatureFlagsHaveTheirOwnPage(): void
    {
        self::bootKernel();

        $container = self::getContainer();
        self::assertSame('feature_flags', DashboardPages::requirement('app_feature_flags')['page']);
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
}
