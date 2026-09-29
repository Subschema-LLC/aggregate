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
}
