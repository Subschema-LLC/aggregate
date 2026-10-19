<?php

declare(strict_types=1);

namespace App\Tests\Service\Scheduler;

use App\Service\Scheduler\ViewSyncResults;
use PHPUnit\Framework\TestCase;

final class ViewSyncResultsTest extends TestCase
{
    public function testAViewSyncBecomesOneResult(): void
    {
        $view = static fn (string $name, string $status, string $message = ''): array => ['view' => $name, 'status' => $status, 'rows' => null, 'message' => $message];

        self::assertSame(['status' => 'off', 'message' => 'Off.'], ViewSyncResults::summarize(['enabled' => false, 'results' => []], 'Off.'));
        self::assertSame(['status' => 'not_due', 'message' => 'Not due yet.'], ViewSyncResults::summarize(['enabled' => true, 'results' => [$view('a', 'not_due')]], 'Off.'));
        self::assertSame(['status' => 'ran', 'message' => 'Copied 2 views.'], ViewSyncResults::summarize(['enabled' => true, 'results' => [$view('a', 'synced'), $view('b', 'synced'), $view('c', 'not_due')]], 'Off.'));
        self::assertSame(['status' => 'ran', 'message' => 'Copied 1 view. Kept 2 unchanged views.'], ViewSyncResults::summarize(['enabled' => true, 'results' => [$view('a', 'unchanged'), $view('b', 'synced'), $view('c', 'unchanged')]], 'Off.'));
        self::assertSame(['status' => 'ran', 'message' => 'Kept 1 unchanged view.'], ViewSyncResults::summarize(['enabled' => true, 'results' => [$view('a', 'unchanged'), $view('b', 'not_due')]], 'Off.'));
        self::assertSame(
            ['status' => 'failed', 'message' => 'Copied 1 view. b failed: The reporting database could not be reached. 1 already being copied by another process.'],
            ViewSyncResults::summarize(['enabled' => true, 'results' => [$view('a', 'synced'), $view('b', 'failed', 'The reporting database could not be reached.'), $view('c', 'busy')]], 'Off.'),
        );
    }

    public function testStatusTakesTheWorstOutcomeAndTheSoonestNextSync(): void
    {
        $now = new \DateTimeImmutable('2026-10-15 12:00:00 UTC');
        $status = ViewSyncResults::status(['last_run' => $now, 'views' => [
            ['status' => 'succeeded', 'next_due' => $now->modify('+50 minutes')],
            ['status' => 'failed', 'next_due' => $now->modify('+15 minutes')],
        ]], $now);

        self::assertSame('failed', $status['last_status']);
        self::assertEquals($now->modify('+15 minutes'), $status['next_due']);
        self::assertEquals($now, ViewSyncResults::status(['last_run' => null, 'views' => [['status' => 'never', 'next_due' => null]]], $now)['next_due']);
    }
}
