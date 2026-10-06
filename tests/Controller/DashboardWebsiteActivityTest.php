<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\DashboardController;
use App\Entity\User;
use App\Service\DocumentationLinks;
use App\Service\DropInScripts;
use App\Service\TrackerBuilds;
use App\Service\TrackerScript;
use App\Service\WebsiteActivityService;
use App\Service\WebsiteConfigManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Yaml\Yaml;

/**
 * The Websites page shows each website's data reception status without
 * waiting on the events database: it renders a status already computed, or a
 * placeholder, and loads fresh statuses from app_website_activity.
 */
final class DashboardWebsiteActivityTest extends KernelTestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/aggregate-website-activity-'.bin2hex(random_bytes(6));
        mkdir($this->directory.'/config', 0777, true);
        file_put_contents($this->directory.'/config/websites.yaml', Yaml::dump([
            'websites' => [['name' => 'Demo Store', 'domain' => 'demo.example.com', 'token' => 'token-demo']],
        ]));
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        (new Filesystem())->remove($this->directory);
    }

    public function testDashboardRendersACachedStatusWithoutComputingOne(): void
    {
        $activity = $this->createMock(WebsiteActivityService::class);
        $activity->expects(self::never())->method('getStatuses');
        $activity->expects(self::never())->method('getStatusForToken');
        $activity->method('cachedStatusForToken')->willReturnMap([['token-demo', self::activityStatus('active', 'Receiving data', '15m ago')]]);

        $crawler = $this->websitesPage($activity);

        $badge = $crawler->filter('[data-website-activity-badge="token-demo"]');
        self::assertCount(1, $badge);
        self::assertStringContainsString('status-active', (string) $badge->attr('class'));
        self::assertCount(1, $badge->filter('.activity-pulse-dot'));
        self::assertStringContainsString('Receiving data', $badge->text());
        self::assertStringContainsString('Last event received: 15m ago', $crawler->filter('[data-website-activity-banner="token-demo"]')->text());
        self::assertSame('/dashboard/websites/activity', $crawler->filter('.websites-page')->attr('data-pages--websites--activity-url-value'));
    }

    public function testDashboardShowsAPlaceholderUntilAStatusIsLoaded(): void
    {
        $activity = $this->createMock(WebsiteActivityService::class);
        $activity->expects(self::never())->method('getStatuses');
        $activity->method('cachedStatusForToken')->willReturn(null);

        $crawler = $this->websitesPage($activity);

        $badge = $crawler->filter('[data-website-activity-badge="token-demo"]');
        self::assertStringContainsString('status-checking', (string) $badge->attr('class'));
        self::assertStringContainsString('Checking data reception', $badge->text());
        self::assertNotNull($crawler->filter('[data-website-activity-banner="token-demo"]')->attr('hidden'));
        self::assertStringContainsString('pages--websites--activity', (string) $crawler->filter('.websites-page')->attr('data-controller'));
    }

    public function testActivityEndpointRendersEachWebsitesBadgeAndBanner(): void
    {
        $activity = $this->createMock(WebsiteActivityService::class);
        $activity->method('getStatuses')->willReturn(['token-demo' => self::activityStatus('inactive', 'No recent data', '5d ago')]);

        $response = $this->controller()->websiteActivity($activity);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['token-demo'], array_keys($body['websites']));
        self::assertSame('inactive', $body['websites']['token-demo']['status']);
        $badge = new Crawler($body['websites']['token-demo']['badge']);
        self::assertSame('token-demo', $badge->filter('[data-website-activity-badge]')->attr('data-website-activity-badge'));
        self::assertStringContainsString('No recent data', $badge->text());
        $banner = new Crawler($body['websites']['token-demo']['banner']);
        self::assertStringContainsString('No events have been recorded in the past 3 days', $banner->text());
        self::assertStringContainsString('demo.example.com', $banner->text());
    }

    public function testActivityEndpointReportsWhenEventsCannotBeRead(): void
    {
        $activity = $this->createMock(WebsiteActivityService::class);
        $activity->method('getStatuses')->willThrowException(new \RuntimeException('Database unavailable'));

        $response = $this->controller()->websiteActivity($activity);

        self::assertSame(503, $response->getStatusCode());
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertSame(['error' => 'Data reception status is unavailable.'], json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testSettingsPageRendersActivityThresholdFields(): void
    {
        $controller = $this->controller('/dashboard/settings');
        $container = self::getContainer();

        $response = $controller->applicationSettings($container->get(DocumentationLinks::class), $container->get(TrackerBuilds::class), $container->get(TrackerScript::class));
        self::assertSame(200, $response->getStatusCode());

        $crawler = new Crawler((string) $response->getContent());
        self::assertCount(1, $crawler->filter('input[name="website_activity_active_days"]'));
        self::assertCount(1, $crawler->filter('input[name="website_activity_stale_days"]'));
    }

    private function websitesPage(WebsiteActivityService $activity): Crawler
    {
        $controller = $this->controller('/dashboard');
        $request = self::getContainer()->get('request_stack')->getCurrentRequest();
        $response = $controller->index($request, self::getContainer()->get(DropInScripts::class), $activity);
        self::assertSame(200, $response->getStatusCode());

        return new Crawler((string) $response->getContent());
    }

    /** The dashboard controller as an administrator, reading this test's websites. */
    private function controller(string $path = '/dashboard/websites/activity'): DashboardController
    {
        self::bootKernel();
        $container = self::getContainer();
        $container->set(WebsiteConfigManager::class, new WebsiteConfigManager($this->directory));
        $request = Request::create($path);
        $request->setSession(new Session(new MockArraySessionStorage()));
        $container->get('request_stack')->push($request);
        $admin = (new User())->setUsername('admin')->setRoles(['ROLE_ADMIN'])->setPassword('unused');
        $container->get('security.token_storage')->setToken(new UsernamePasswordToken($admin, 'main', ['ROLE_ADMIN']));

        return $container->get(DashboardController::class);
    }

    /** @return array<string, mixed> */
    private static function activityStatus(string $status, string $label, string $relativeTime): array
    {
        return [
            'token' => 'token-demo',
            'status' => $status,
            'label' => $label,
            'relative_time' => $relativeTime,
            'last_event_at' => '2026-10-05T12:00:00+00:00',
            'last_event_at_formatted' => '2026-10-05 12:00 UTC',
            'active_days' => 1,
            'stale_days' => 3,
        ];
    }
}
