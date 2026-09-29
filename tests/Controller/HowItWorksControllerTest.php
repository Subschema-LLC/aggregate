<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HowItWorksControllerTest extends WebTestCase
{
    public function testBrandingTwigGlobalIsRegisteredAndComplete(): void
    {
        self::createClient();

        $globals = self::getContainer()->get('twig')->getGlobals();
        self::assertArrayHasKey('app_branding', $globals);
        self::assertIsArray($globals['app_branding']);
        self::assertNotSame('', $globals['app_branding']['name'] ?? '');
        self::assertMatchesRegularExpression(
            '/^#[0-9A-F]{6}$/D',
            $globals['app_branding']['theme']['primary_color'] ?? '',
        );
        self::assertNotSame('', $globals['app_branding']['theme']['font_family_css'] ?? '');
    }

    public function testOverviewPageIsPubliclyAvailable(): void
    {
        $client = self::createClient();

        $client->request('GET', '/how-it-works');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'How Aggregate Analytics Works');
        self::assertSelectorTextContains('body', 'Anonymous mode');
        self::assertSelectorTextContains('body', 'Enhanced analytics');
        self::assertSelectorTextContains('body', 'Strict Collection Profile');
        self::assertSelectorTextContains('body', 'collection_profile: strict');
        self::assertSelectorTextContains('body', 'config/goals.yaml');
        self::assertSelectorTextContains('body', 'Drops a disallowed goal without dropping the event');
        self::assertSelectorExists('a[href="/how-it-works/data-visualization"]');
        self::assertSelectorExists('a[href="/"]');
        self::assertDoesNotMatchRegularExpression(
            '/--app-brand-[a-z-]+:\s*;/',
            (string) $client->getResponse()->getContent(),
        );
    }

    public function testUiImportMapServesItsRuntimeDependencies(): void
    {
        // Source assets use Symfony's debug responder; BrowserKit has no static-file web server.
        $client = self::createClient(['debug' => true]);
        $crawler = $client->request('GET', '/how-it-works');

        self::assertResponseIsSuccessful();
        $importMap = json_decode($crawler->filter('script[type="importmap"]')->text(), true, flags: JSON_THROW_ON_ERROR);
        foreach (['app', '@symfony/stimulus-bundle', '@hotwired/stimulus'] as $module) {
            self::assertArrayHasKey($module, $importMap['imports']);
            $url = $importMap['imports'][$module];
            self::assertStringStartsWith('/assets/', $url);

            $client->request('GET', $url);

            self::assertResponseIsSuccessful();
            self::assertStringContainsString('javascript', (string) $client->getResponse()->headers->get('Content-Type'));
            self::assertNotEmpty($client->getInternalResponse()->getContent());
        }
    }

    public function testThemeCssPreservesHeadingAndCodeContrastAcrossBulmaContexts(): void
    {
        // Exercise source CSS serving explicitly even when CI sets APP_DEBUG=0.
        $client = self::createClient(['debug' => true]);

        $crawler = $client->request('GET', '/how-it-works');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('style, [style]');
        self::assertSelectorExists('link[rel="stylesheet"][href="/branding/theme.css"]');
        self::assertSelectorExists('html[data-theme="light"][data-theme-default="light"]');
        self::assertCount(1, $crawler->filter('link[rel="stylesheet"][href*="/styles/app-"]'));
        self::assertSelectorExists('link[rel="stylesheet"][href*="/styles/vendor/bulma/bulma.min-"]');
        self::assertSelectorExists('.app-theme-toggle[hidden] button[aria-pressed="false"]');
        $preferenceScript = $crawler->filter('script[src*="/theme-preference-"]');
        self::assertCount(1, $preferenceScript);
        self::assertNull($preferenceScript->attr('async'));
        self::assertNull($preferenceScript->attr('defer'));
        $client->request('GET', $preferenceScript->attr('src'));
        self::assertResponseIsSuccessful();
        $themePath = self::getContainer()->get('asset_mapper')->getPublicPath('styles/base.css');
        $client->request('GET', $crawler->filter('link[rel="stylesheet"][href*="/styles/app-"]')->attr('href'));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(basename($themePath), $client->getInternalResponse()->getContent());
        $client->request('GET', $themePath);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('text/css', $client->getResponse()->headers->get('Content-Type'));
        $content = $client->getInternalResponse()->getContent();

        self::assertStringContainsString(
            '--bulma-text-strong: var(--app-brand-text);',
            $content,
        );
        self::assertMatchesRegularExpression(
            '/\.content h1,\s*'
            .'\.content h2,\s*'
            .'\.content h3,\s*'
            .'\.content h4,\s*'
            .'\.content h5,\s*'
            .'\.content h6\s*'
            .'\{[^}]*color:\s*var\(--app-brand-text\);[^}]*\}/s',
            $content,
        );
        self::assertMatchesRegularExpression(
            '/\ncode\s*'
            .'\{'
            .'(?=[^}]*background-color:\s*#000000;)'
            .'(?=[^}]*color:\s*#FFFFFF;)'
            .'[^}]*\}/s',
            $content,
        );
        self::assertMatchesRegularExpression(
            '/pre,\s*'
            .'\.content pre,\s*'
            .'\.notification pre,\s*'
            .'\.message-body pre\s*'
            .'\{'
            .'(?=[^}]*background(?:-color)?:\s*#000000;)'
            .'(?=[^}]*color:\s*#FFFFFF;)'
            .'[^}]*\}/s',
            $content,
        );
        self::assertMatchesRegularExpression(
            '/pre code,\s*'
            .'\.content pre code,\s*'
            .'\.notification pre code,\s*'
            .'\.message-body pre code\s*'
            .'\{'
            .'(?=[^}]*background(?:-color)?:\s*transparent;)'
            .'(?=[^}]*border-radius:\s*0;)'
            .'(?=[^}]*color:\s*inherit;)'
            .'(?=[^}]*padding:\s*0;)'
            .'[^}]*\}/s',
            $content,
        );
        self::assertMatchesRegularExpression(
            '/\.notification code,\s*'
            .'\.message code\s*'
            .'\{'
            .'(?=[^}]*background-color:\s*#000000;)'
            .'(?=[^}]*color:\s*#FFFFFF;)'
            .'[^}]*\}/s',
            $content,
        );
    }

    public function testDataVisualizationStructurePageIsPubliclyAvailable(): void
    {
        $client = self::createClient();

        $client->request('GET', '/how-it-works/data-visualization');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Data Structure for Visualization');
        self::assertSelectorTextContains('body', 'bi_anonymous_events_v1');
        self::assertSelectorTextContains('body', 'bi_anonymous_goals_v1');
        self::assertSelectorTextContains('body', 'bi_anonymous_geo_events_v1');
        self::assertSelectorExists('#anonymous-events-view table');
        self::assertSelectorTextContains('#anonymous-events-view table', 'event_hour');
        self::assertSelectorTextContains('#anonymous-events-view table', 'event_count');
        self::assertSelectorExists('#anonymous-goals-view table');
        self::assertSelectorTextContains('#anonymous-goals-view table', 'event_day');
        self::assertSelectorTextContains('#anonymous-goals-view table', 'goal_event');
        self::assertSelectorTextContains('#anonymous-goals-view', 'not distinct people or unique converters');
        self::assertSelectorExists('#anonymous-geography-view table');
        self::assertSelectorTextContains('#anonymous-geography-view table', 'event_day');
        self::assertSelectorExists('#private-source-tables table');
        self::assertSelectorTextContains('#private-source-tables', 'not the routine BI contract');
        self::assertSelectorExists('a[href="/how-it-works"]');
    }

    public function testEnvironmentBrandingAndThemeAreSafelyShownOnPublicPages(): void
    {
        $overrides = [
            'BRAND_NAME' => '<Example & Company>',
            'BRAND_LOGO_TEXT' => 'Example <Insights>',
            'BRAND_PRIMARY_COLOR' => '#abc',
            'BRAND_BACKGROUND_COLOR' => '#111827',
            'BRAND_SURFACE_COLOR' => '#1F2937',
            'BRAND_TEXT_COLOR' => '#F9FAFB',
            'BRAND_FONT_FAMILY' => 'Open Sans, serif',
            'BRAND_HEADING_FONT_FAMILY' => 'Georgia, serif',
        ];
        $savedEnvironment = [];
        foreach ($overrides as $key => $value) {
            $savedEnvironment[$key] = [
                'exists' => array_key_exists($key, $_ENV),
                'value' => $_ENV[$key] ?? null,
            ];
            $_ENV[$key] = $value;
        }

        try {
            $client = self::createClient();

            $client->request('GET', '/how-it-works');

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'How <Example & Company> Works');
            self::assertSelectorTextSame(
                'nav[aria-label="public navigation"] strong',
                'Example <Insights>',
            );
            self::assertStringContainsString(
                '&lt;Example &amp; Company&gt;',
                (string) $client->getResponse()->getContent(),
            );
            self::assertStringNotContainsString(
                '<Example & Company>',
                (string) $client->getResponse()->getContent(),
            );
            self::assertSelectorExists('link[rel="stylesheet"][href="/branding/theme.css"]');
            $client->request('GET', '/branding/theme.css');
            self::assertResponseIsSuccessful();
            self::assertStringContainsString(
                '--app-brand-primary: #AABBCC;',
                (string) $client->getResponse()->getContent(),
            );
            self::assertStringContainsString(
                '--app-brand-background: #111827;',
                (string) $client->getResponse()->getContent(),
            );
            self::assertStringContainsString(
                '--app-brand-font-family: "Open Sans", serif;',
                (string) $client->getResponse()->getContent(),
            );
            self::assertStringContainsString(
                '--app-brand-heading-font-family: "Georgia", serif;',
                (string) $client->getResponse()->getContent(),
            );
        } finally {
            self::ensureKernelShutdown();
            foreach ($savedEnvironment as $key => $saved) {
                if ($saved['exists']) {
                    $_ENV[$key] = $saved['value'];
                } else {
                    unset($_ENV[$key]);
                }
            }
        }
    }
}
