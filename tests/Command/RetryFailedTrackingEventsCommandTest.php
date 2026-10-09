<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\RetryFailedTrackingEventsCommand;
use App\Service\TrackingFailureRetryRunner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class RetryFailedTrackingEventsCommandTest extends TestCase
{
    public function testItReportsTheRetrySummary(): void
    {
        $runner = $this->createMock(TrackingFailureRetryRunner::class);
        $runner->expects(self::once())->method('retryNow')->willReturn([
            'status' => 'succeeded',
            'enabled' => true,
            'retried' => 5,
            'scanned' => 6,
            'ignored' => 1,
            'message' => 'Scanned 6 failed messages, retried 5 tracking messages, ignored 1 other message.',
        ]);
        $tester = new CommandTester(new RetryFailedTrackingEventsCommand($runner));

        self::assertSame(Command::SUCCESS, $tester->execute(['--limit' => '5']));
        self::assertStringContainsString('retried 5 tracking messages', $tester->getDisplay());
    }

    public function testItReturnsFailureWhenRetryRunFails(): void
    {
        $runner = $this->createStub(TrackingFailureRetryRunner::class);
        $runner->method('retryNow')->willReturn([
            'status' => 'failed',
            'enabled' => true,
            'retried' => 0,
            'scanned' => 0,
            'ignored' => 0,
            'message' => 'Stopped.',
        ]);
        $tester = new CommandTester(new RetryFailedTrackingEventsCommand($runner));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertSame(Command::INVALID, $tester->execute(['--limit' => 'x']));
    }
}
