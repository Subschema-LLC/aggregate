<?php

declare(strict_types=1);

namespace App\Tests\Service\Update;

use App\Service\Update\MaintenanceMode;
use App\Service\Update\UpdateJournal;
use App\Service\Update\UpdatePaths;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MaintenanceModeTest extends TestCase
{
    private string $project;
    /** @var \Closure(string): bool */
    private \Closure $gate;

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir().'/aggregate-maintenance-'.bin2hex(random_bytes(8));
        mkdir($this->project.'/var', 0775, true);
        $this->gate = require dirname(__DIR__, 3).'/config/maintenance.php';
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->project, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->project);
        unset($_SERVER['REQUEST_URI']);
    }

    public function testRequestsPassThroughWithoutAMarker(): void
    {
        self::assertFalse(($this->gate)($this->project));
    }

    public function testApiRequestsGetAJsonMaintenanceResponse(): void
    {
        (new MaintenanceMode($this->project))->enable('update');
        $_SERVER['REQUEST_URI'] = '/api/receive?x=1';

        [$handled, $body] = $this->serve();

        self::assertTrue($handled);
        self::assertSame(['status' => 'maintenance', 'message' => 'An application update is in progress. Retry later.'], json_decode($body, true));
    }

    public function testPagesShowProgressWithoutVersionDetails(): void
    {
        (new MaintenanceMode($this->project))->enable('update');
        (new UpdateJournal($this->project))->write(['status' => 'running', 'step' => 'migrations', 'to' => ['version' => '9.9.9'], 'log' => []]);
        $_SERVER['REQUEST_URI'] = '/dashboard';

        [$handled, $body] = $this->serve();

        self::assertTrue($handled);
        self::assertStringContainsString('Down for maintenance', $body);
        self::assertStringContainsString('Updating the database', $body);
        self::assertStringContainsString('http-equiv="refresh"', $body);
        self::assertStringNotContainsString('9.9.9', $body);
    }

    public function testFailedUpdatesStayPausedWithoutAutoRefresh(): void
    {
        (new MaintenanceMode($this->project))->hold('update');
        (new UpdateJournal($this->project))->write(['status' => 'needs_attention', 'step' => 'migrations', 'log' => []]);
        $_SERVER['REQUEST_URI'] = '/';

        [$handled, $body] = $this->serve();

        self::assertTrue($handled);
        self::assertStringContainsString('needs attention from an administrator', $body);
        self::assertStringNotContainsString('http-equiv="refresh"', $body);
    }

    #[DataProvider('expiredLeases')]
    public function testExpiredLeaseEndsMaintenanceOnlyWhenNoUpdateHoldsTheLock(bool $locked, bool $expected): void
    {
        file_put_contents($this->project.'/'.UpdatePaths::MAINTENANCE_FILE, json_encode(['since' => time() - 7200, 'expires_at' => time() - 60]));
        $journal = new UpdateJournal($this->project);
        if ($locked) {
            $journal->acquire();
        }
        $_SERVER['REQUEST_URI'] = '/';

        try {
            [$handled] = $this->serve();
        } finally {
            $journal->release();
        }

        self::assertSame($expected, $handled);
    }

    public static function expiredLeases(): iterable
    {
        yield 'updater stopped' => [false, false];
        yield 'updater still running' => [true, true];
    }

    public function testUnreadableMarkerFailsClosed(): void
    {
        file_put_contents($this->project.'/'.UpdatePaths::MAINTENANCE_FILE, '{not json');
        $_SERVER['REQUEST_URI'] = '/';

        self::assertTrue($this->serve()[0]);
    }

    public function testServiceStatusRefreshAndDisable(): void
    {
        $maintenance = new MaintenanceMode($this->project);
        self::assertNull($maintenance->status());

        $maintenance->enable('update', 60);
        $status = $maintenance->status();
        self::assertTrue($status['active']);
        self::assertSame('update', $status['reason']);
        self::assertGreaterThan(time(), $status['expires_at']);

        $maintenance->hold('update');
        self::assertNull($maintenance->status()['expires_at']);
        $maintenance->refresh();
        self::assertNull($maintenance->status()['expires_at'], 'Refreshing never shortens an indefinite hold.');

        $maintenance->disable();
        self::assertNull($maintenance->status());
        self::assertFalse(($this->gate)($this->project));
    }

    public function testJournalLockPreventsConcurrentUpdates(): void
    {
        $first = new UpdateJournal($this->project);
        $second = new UpdateJournal($this->project);
        $first->acquire();
        self::assertTrue($second->isLocked());
        try {
            $second->acquire();
            self::fail('A second update must not start.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Another application update is running', $e->getMessage());
        } finally {
            $first->release();
        }
        self::assertFalse($second->isLocked());
        $second->acquire();
        $second->release();
    }

    public function testPackagingAndUpdaterShareOneProtectedPathList(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 3).'/scripts/build-release.py');
        preg_match('/^PROTECTED_PATHS = \((.*?)\n\)/ms', $script, $match);
        preg_match_all('/"([^"]+)"/', $match[1] ?? '', $patterns);
        self::assertSame(UpdatePaths::PROTECTED, $patterns[1]);

        foreach (['.env.local', '.env.prod.local', 'config/aggregate.yaml', 'config/aggregate_prod.yaml', 'config/websites.yaml',
            'config/goals.local.yaml', 'config/tag-manager/sites/a.yaml', 'var/data.db', 'var/branding/logo.png'] as $path) {
            self::assertTrue(UpdatePaths::isProtected($path), $path);
        }
        foreach (['.env', 'config/goals.yaml', 'config/services.yaml', 'var/browser/manifest.json', 'src/Kernel.php', 'config/sub/x.local.yaml'] as $path) {
            self::assertFalse(UpdatePaths::isProtected($path), $path);
        }
    }

    /** @return array{0: bool, 1: string} */
    private function serve(): array
    {
        ob_start();
        try {
            $handled = ($this->gate)($this->project);
        } finally {
            $body = (string) ob_get_clean();
        }

        return [$handled, $body];
    }
}
