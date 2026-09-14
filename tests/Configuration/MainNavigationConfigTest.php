<?php

declare(strict_types=1);

namespace App\Tests\Configuration;

use App\Service\ApplicationUpdateService;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;
use Twig\Environment;

final class MainNavigationConfigTest extends KernelTestCase
{
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

        $router = $container->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        $links = [
            $navigation['brand'],
            ...$navigation['items'],
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
            $navigation['items'],
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
            $navigation['items'],
            static fn (mixed $item): bool => is_array($item)
                && ($item['route'] ?? null) === 'app_updates',
        ));
        self::assertCount(1, $updateLinks);
        self::assertSame('Updates', $updateLinks[0]['label']);
        self::assertSame('ROLE_ADMIN', $updateLinks[0]['role']);

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
