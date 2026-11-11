<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Message\TrackEventMessage;
use App\Service\AggregateConfigLoader;
use App\Service\Operations\ProcessingTasks;
use App\Service\TrackingFailureRetryRunner;
use App\Service\TrackingFailureSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;

final class TrackingFailureRetryRunnerTest extends TestCase
{
    /** @return iterable<string, array{?string, string, string}> */
    public static function transports(): iterable
    {
        yield 'sync' => ['sync://', 'did not queue it for retry', 'remains in the failed transport'];
        yield 'sync, upper case with spaces' => ['  SYNC://  ', 'did not queue it for retry', 'failed transport for'];
        yield 'unset DSN, the documented default' => [null, 'did not queue it for retry', 'failed transport for'];
        yield 'doctrine' => ['doctrine://default', 'keeps it in the failed transport for app:tracking:retry-failed', 'did not queue'];
        yield 'redis' => ['redis://localhost:6379/messages', 'Messenger retries it', 'did not queue'];
    }

    #[DataProvider('transports')]
    public function testStoreFailureDescribesWhatHappensToTheMessage(?string $dsn, string $expected, string $absent): void
    {
        $details = null;
        $tasks = $this->createMock(ProcessingTasks::class);
        $tasks->expects(self::once())->method('start')
            ->with(TrackingFailureRetryRunner::TASK_TYPE_FAILURE, 'site-token')
            ->willReturn(7);
        $tasks->expects(self::once())->method('fail')
            ->willReturnCallback(static function (int $id, \DateTimeImmutable $now, string $text) use (&$details): void {
                $details = $text;
            });

        $runner = new TrackingFailureRetryRunner(
            $tasks,
            new TrackingFailureSettings($this->createStub(AggregateConfigLoader::class)),
            $this->createStub(MessageBusInterface::class),
            $this->createStub(ListableReceiverInterface::class),
            new NullLogger(),
            static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-11-10 12:00:00 UTC'),
            $dsn,
        );

        $runner->recordFailure($this->message(), new \RuntimeException('database is locked'));

        self::assertIsString($details);
        self::assertStringContainsString('could not be written to events (RuntimeException)', $details);
        self::assertStringContainsString($expected, $details);
        self::assertStringNotContainsString($absent, $details);
        self::assertStringNotContainsString('database is locked', $details, 'The exception message can hold payload values.');
    }

    private function message(): TrackEventMessage
    {
        return new TrackEventMessage(
            websiteToken: 'site-token',
            eventName: 'purchase.completed',
            pagePath: '/pricing',
            referrerChannel: 'search',
            deviceClass: 'desktop',
            viewportBucket: 'large',
            screenWidth: 1440,
            goalEvent: 'purchase',
            eventData: ['plan' => 'pro'],
            generalizedUserAgent: 'Chrome / desktop',
            visitorId: 'visitor_abc',
            sessionId: 'session_abc',
            occurredAt: new \DateTimeImmutable('2026-11-10 11:59:00 UTC'),
        );
    }
}
