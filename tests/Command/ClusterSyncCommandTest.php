<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ClusterSyncCommand;
use App\Service\Cluster\ClusterSync;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * app:cluster:sync runs once at a time with a file lock in var/cluster/,
 * which needs no lock component (an earlier version needed symfony/lock,
 * which is not installed, and failed on every server).
 */
final class ClusterSyncCommandTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/aggregate-cluster-sync-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testARunReportsWhatTheSyncDidAndItsOutcome(): void
    {
        $sync = $this->createMock(ClusterSync::class);
        $sync->expects(self::exactly(2))->method('run')->willReturnOnConsecutiveCalls(
            ['ok' => true, 'messages' => ['The configuration is the newest (abc).']],
            ['ok' => false, 'messages' => ['The administration server answered 500.']],
        );
        $tester = new CommandTester(new ClusterSyncCommand($sync, $this->directory));

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('The configuration is the newest', $tester->getDisplay());
        self::assertFileExists($this->directory.'/'.ClusterSyncCommand::LOCK_FILE);
        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('answered 500', $tester->getDisplay());
    }

    public function testARunThatOverlapsThePreviousOneWaitsForTheNextMinute(): void
    {
        mkdir(dirname($this->directory.'/'.ClusterSyncCommand::LOCK_FILE), 0775, true);
        $held = fopen($this->directory.'/'.ClusterSyncCommand::LOCK_FILE, 'c');
        self::assertNotFalse($held);
        self::assertTrue(flock($held, LOCK_EX));
        $sync = $this->createMock(ClusterSync::class);
        $sync->expects(self::never())->method('run');

        try {
            $tester = new CommandTester(new ClusterSyncCommand($sync, $this->directory));
            self::assertSame(0, $tester->execute([]));
            self::assertStringContainsString('still running', $tester->getDisplay());
        } finally {
            flock($held, LOCK_UN);
            fclose($held);
        }
    }
}
