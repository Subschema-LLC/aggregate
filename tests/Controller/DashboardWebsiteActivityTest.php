<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\DashboardController;
use App\Entity\User;
use App\Service\AggregateConfigLoader;
use App\Service\DocumentationLinks;
use App\Service\DropInScripts;
use App\Service\TrackerBuilds;
use App\Service\TrackerScript;
use App\Service\WebsiteActivityService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class DashboardWebsiteActivityTest extends KernelTestCase
{
    public function testDashboardRendersWebsiteActivityBadge(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $request = Request::create('/dashboard');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $container->get('request_stack')->push($request);

        $admin = (new User())->setUsername('admin')->setRoles(['ROLE_ADMIN'])->setPassword('unused');
        $container->get('security.token_storage')->setToken(new UsernamePasswordToken($admin, 'main', ['ROLE_ADMIN']));

        $controller = $container->get(DashboardController::class);
        $scripts = $container->get(DropInScripts::class);
        $activityService = $container->get(WebsiteActivityService::class);

        $response = $controller->index($request, $scripts, $activityService);
        self::assertSame(200, $response->getStatusCode());

        $crawler = new Crawler((string) $response->getContent());
        $badges = $crawler->filter('.website-activity-badge');
        self::assertGreaterThanOrEqual(1, $badges->count());

        $pulseDot = $crawler->filter('.activity-pulse-dot');
        self::assertGreaterThanOrEqual(1, $pulseDot->count());
    }

    public function testSettingsPageRendersActivityThresholdFields(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $request = Request::create('/dashboard/settings');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $container->get('request_stack')->push($request);

        $admin = (new User())->setUsername('admin')->setRoles(['ROLE_ADMIN'])->setPassword('unused');
        $container->get('security.token_storage')->setToken(new UsernamePasswordToken($admin, 'main', ['ROLE_ADMIN']));

        $controller = $container->get(DashboardController::class);
        $docs = $container->get(DocumentationLinks::class);
        $builds = $container->get(TrackerBuilds::class);
        $tracker = $container->get(TrackerScript::class);

        $response = $controller->applicationSettings($docs, $builds, $tracker);
        self::assertSame(200, $response->getStatusCode());

        $crawler = new Crawler((string) $response->getContent());
        self::assertCount(1, $crawler->filter('input[name="website_activity_active_days"]'));
        self::assertCount(1, $crawler->filter('input[name="website_activity_stale_days"]'));
    }
}
