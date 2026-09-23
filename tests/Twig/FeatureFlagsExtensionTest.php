<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Service\AggregateConfigLoader;
use App\Service\FeatureFlags;
use App\Twig\FeatureFlagsExtension;
use App\Twig\NavigationExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Yaml\Yaml;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class FeatureFlagsExtensionTest extends TestCase
{
    private string $projectDir;
    private AggregateConfigLoader $config;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/aggregate-navigation-flags-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->projectDir.'/config', 0700, true));
        $this->config = new AggregateConfigLoader($this->projectDir, 'test');
    }

    protected function tearDown(): void
    {
        if (is_file($this->projectDir.'/config/aggregate.yaml')) {
            unlink($this->projectDir.'/config/aggregate.yaml');
        }
        rmdir($this->projectDir.'/config');
        rmdir($this->projectDir);
    }

    #[DataProvider('featureStates')]
    public function testFeatureVisibilityAndAvailabilityAreIndependent(bool $enabled, bool $hidden): void
    {
        $this->writeFlags($enabled, $hidden);
        $navigation = $this->navigation();
        $crawler = $this->render($this->twig(), $navigation);

        $this->assertFeatureEntry($crawler, 'Updates', '/dashboard/updates', $enabled, $hidden);
        self::assertSame('Customer Analytics', $crawler->filter('.app-branding-identity strong')->text());
        self::assertCount(1, $crawler->filter('a[href="/documentation"]'));
        self::assertCount(1, $crawler->filter('a[href="/dashboard/feature-flags"]'));
        self::assertCount(1, $crawler->filter('a[href="/logout?_csrf_token=logout-test-token"]'));
        self::assertSame(!$enabled && !$hidden, str_contains($crawler->text(), '(disabled)'));
    }

    public static function featureStates(): iterable
    {
        yield 'enabled and visible' => [true, false];
        yield 'enabled and hidden' => [true, true];
        yield 'disabled and visible' => [false, false];
        yield 'disabled and hidden' => [false, true];
    }

    #[DataProvider('legacyAndCustomLinks')]
    public function testLegacyAndExplicitlyTaggedCustomLinksRespectFeatureSettings(array $link, bool $hidden): void
    {
        $this->writeFlags(false, $hidden);
        $navigation = $this->navigation();
        $navigation['items'] = [['label' => 'Custom destination', ...$link]];
        $crawler = $this->render($this->twig(), $navigation);

        self::assertSame($hidden ? 0 : 1, $crawler->filter('[role="link"][aria-disabled="true"]')->count());
        self::assertSame(!$hidden, str_contains($crawler->text(), 'Custom destination'));
        self::assertCount(2, $crawler->filter('a[href]'), 'Only the unrelated brand and logout links remain clickable.');
    }

    public static function legacyAndCustomLinks(): iterable
    {
        $links = [
            'Updates route' => ['route' => 'app_updates'],
            'refresh route' => ['route' => 'app_updates_refresh'],
            'local URL' => ['url' => '/dashboard/updates'],
            'local URL query and fragment' => ['url' => '/dashboard/updates?refresh=1#version'],
            'local URL trailing slash' => ['url' => '/dashboard/updates/'],
            'local refresh URL' => ['url' => '/dashboard/updates/refresh?refresh=1'],
            'tagged external URL' => ['url' => 'https://example.test/releases', 'feature' => 'updates'],
            'tagged unknown route' => ['route' => 'custom_route_not_installed', 'feature' => 'updates'],
        ];
        foreach ($links as $label => $link) {
            foreach ([false, true] as $hidden) {
                yield $label.($hidden ? ' hidden' : ' disabled') => [$link, $hidden];
            }
        }
    }

    #[DataProvider('featureStates')]
    public function testBrandAndAccountLinksRespectFeaturesWithoutReplacingBranding(bool $enabled, bool $hidden): void
    {
        $this->writeFlags($enabled, $hidden);
        $navigation = $this->navigation();
        $navigation['brand'] = ['label' => 'Legacy vendor label', 'route' => 'app_updates'];
        $navigation['account']['logout'] = ['label' => 'Account destination', 'url' => '/dashboard/updates#account'];
        $crawler = $this->render($this->twig(), $navigation);

        $this->assertFeatureEntry($crawler, 'Customer Analytics', '/dashboard/updates', $enabled, $hidden);
        $this->assertFeatureEntry($crawler, 'Account destination', '/dashboard/updates#account', $enabled, $hidden);
        self::assertStringNotContainsString('Legacy vendor label', $crawler->text());
        self::assertStringContainsString('operator', $crawler->text());
    }

    #[DataProvider('featureStates')]
    public function testRoleRestrictionsStillApplyToEveryNavigationPosition(bool $enabled, bool $hidden): void
    {
        $this->writeFlags($enabled, $hidden);
        $navigation = $this->navigation();
        $navigation['brand']['role'] = 'ROLE_ADMIN';
        $navigation['account']['logout']['role'] = 'ROLE_ADMIN';
        $crawler = $this->render($this->twig(admin: false), $navigation);

        self::assertStringNotContainsString('Updates', $crawler->text());
        self::assertStringNotContainsString('Feature flags', $crawler->text());
        self::assertStringNotContainsString('Customer Analytics', $crawler->text());
        self::assertStringNotContainsString('Logout', $crawler->text());
        self::assertCount(1, $crawler->filter('a[href="/documentation"]'));
        self::assertCount(0, $crawler->filter('[aria-disabled]'));
    }

    #[DataProvider('invalidFlags')]
    public function testInvalidFeatureConfigurationHidesAffectedLinks(mixed $flags): void
    {
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump(['feature_flags' => $flags], 5));
        $crawler = $this->render($this->twig(), $this->navigation());

        self::assertStringNotContainsString('Updates', $crawler->text());
        self::assertCount(1, $crawler->filter('a[href="/dashboard/feature-flags"]'));
        self::assertCount(1, $crawler->filter('a[href="/documentation"]'));
    }

    public static function invalidFlags(): iterable
    {
        yield 'null mapping' => [null];
        yield 'string mapping' => ['invalid'];
        yield 'list mapping' => [['updates']];
        yield 'null enabled' => [['updates' => ['enabled' => null]]];
        yield 'array enabled' => [['updates' => ['enabled' => []]]];
        yield 'null hidden' => [['updates' => ['hide_from_navigation' => null]]];
        yield 'array hidden' => [['updates' => ['hide_from_navigation' => []]]];
    }

    public function testMalformedYamlDoesNotRevealUpdatesOrPreventOrdinaryNavigation(): void
    {
        file_put_contents($this->projectDir.'/config/aggregate.yaml', 'feature_flags: [');
        $crawler = $this->render($this->twig(), $this->navigation());

        self::assertStringNotContainsString('Updates', $crawler->text());
        self::assertCount(1, $crawler->filter('a[href="/dashboard/feature-flags"]'));
        self::assertCount(1, $crawler->filter('a[href="/logout?_csrf_token=logout-test-token"]'));
    }

    public function testUnknownAndInvalidNavigationFeatureTagsFailClosed(): void
    {
        $navigation = $this->navigation();
        $navigation['items'] = [];
        foreach (['unknown_feature', '', null, ['updates']] as $index => $feature) {
            $navigation['items'][] = ['label' => 'Unknown '.$index, 'url' => '/custom/'.$index, 'feature' => $feature];
        }
        $crawler = $this->render($this->twig(), $navigation);

        self::assertStringNotContainsString('Unknown', $crawler->text());
        self::assertCount(2, $crawler->filter('a[href]'));
    }

    public function testSimilarAndExternalUrlsDoNotAcquireAnUnrelatedFeatureRestriction(): void
    {
        $this->writeFlags(false, true);
        $navigation = $this->navigation();
        $navigation['items'] = [];
        foreach ([
            'https://example.test/dashboard/updates',
            '//example.test/dashboard/updates',
            '/dashboard/updates-guide',
            '/documentation?next=/dashboard/updates',
        ] as $index => $url) {
            $navigation['items'][] = ['label' => 'Unrelated '.$index, 'url' => $url];
        }
        $crawler = $this->render($this->twig(), $navigation);

        self::assertCount(6, $crawler->filter('a[href]'));
        self::assertCount(0, $crawler->filter('[aria-disabled]'));
    }

    public function testRepeatedTwigRenderingReadsNewFeatureValuesAfterConfigurationReset(): void
    {
        $this->writeFlags(true, false);
        $twig = $this->twig();
        $helpers = $twig->createTemplate('{{ feature_enabled("updates") ? "enabled" : "disabled" }}:{{ feature_hidden_from_navigation("updates") ? "hidden" : "visible" }}');
        self::assertCount(1, $this->render($twig, $this->navigation())->filter('a[href="/dashboard/updates"]'));
        self::assertSame('enabled:visible', $helpers->render());

        $this->writeFlags(false, true);
        $this->config->reset();
        self::assertStringNotContainsString('Updates', $this->render($twig, $this->navigation())->text());
        self::assertSame('disabled:hidden', $helpers->render());

        $this->writeFlags(false, false);
        $this->config->reset();
        self::assertCount(1, $this->render($twig, $this->navigation())->filter('[role="link"][aria-disabled="true"]'));
        self::assertSame('disabled:visible', $helpers->render());
    }

    #[DataProvider('logoutDestinations')]
    public function testLogoutProtectionPreservesCustomNavigationDestinations(array $destination, string $expectedUrl): void
    {
        $navigation = $this->navigation();
        $navigation['account']['logout'] = ['label' => 'Leave console', 'icon' => 'custom-logout-icon', ...$destination];
        $crawler = $this->render($this->twig(), $navigation);
        $link = $crawler->selectLink('Leave console');

        self::assertSame($expectedUrl, $link->attr('href'));
        self::assertCount(1, $link->filter('i.custom-logout-icon'));
    }

    public static function logoutDestinations(): iterable
    {
        yield 'application route' => [['route' => 'app_logout'], '/logout?_csrf_token=logout-test-token'];
        yield 'legacy local URL' => [['url' => '/logout'], '/logout?_csrf_token=logout-test-token'];
        yield 'legacy local URL with query' => [['url' => '/logout?_csrf_token=untrusted#account'], '/logout?_csrf_token=logout-test-token'];
        yield 'custom external URL' => [['url' => 'https://accounts.example.test/logout'], 'https://accounts.example.test/logout'];
        yield 'custom local destination' => [['url' => '/account/leave'], '/account/leave'];
        yield 'custom route' => [['route' => 'app_home'], '/'];
    }

    public function testGroupsUseNativeDisclosureAndRetainVisibleAuthorizedChildren(): void
    {
        $this->writeFlags(false, true);
        $navigation = $this->navigation();
        $navigation['items'] = [[
            'label' => 'Administration',
            'icon' => 'fas fa-gear',
            'children' => [
                ['label' => 'Updates', 'route' => 'app_updates'],
                ['label' => 'Feature flags', 'route' => 'app_feature_flags', 'role' => 'ROLE_ADMIN'],
                ['label' => 'Documentation', 'url' => '/documentation'],
            ],
        ]];
        $crawler = $this->render($this->twig(admin: false), $navigation);

        self::assertCount(1, $crawler->filter('details.app-navigation-group > summary'));
        self::assertSame('Administration', trim($crawler->filter('summary')->text()));
        self::assertCount(1, $crawler->filter('summary i.fas.fa-gear'));
        self::assertCount(1, $crawler->filter('details a[href="/documentation"]'));
        self::assertStringNotContainsString('Feature flags', $crawler->text());
        self::assertStringNotContainsString('Updates', $crawler->text());
        self::assertCount(0, $crawler->filter('summary a, [role="menu"], [role="menuitem"]'));
        self::assertCount(1, $crawler->filter('.app-navigation-account a[href="/logout?_csrf_token=logout-test-token"]'));
    }

    public function testEmptyInaccessibleAndHiddenGroupsAreRemoved(): void
    {
        $this->writeFlags(true, true);
        $navigation = $this->navigation();
        $navigation['items'] = [
            ['label' => 'Empty', 'children' => []],
            ['label' => 'Admin group', 'role' => 'ROLE_ADMIN', 'children' => [['label' => 'Guide', 'url' => '/guide']]],
            ['label' => 'Admin children', 'children' => [['label' => 'Restricted', 'role' => 'ROLE_ADMIN', 'url' => '/guide']]],
            ['label' => 'Hidden group', 'feature' => 'updates', 'children' => [['label' => 'Guide', 'url' => '/guide']]],
            ['label' => 'Hidden children', 'children' => [['label' => 'Updates', 'route' => 'app_updates']]],
        ];
        $crawler = $this->render($this->twig(admin: false), $navigation);

        self::assertCount(0, $crawler->filter('details, summary'));
        self::assertCount(2, $crawler->filter('a[href]'));
    }

    public function testDisabledGroupMakesAllChildrenUnclickableWithoutHidingOtherGroups(): void
    {
        $this->writeFlags(false, false);
        $navigation = $this->navigation();
        $navigation['items'] = [
            [
                'label' => 'Release tools',
                'feature' => 'updates',
                'children' => [
                    ['label' => 'Updates', 'route' => 'app_updates'],
                    ['label' => 'Release notes', 'url' => 'https://example.test/releases'],
                ],
            ],
            ['label' => 'Help', 'children' => [['label' => 'Guide', 'url' => '/guide']]],
        ];
        $crawler = $this->render($this->twig(), $navigation);

        self::assertCount(2, $crawler->filter('details'));
        self::assertCount(2, $crawler->filter('details')->eq(0)->filter('span[role="link"][aria-disabled="true"]'));
        self::assertCount(0, $crawler->filter('details')->eq(0)->filter('a[href], [aria-disabled="true"][href]'));
        self::assertCount(1, $crawler->filter('details')->eq(1)->filter('a[href="/guide"]'));
    }

    #[DataProvider('unsafeUrls')]
    public function testUnsafeUrlsAreRejectedInEveryNavigationPosition(mixed $url): void
    {
        $link = ['label' => 'Unsafe destination', 'url' => $url];
        $navigation = [
            'brand' => $link,
            'items' => [$link, ['label' => 'Unsafe children', 'children' => [$link]]],
            'account' => ['logout' => $link],
        ];
        $crawler = $this->render($this->twig(), $navigation);

        self::assertCount(0, $crawler->filter('a[href], details'));
        self::assertStringNotContainsString('Unsafe', $crawler->text());
        self::assertStringContainsString('operator', $crawler->text());
    }

    public static function unsafeUrls(): iterable
    {
        foreach ([
            'javascript:alert(1)', 'JaVaScRiPt:alert(1)', "java\nscript:alert(1)",
            'data:text/html,test', 'vbscript:alert(1)', 'file:///tmp/test',
            "\tjavascript:alert(1)", ' https://example.test', '/\\example.test',
            "//example.test\t/path", 'https:example.test', 'http:///example.test',
            'https://operator:password@example.test', '///example.test', '',
            '/'.str_repeat('a', 2048), false, null, [],
        ] as $index => $url) {
            yield 'URL '.$index => [$url];
        }
    }

    public function testSafeLiteralUrlsAndRouteParametersRemainSupportedAndLabelsAreEscaped(): void
    {
        $navigation = $this->navigation();
        $navigation['items'] = [];
        foreach (['/local?x=1&y=2#section', 'relative/path', '#section', '?query=1', 'https://example.test', '//example.test/path'] as $url) {
            $navigation['items'][] = ['label' => '<script>example</script>', 'url' => $url];
        }
        $navigation['items'][] = [
            'label' => 'Parameterized route', 'route' => 'app_parameterized', 'route_parameters' => ['id' => 'example'],
        ];
        $crawler = $this->render($this->twig(), $navigation);

        self::assertCount(9, $crawler->filter('a[href]'));
        self::assertCount(0, $crawler->filter('script'));
        self::assertCount(1, $crawler->filter('a[href="/custom/example"]'));
        self::assertCount(1, $crawler->filter('a[href="/local?x=1&y=2#section"]'));
        self::assertStringContainsString('<script>example</script>', $crawler->text());
    }

    public function testMalformedEntriesNestedGroupsAndUnknownRoutesDoNotBreakOtherLinks(): void
    {
        $navigation = $this->navigation();
        $invalid = [
            null, false, 'invalid', [],
            ['label' => ['array'], 'url' => '/guide'],
            ['label' => 'Missing destination'],
            ['label' => 'Two destinations', 'route' => 'app_home', 'url' => '/guide'],
            ['label' => 'Unknown route', 'route' => 'not_installed'],
            ['label' => 'Missing parameters', 'route' => 'app_parameterized'],
            ['label' => 'Invalid parameters', 'route' => 'app_home', 'route_parameters' => ['id' => ['nested']]],
            ['label' => 'Invalid role', 'role' => [], 'url' => '/guide'],
            ['label' => 'Null role', 'role' => null, 'url' => '/guide'],
            ['label' => 'Invalid group', 'children' => 'invalid'],
            ['label' => 'Linked group', 'route' => 'app_home', 'children' => [['label' => 'Guide', 'url' => '/guide']]],
            ['label' => 'Deep group', 'children' => [['label' => 'Nested', 'children' => [['label' => 'Guide', 'url' => '/guide']]]]],
        ];
        $navigation['items'] = [...$invalid, ['label' => 'Kept', 'children' => [
            ['label' => 'Nested', 'children' => [['label' => 'Ignored', 'url' => '/ignored']]],
            ['label' => 'Guide', 'url' => '/guide'],
        ]]];
        $crawler = $this->render($this->twig(), $navigation);

        self::assertCount(1, $crawler->filter('details'));
        self::assertSame('Kept', trim($crawler->filter('summary')->text()));
        self::assertCount(1, $crawler->filter('details a[href="/guide"]'));
        self::assertCount(0, $crawler->filter('details details'));
        self::assertCount(3, $crawler->filter('a[href]'));
    }

    public function testMalformedTopLevelSectionsStillAllowUnaffectedLinks(): void
    {
        $navigation = $this->navigation();
        $navigation['brand'] = 'invalid';
        $navigation['items'] = ['label' => 'Invalid mapping', 'url' => '/ignored'];
        $navigation['account'] = ['logout' => $navigation['account']['logout'], 'user_icon' => ['invalid']];
        $crawler = $this->render($this->twig(), $navigation);

        self::assertCount(1, $crawler->filter('a[href="/logout?_csrf_token=logout-test-token"]'));
        self::assertCount(1, $crawler->filter('a[href]'));
        self::assertCount(0, $crawler->filter('i'));
    }

    public function testNavigationSizeIsBoundedAtBothSupportedLevels(): void
    {
        $navigation = $this->navigation();
        $navigation['items'] = array_fill(0, 33, ['label' => 'Group', 'children' => array_fill(0, 33, [
            'label' => 'Guide', 'url' => '/guide',
        ])]);
        $crawler = $this->render($this->twig(), $navigation);

        self::assertCount(32, $crawler->filter('details'));
        self::assertCount(32, $crawler->filter('details')->eq(0)->filter('a[href]'));
        self::assertCount(32 * 32 + 2, $crawler->filter('a[href]'));
    }

    private function writeFlags(bool $enabled, bool $hidden): void
    {
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump([
            'feature_flags' => ['updates' => ['enabled' => $enabled, 'hide_from_navigation' => $hidden]],
        ], 5));
    }

    private function twig(bool $admin = true): Environment
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 2).'/templates'), ['strict_variables' => true]);
        $features = new FeatureFlagsExtension(new FeatureFlags($this->config));
        $twig->addExtension($features);
        $authorization = $this->createStub(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willReturnCallback(static fn (string $role): bool => $admin && $role === 'ROLE_ADMIN');
        $routes = new RouteCollection();
        foreach ([
            'app_home' => '/',
            'app_updates' => '/dashboard/updates',
            'app_updates_refresh' => '/dashboard/updates/refresh',
            'app_feature_flags' => '/dashboard/feature-flags',
            'app_logout' => '/logout',
            'app_parameterized' => '/custom/{id}',
        ] as $name => $path) {
            $routes->add($name, new Route($path));
        }
        $twig->addExtension(new NavigationExtension($features, $authorization, new UrlGenerator($routes, new RequestContext())));
        $twig->addFunction(new TwigFunction('is_granted', static fn (string $role): bool => $admin && $role === 'ROLE_ADMIN'));
        $twig->addFunction(new TwigFunction('path', static fn (string $route): string => match ($route) {
            'app_home' => '/',
            'app_updates' => '/dashboard/updates',
            'app_updates_refresh' => '/dashboard/updates/refresh',
            'app_feature_flags' => '/dashboard/feature-flags',
            'app_logout' => '/logout',
        }));
        $twig->addFunction(new TwigFunction('logout_path', static function (string $firewall): string {
            self::assertSame('main', $firewall);

            return '/logout?_csrf_token=logout-test-token';
        }));
        $twig->addGlobal('app_branding', [
            'name' => 'Customer Analytics',
            'logo_text' => 'Customer Analytics',
            'has_logo' => false,
            'logo_version' => null,
        ]);

        \App\Tests\Support\TwigComponents::register($twig);

        return $twig;
    }

    private function render(Environment $twig, array $navigation): Crawler
    {
        return new Crawler($twig->createTemplate("{{ component('Layout:Navigation', {navigation: navigation_menu(main_navigation), username: 'operator'}) }}")->render(['main_navigation' => $navigation]));
    }

    private function navigation(): array
    {
        return [
            'brand' => ['label' => 'Legacy vendor label', 'route' => 'app_home'],
            'items' => [
                ['label' => 'Documentation', 'url' => '/documentation'],
                ['label' => 'Updates', 'route' => 'app_updates', 'role' => 'ROLE_ADMIN', 'feature' => 'updates'],
                ['label' => 'Feature flags', 'route' => 'app_feature_flags', 'role' => 'ROLE_ADMIN'],
            ],
            'account' => ['logout' => ['label' => 'Logout', 'url' => '/logout']],
        ];
    }

    private function assertFeatureEntry(Crawler $crawler, string $label, string $url, bool $enabled, bool $hidden): void
    {
        $links = $crawler->filter('a[href]')->reduce(static fn (Crawler $node): bool => $node->attr('href') === $url && str_contains($node->text(), $label));
        self::assertCount($enabled && !$hidden ? 1 : 0, $links);
        $disabled = $crawler->filter('span[role="link"][aria-disabled="true"]')->reduce(static fn (Crawler $node): bool => str_contains($node->text(), $label));
        self::assertCount(!$enabled && !$hidden ? 1 : 0, $disabled);
        self::assertSame(!$hidden, str_contains($crawler->text(), $label));
        self::assertCount(0, $crawler->filter('[aria-disabled="true"][href]'));
    }
}
