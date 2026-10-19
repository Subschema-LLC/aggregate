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
        // An administrator, who can open the Websites page for every website.
        $auth->method('isGranted')->willReturn(true);
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

        $auth = $this->createMock(AuthorizationCheckerInterface::class);
        $auth->method('isGranted')->willReturn(true);
        $extension = new NavigationExtension(
            new FeatureFlagsExtension($this->createMock(\App\Service\FeatureFlags::class)),
            $auth,
            $urls,
            websiteManager: $websiteManager,
            activityService: $activityService
        );
        $items = $extension->quickSearchItems(['items' => []]);

        self::assertCount(1, $items);
        self::assertSame('Demo Store', $items[0]['title']);
        self::assertSame('none', $items[0]['status']);
    }

    public function testQuickSearchListsOnlyTheWebsitesAStandardUserCanUse(): void
    {
        $directory = sys_get_temp_dir().'/aggregate-quick-search-'.bin2hex(random_bytes(6));
        mkdir($directory.'/config', 0700, true);
        try {
            $urls = $this->createMock(UrlGeneratorInterface::class);
            $urls->method('generate')->willReturn('/dashboard');
            $websites = [
                ['name' => 'Demo Store', 'domain' => 'demo.example.com', 'token' => 'token-demo'],
                ['name' => 'Other Store', 'domain' => 'other.example.com', 'token' => 'token-other'],
            ];
            $websiteManager = $this->createMock(\App\Service\WebsiteConfigManager::class);
            $websiteManager->method('getWebsites')->willReturn($websites);
            $user = (new \App\Entity\User())->setUsername('client')->setRoles([])
                ->setAccess(['websites' => ['sites' => ['token-demo']], 'pages' => ['websites' => 'view']]);
            $security = $this->createMock(\Symfony\Bundle\SecurityBundle\Security::class);
            $security->method('getUser')->willReturn($user);
            $requests = new \Symfony\Component\HttpFoundation\RequestStack();
            $requests->push(new \Symfony\Component\HttpFoundation\Request());
            $resolver = new \App\Security\Access\UserAccessResolver(
                new \App\Security\Access\PermissionGroupCatalog($directory, 'test'),
                new \App\Service\WebsiteScopeCatalog($directory, 'test'),
            );
            $access = new \App\Security\Access\WebsiteAccess($security, $resolver, $requests, $websiteManager);
            $voter = new \App\Security\Access\DashboardAccessVoter($resolver);
            $token = new \Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken($user, 'main', []);
            $auth = $this->createMock(AuthorizationCheckerInterface::class);
            $auth->method('isGranted')->willReturnCallback(static fn (string $attribute, mixed $subject = null): bool => $voter->vote($token, $subject, [$attribute]) === 1);
            $features = new FeatureFlagsExtension($this->createMock(\App\Service\FeatureFlags::class));
            $navigation = static fn () => new NavigationExtension(
                $features,
                $auth,
                $urls,
                websiteManager: $websiteManager,
                websiteAccess: $access,
            );

            self::assertSame(['Demo Store'], array_column($navigation()->quickSearchItems(['items' => []]), 'title'));

            // Without the Websites page, quick search lists no websites at all.
            $user->setAccess(['websites' => ['all' => true], 'pages' => ['tag_manager' => 'edit']]);
            self::assertSame([], $navigation()->quickSearchItems(['items' => []]));
        } finally {
            (new \Symfony\Component\Filesystem\Filesystem())->remove($directory);
        }
    }
}
