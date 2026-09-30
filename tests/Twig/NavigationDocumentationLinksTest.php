<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Service\FeatureFlags;
use App\Tests\Support\FixedDocumentationLinks;
use App\Twig\FeatureFlagsExtension;
use App\Twig\NavigationExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class NavigationDocumentationLinksTest extends TestCase
{
    public function testDocumentationTopicsBecomeExternalLinks(): void
    {
        $menu = $this->extension('https://docs.example.test/analytics/')->menu($this->navigation());

        self::assertSame(['label' => 'Help', 'icon' => null, 'enabled' => true, 'children' => [
            ['label' => 'How it works', 'icon' => null, 'enabled' => true, 'url' => '/how-it-works', 'external' => false, 'logout' => false],
            ['label' => 'Documentation', 'icon' => 'fas fa-book', 'enabled' => true, 'url' => 'https://docs.example.test/analytics/', 'external' => true, 'logout' => false],
            ['label' => 'Update guide', 'icon' => null, 'enabled' => true, 'url' => 'https://docs.example.test/analytics/operate/updates', 'external' => true, 'logout' => false],
        ]], $menu['items'][0]);
    }

    public function testDocumentationEntriesDisappearWhenLinksAreOff(): void
    {
        $menu = $this->extension('')->menu($this->navigation());

        self::assertSame(['How it works'], array_column($menu['items'][0]['children'], 'label'));
        // Without the service (for example, older wiring), documentation entries are hidden too.
        $menu = $this->extension(null)->menu($this->navigation());
        self::assertSame(['How it works'], array_column($menu['items'][0]['children'], 'label'));
    }

    public function testInvalidDocumentationEntriesAreOmitted(): void
    {
        $menu = $this->extension('https://docs.example.test/')->menu(['items' => [
            ['label' => 'Unknown topic', 'docs' => 'no.such.topic'],
            ['label' => 'Not a topic', 'docs' => ['home']],
            ['label' => 'Two destinations', 'docs' => 'home', 'url' => '/elsewhere'],
            ['label' => 'Route and topic', 'docs' => 'home', 'route' => 'app_how_it_works'],
            ['label' => 'Group with a topic', 'docs' => 'home', 'children' => [['label' => 'Child', 'route' => 'app_how_it_works']]],
            ['label' => 'Valid', 'docs' => 'tracking.setup'],
        ]]);

        self::assertSame(['Valid'], array_column($menu['items'], 'label'));
        self::assertSame('https://docs.example.test/tracking/setup', $menu['items'][0]['url']);
    }

    private function extension(?string $documentationUrl): NavigationExtension
    {
        $routes = new RouteCollection();
        $routes->add('app_how_it_works', new Route('/how-it-works'));
        $features = $this->createStub(FeatureFlags::class);
        $features->method('isEnabled')->willReturn(true);
        $features->method('isHiddenFromNavigation')->willReturn(false);

        return new NavigationExtension(
            new FeatureFlagsExtension($features),
            $this->createStub(AuthorizationCheckerInterface::class),
            new UrlGenerator($routes, new RequestContext()),
            documentation: $documentationUrl === null ? null : FixedDocumentationLinks::create($documentationUrl),
        );
    }

    private function navigation(): array
    {
        return ['items' => [[
            'label' => 'Help',
            'children' => [
                ['label' => 'How it works', 'route' => 'app_how_it_works'],
                ['label' => 'Documentation', 'docs' => 'home', 'icon' => 'fas fa-book'],
                ['label' => 'Update guide', 'docs' => 'updates'],
            ],
        ]]];
    }
}
