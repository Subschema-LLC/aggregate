<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Service\FeatureFlags;
use App\Twig\FeatureFlagsExtension;
use App\Twig\NavigationExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * How the menu code treats any navigation file: the brand, the account and
 * its sign-out link, and section headings inside groups. The menus here are
 * made up for the test; the shipped navigation.yaml is the operator's to
 * arrange and no test pins its groups or links.
 */
final class NavigationMenuTest extends TestCase
{
    public function testAMenuWithoutAnAccountEntryStillSignsOut(): void
    {
        $menu = $this->extension()->menu(['items' => []]);

        self::assertSame('/logout', $menu['account']['logout']['url']);
        self::assertTrue($menu['account']['logout']['logout']);
    }

    public function testAConfiguredExitIsKeptAndAnUnusableOneFallsBackToSigningOut(): void
    {
        $configured = $this->extension()->menu(['account' => ['user_icon' => 'fas fa-circle-user', 'logout' => ['label' => 'Leave', 'url' => 'https://sso.example.com/logout']]]);
        self::assertSame(['Leave', 'https://sso.example.com/logout', 'fas fa-circle-user'], [$configured['account']['logout']['label'], $configured['account']['logout']['url'], $configured['account']['user_icon']]);

        foreach ([['label' => 'Unsafe', 'url' => 'javascript:alert(1)'], ['label' => 'Admins only', 'url' => '/logout', 'role' => 'ROLE_ADMIN'], 'not a link'] as $logout) {
            $menu = $this->extension(denied: ['ROLE_ADMIN'])->menu(['account' => ['logout' => $logout]]);
            self::assertSame(['Logout', '/logout'], [$menu['account']['logout']['label'], $menu['account']['logout']['url']]);
        }
    }

    public function testTheBrandLinksToItsRoute(): void
    {
        $menu = $this->extension()->menu(['brand' => ['label' => 'Example Analytics', 'route' => 'app_home']]);

        self::assertSame(['Example Analytics', '/'], [$menu['brand']['label'], $menu['brand']['url']]);
    }

    public function testHeadingsNameTheLinksAfterThemAndEmptyOnesAreLeftOut(): void
    {
        $menu = $this->extension(denied: ['ROLE_ADMIN'])->menu(['items' => [[
            'label' => 'Administration',
            'children' => [
                ['heading' => 'Settings'],
                ['label' => 'General settings', 'route' => 'app_settings'],
                ['heading' => 'People'],
                ['label' => 'Users', 'route' => 'app_users', 'role' => 'ROLE_ADMIN'],
                ['heading' => 'Operations'],
                ['label' => 'Scheduled tasks', 'route' => 'app_tasks'],
                ['heading' => 'Nothing below'],
            ],
        ], [
            'label' => 'Only headings',
            'children' => [['heading' => 'Alone'], ['label' => 'Hidden', 'route' => 'app_users', 'role' => 'ROLE_ADMIN']],
        ]]]);

        self::assertCount(1, $menu['items'], 'A group left with only headings disappears.');
        self::assertSame(
            ['Settings', 'General settings', 'Operations', 'Scheduled tasks'],
            array_map(static fn (array $child): string => $child['heading'] ?? $child['label'], $menu['items'][0]['children']),
            'The People heading lost its only link, and the last heading has none.',
        );
    }

    public function testQuickSearchListsLinksButNotHeadings(): void
    {
        $extension = $this->extension();
        $menu = $extension->menu(['items' => [[
            'label' => 'Administration',
            'children' => [['heading' => 'Settings'], ['label' => 'General settings', 'route' => 'app_settings']],
        ]]]);
        $titles = array_column($extension->quickSearchItems($menu), 'title');

        self::assertContains('General settings', $titles);
        self::assertNotContains('Settings', $titles);
    }

    /** @param list<string> $denied roles the signed-in user does not have */
    private function extension(array $denied = []): NavigationExtension
    {
        $routes = new RouteCollection();
        foreach (['app_home' => '/', 'app_settings' => '/dashboard/settings', 'app_users' => '/dashboard/users', 'app_tasks' => '/dashboard/scheduled-tasks'] as $name => $path) {
            $routes->add($name, new Route($path));
        }
        $features = $this->createStub(FeatureFlags::class);
        $features->method('isEnabled')->willReturn(true);
        $features->method('isHiddenFromNavigation')->willReturn(false);
        $authorization = $this->createStub(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willReturnCallback(static fn (mixed $attribute): bool => !in_array($attribute, $denied, true));

        return new NavigationExtension(new FeatureFlagsExtension($features), $authorization, new UrlGenerator($routes, new RequestContext()));
    }
}
