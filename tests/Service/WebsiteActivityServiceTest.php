<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Repository\EventRepository;
use App\Service\AggregateConfigLoader;
use App\Service\WebsiteActivityService;
use App\Service\WebsiteConfigManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class WebsiteActivityServiceTest extends TestCase
{
    public function testStatusCalculationWithCustomThresholds(): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $eventRepo = $this->createMock(EventRepository::class);
        $eventRepo->method('findLastEventDatesByTokens')->willReturn([
            'token-active' => $now->modify('-2 hours'),
            'token-idle' => $now->modify('-2 days'),
            'token-inactive' => $now->modify('-5 days'),
            // 'token-none' is missing
        ]);

        $websiteManager = $this->createMock(WebsiteConfigManager::class);
        $websiteManager->method('getWebsites')->willReturn([
            ['name' => 'Active Site', 'domain' => 'active.com', 'token' => 'token-active'],
            ['name' => 'Idle Site', 'domain' => 'idle.com', 'token' => 'token-idle'],
            ['name' => 'Inactive Site', 'domain' => 'inactive.com', 'token' => 'token-inactive'],
            ['name' => 'New Site', 'domain' => 'new.com', 'token' => 'token-none'],
        ]);

        $config = $this->createMock(AggregateConfigLoader::class);
        $config->method('getWithEnvFallback')->willReturnCallback(function (string $key, mixed $default): mixed {
            return match ($key) {
                'website_activity_active_days' => 1,
                'website_activity_stale_days' => 3,
                default => $default,
            };
        });

        $cache = new ArrayAdapter();
        $service = new WebsiteActivityService($eventRepo, $websiteManager, $config, $cache);

        $statuses = $service->getStatuses();

        self::assertCount(4, $statuses);

        // Active
        self::assertSame('active', $statuses['token-active']['status']);
        self::assertSame('Receiving data', $statuses['token-active']['label']);
        self::assertStringContainsString('h ago', $statuses['token-active']['relative_time']);
        self::assertNotNull($statuses['token-active']['last_event_at']);

        // Idle
        self::assertSame('idle', $statuses['token-idle']['status']);
        self::assertSame('Idle', $statuses['token-idle']['label']);
        self::assertSame('2d ago', $statuses['token-idle']['relative_time']);

        // Inactive
        self::assertSame('inactive', $statuses['token-inactive']['status']);
        self::assertSame('No recent data', $statuses['token-inactive']['label']);
        self::assertSame('5d ago', $statuses['token-inactive']['relative_time']);

        // None
        self::assertSame('none', $statuses['token-none']['status']);
        self::assertSame('Waiting for setup', $statuses['token-none']['label']);
        self::assertSame('Never', $statuses['token-none']['relative_time']);
        self::assertNull($statuses['token-none']['last_event_at']);
    }

    public function testGetStatusForUnknownToken(): void
    {
        $eventRepo = $this->createMock(EventRepository::class);
        $eventRepo->method('findLastEventDatesByTokens')->willReturn([]);

        $websiteManager = $this->createMock(WebsiteConfigManager::class);
        $websiteManager->method('getWebsites')->willReturn([]);

        $config = $this->createMock(AggregateConfigLoader::class);
        $config->method('getWithEnvFallback')->willReturnArgument(1);

        $cache = new ArrayAdapter();
        $service = new WebsiteActivityService($eventRepo, $websiteManager, $config, $cache);

        $status = $service->getStatusForToken('unregistered-token');
        self::assertNotNull($status);
        self::assertSame('none', $status['status']);
        self::assertSame('Waiting for setup', $status['label']);
        self::assertSame('Never', $status['relative_time']);

        self::assertNull($service->getStatusForToken(''));
    }

    public function testFormatRelativeTime(): void
    {
        $now = new \DateTimeImmutable('2026-10-05 12:00:00', new \DateTimeZone('UTC'));

        $eventRepo = $this->createMock(EventRepository::class);
        $websiteManager = $this->createMock(WebsiteConfigManager::class);
        $config = $this->createMock(AggregateConfigLoader::class);
        $cache = new ArrayAdapter();
        $service = new WebsiteActivityService($eventRepo, $websiteManager, $config, $cache);

        self::assertSame('just now', $service->formatRelativeTime($now->modify('-30 seconds'), $now));
        self::assertSame('5m ago', $service->formatRelativeTime($now->modify('-5 minutes'), $now));
        self::assertSame('3h ago', $service->formatRelativeTime($now->modify('-3 hours'), $now));
        self::assertSame('2d ago', $service->formatRelativeTime($now->modify('-2 days'), $now));
        self::assertSame('Sep 1, 2026', $service->formatRelativeTime($now->modify('-34 days'), $now));
    }

    public function testPagesReadOnlyStatusesAlreadyComputed(): void
    {
        $eventRepo = $this->createMock(EventRepository::class);
        $eventRepo->expects(self::once())->method('findLastEventDatesByTokens')
            ->willReturn(['token-1' => new \DateTimeImmutable('now', new \DateTimeZone('UTC'))]);
        $websiteManager = $this->createMock(WebsiteConfigManager::class);
        $websiteManager->method('getWebsites')->willReturn([['name' => 'Site 1', 'domain' => 'site1.com', 'token' => 'token-1']]);
        $config = $this->createMock(AggregateConfigLoader::class);
        $config->method('getWithEnvFallback')->willReturnArgument(1);
        $service = new WebsiteActivityService($eventRepo, $websiteManager, $config, new ArrayAdapter());

        self::assertNull($service->cachedStatuses(), 'nothing computed yet, and nothing queried');
        self::assertNull($service->cachedStatusForToken('token-1'));

        $service->getStatuses();
        self::assertSame('active', $service->cachedStatuses()['token-1']['status']);
        self::assertSame('active', $service->cachedStatusForToken('token-1')['status']);
        self::assertNull($service->cachedStatusForToken('token-unknown'));
        self::assertNull($service->cachedStatusForToken(''));

        $service->forget();
        self::assertNull($service->cachedStatuses());
    }

    public function testAFailedLookupIsNotCachedAsNoEvents(): void
    {
        $eventRepo = $this->createMock(EventRepository::class);
        $eventRepo->expects(self::exactly(2))->method('findLastEventDatesByTokens')->willReturnOnConsecutiveCalls(
            self::throwException(new \RuntimeException('Database unavailable')),
            ['token-1' => new \DateTimeImmutable('now', new \DateTimeZone('UTC'))],
        );
        $websiteManager = $this->createMock(WebsiteConfigManager::class);
        $websiteManager->method('getWebsites')->willReturn([['name' => 'Site 1', 'domain' => 'site1.com', 'token' => 'token-1']]);
        $config = $this->createMock(AggregateConfigLoader::class);
        $config->method('getWithEnvFallback')->willReturnArgument(1);
        $service = new WebsiteActivityService($eventRepo, $websiteManager, $config, new ArrayAdapter());

        try {
            $service->getStatuses();
            self::fail('The failure reaches the caller.');
        } catch (\RuntimeException) {
        }
        self::assertNull($service->cachedStatuses());
        self::assertSame('active', $service->getStatuses()['token-1']['status']);
    }

    public function testRefreshBypassesCache(): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $eventRepo = $this->createMock(EventRepository::class);
        $eventRepo->expects(self::exactly(2))
            ->method('findLastEventDatesByTokens')
            ->willReturn(['token-1' => $now]);

        $websiteManager = $this->createMock(WebsiteConfigManager::class);
        $websiteManager->method('getWebsites')->willReturn([
            ['name' => 'Site 1', 'domain' => 'site1.com', 'token' => 'token-1'],
        ]);

        $config = $this->createMock(AggregateConfigLoader::class);
        $config->method('getWithEnvFallback')->willReturnArgument(1);

        $cache = new ArrayAdapter();
        $service = new WebsiteActivityService($eventRepo, $websiteManager, $config, $cache);

        // First call populates cache
        $service->getStatuses();
        // Second call hits cache (no repo call)
        $service->getStatuses();
        // Refresh busts cache (triggers second repo call)
        $service->refresh();
    }
}
