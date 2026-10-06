<?php

declare(strict_types=1);

namespace App\Tests\Configuration;

use App\Twig\FeatureFlagsExtension;
use App\Twig\NavigationExtension;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Yaml\Yaml;

final class QuickSearchConfigTest extends KernelTestCase
{
    public function testQuickSearchYamlIsValidAndDefinesSynonyms(): void
    {
        $file = self::getContainer()->getParameter('kernel.project_dir') . '/config/quick_search.yaml';
        self::assertFileExists($file);

        $parsed = Yaml::parseFile($file);
        self::assertIsArray($parsed);
        self::assertArrayHasKey('parameters', $parsed);
        self::assertArrayHasKey('app.quick_search_synonyms', $parsed['parameters']);

        $synonyms = $parsed['parameters']['app.quick_search_synonyms'];
        self::assertIsArray($synonyms);
        self::assertArrayHasKey('Websites', $synonyms);
        self::assertArrayHasKey('Setup', $synonyms);
        self::assertArrayHasKey('Branding', $synonyms);

        self::assertIsArray($synonyms['Websites']['synonyms'] ?? null);
        self::assertContains('tokens', $synonyms['Websites']['synonyms']);
    }

    public function testQuickSearchItemsMergesNavigationAndSynonyms(): void
    {
        $features = new FeatureFlagsExtension($this->createMock(\App\Service\FeatureFlags::class));
        $auth = $this->createMock(AuthorizationCheckerInterface::class);
        $urls = $this->createMock(UrlGeneratorInterface::class);

        $synonyms = [
            'Websites' => [
                'description' => 'Custom website description',
                'synonyms' => ['sites', 'origins'],
            ],
        ];

        $extra = [
            [
                'title' => 'External Docs',
                'category' => 'Reference',
                'url' => 'https://docs.example.com',
                'icon' => 'fas fa-book',
                'description' => 'External guides',
                'synonyms' => ['help', 'manual'],
            ],
        ];

        $extension = new NavigationExtension($features, $auth, $urls, $synonyms, $extra);

        $navigation = [
            'items' => [
                [
                    'label' => 'Websites',
                    'url' => '/dashboard',
                    'icon' => 'fas fa-globe',
                    'enabled' => true,
                ],
            ],
        ];

        $items = $extension->quickSearchItems($navigation);

        self::assertCount(2, $items);

        self::assertSame('Websites', $items[0]['title']);
        self::assertSame('Custom website description', $items[0]['description']);
        self::assertSame('sites origins', $items[0]['keywords']);

        self::assertSame('External Docs', $items[1]['title']);
        self::assertSame('help manual', $items[1]['keywords']);
    }

    public function testQuickSearchItemsIncludesWebsitesWithStatus(): void
    {
        $features = new FeatureFlagsExtension($this->createMock(\App\Service\FeatureFlags::class));
        $auth = $this->createMock(AuthorizationCheckerInterface::class);
        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('/dashboard');

        $websiteManager = $this->createMock(\App\Service\WebsiteConfigManager::class);
        $websiteManager->method('getWebsites')->willReturn([
            ['name' => 'Demo Store', 'domain' => 'demo.example.com', 'token' => 'token-demo'],
        ]);

        $activityService = $this->createMock(\App\Service\WebsiteActivityService::class);
        // Quick search is on every page, so it never computes statuses itself.
        $activityService->expects(self::never())->method('getStatuses');
        $activityService->method('cachedStatuses')->willReturn([
            'token-demo' => [
                'token' => 'token-demo',
                'status' => 'active',
                'label' => 'Receiving data',
                'relative_time' => '15m ago',
            ],
        ]);

        $extension = new NavigationExtension(
            $features,
            $auth,
            $urls,
            websiteManager: $websiteManager,
            activityService: $activityService
        );

        $items = $extension->quickSearchItems(['items' => []]);

        self::assertCount(1, $items);
        self::assertSame('Demo Store', $items[0]['title']);
        self::assertSame('Websites', $items[0]['category']);
        self::assertSame('active', $items[0]['status']);
        self::assertSame('Receiving data', $items[0]['status_label']);
        self::assertSame('15m ago', $items[0]['status_time']);
        self::assertStringContainsString('demo.example.com', $items[0]['description']);
        self::assertStringContainsString('Receiving data', $items[0]['description']);
    }

    public function testQuickSearchListsWebsitesBeforeAnyStatusIsComputed(): void
    {
        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('/dashboard');
        $websiteManager = $this->createMock(\App\Service\WebsiteConfigManager::class);
        $websiteManager->method('getWebsites')->willReturn([
            ['name' => 'Demo Store', 'domain' => 'demo.example.com', 'token' => 'token-demo'],
        ]);
        $activityService = $this->createMock(\App\Service\WebsiteActivityService::class);
        $activityService->expects(self::never())->method('getStatuses');
        $activityService->method('cachedStatuses')->willReturn(null);

        $extension = new NavigationExtension(
            new FeatureFlagsExtension($this->createMock(\App\Service\FeatureFlags::class)),
            $this->createMock(AuthorizationCheckerInterface::class),
            $urls,
            websiteManager: $websiteManager,
            activityService: $activityService
        );
        $items = $extension->quickSearchItems(['items' => []]);

        self::assertCount(1, $items);
        self::assertSame('Demo Store', $items[0]['title']);
        self::assertSame('none', $items[0]['status']);
    }
}
