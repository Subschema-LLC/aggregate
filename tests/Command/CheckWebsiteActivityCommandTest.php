<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\CheckWebsiteActivityCommand;
use App\Service\WebsiteActivityService;
use App\Service\WebsiteConfigManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class CheckWebsiteActivityCommandTest extends TestCase
{
    public function testExecuteOutputsWebsitesAndStatuses(): void
    {
        $activityService = $this->createMock(WebsiteActivityService::class);
        $activityService->method('getActiveDays')->willReturn(1);
        $activityService->method('getStaleDays')->willReturn(3);
        $activityService->method('getStatuses')->willReturn([
            'token-site1' => [
                'token' => 'token-site1',
                'status' => 'active',
                'label' => 'Receiving data',
                'relative_time' => '10m ago',
                'last_event_at_formatted' => '2026-10-05 12:00 UTC',
            ],
        ]);

        $websiteManager = $this->createMock(WebsiteConfigManager::class);
        $websiteManager->method('getWebsites')->willReturn([
            ['name' => 'Main Site', 'domain' => 'example.com', 'token' => 'token-site1'],
        ]);

        $command = new CheckWebsiteActivityCommand($activityService, $websiteManager);
        $tester = new CommandTester($command);

        $tester->execute([]);

        $output = $tester->getDisplay();
        self::assertStringContainsString('Website Activity Check', $output);
        self::assertStringContainsString('Main Site', $output);
        self::assertStringContainsString('example.com', $output);
        self::assertStringContainsString('Active', $output);
        self::assertStringContainsString('10m ago', $output);
    }

    public function testUnreadableEventsAreReportedAsAFailure(): void
    {
        $activityService = $this->createMock(WebsiteActivityService::class);
        $activityService->method('getStatuses')->willThrowException(new \RuntimeException('Connection refused'));
        $command = new CheckWebsiteActivityCommand($activityService, $this->createMock(WebsiteConfigManager::class));
        $tester = new CommandTester($command);

        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('Data reception status is unavailable', $tester->getDisplay());
        self::assertStringContainsString('Connection refused', $tester->getDisplay());
    }
}
