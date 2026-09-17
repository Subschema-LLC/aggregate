<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Service\AggregateConfigLoader;
use App\Service\FeatureFlags;
use App\Twig\FeatureFlagsExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DomCrawler\Crawler;
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
        self::assertCount(1, $crawler->filter('a[href="/logout"]'));
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
        self::assertCount(1, $crawler->filter('a[href="/logout"]'));
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

    private function writeFlags(bool $enabled, bool $hidden): void
    {
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump([
            'feature_flags' => ['updates' => ['enabled' => $enabled, 'hide_from_navigation' => $hidden]],
        ], 5));
    }

    private function twig(bool $admin = true): Environment
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 2).'/templates'), ['strict_variables' => true]);
        $twig->addExtension(new FeatureFlagsExtension(new FeatureFlags($this->config)));
        $twig->addFunction(new TwigFunction('is_granted', static fn (string $role): bool => $admin && $role === 'ROLE_ADMIN'));
        $twig->addFunction(new TwigFunction('path', static fn (string $route): string => match ($route) {
            'app_home' => '/',
            'app_updates' => '/dashboard/updates',
            'app_updates_refresh' => '/dashboard/updates/refresh',
            'app_feature_flags' => '/dashboard/feature-flags',
        }));
        $twig->addGlobal('app_branding', [
            'name' => 'Customer Analytics',
            'logo_text' => 'Customer Analytics',
            'has_logo' => false,
            'logo_version' => null,
        ]);

        return $twig;
    }

    private function render(Environment $twig, array $navigation): Crawler
    {
        return new Crawler($twig->render('navigation/main.html.twig', [
            'main_navigation' => $navigation,
            'app' => ['user' => ['username' => 'operator']],
        ]));
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
