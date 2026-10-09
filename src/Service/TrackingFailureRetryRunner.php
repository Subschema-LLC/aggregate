<?php

declare(strict_types=1);

namespace App\Service;

use App\Message\TrackEventMessage;
use App\Service\Operations\ProcessingTasks;
use App\Service\Operations\TaskTrigger;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;

/**
 * Logs failed enhanced-message persistence attempts and retries failed
 * TrackEventMessage items from the Messenger failure transport on demand.
 * Not final: ingestion and message-handler tests replace it with a double.
 */
class TrackingFailureRetryRunner
{
    public const TASK_TYPE_FAILURE = 'tracking_event_store';
    public const TASK_TYPE_RETRY = 'tracking_retry';
    public const TASK_TYPE_INGEST_FAILURE = 'tracking_ingest';
    private const RETRY_SCAN_FACTOR = 10;

    private \Closure $clock;

    /**
     * @param \Closure(): \DateTimeImmutable|null $clock
     */
    public function __construct(
        private readonly ProcessingTasks $tasks,
        private readonly TrackingFailureSettings $settings,
        private readonly MessageBusInterface $bus,
        #[Autowire(service: 'messenger.transport.failed')]
        private readonly ListableReceiverInterface $failedReceiver,
        private readonly LoggerInterface $logger,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function recordFailure(TrackEventMessage $message, \Throwable $error): void
    {
        $now = ($this->clock)();
        $task = $this->tasks->start(
            self::TASK_TYPE_FAILURE,
            $message->websiteToken,
            TaskTrigger::worker(),
            $now,
        );
        if ($task === null) {
            return;
        }
        $details = sprintf(
            'An enhanced event could not be written to events (%s). The message remains in the failed transport for retry.',
            $this->shortClass($error),
        );
        $this->tasks->fail($task, $now, $details);
    }

    public function recordIngestionFailure(string $mode, ?string $websiteToken, \Throwable $error): void
    {
        $mode = in_array($mode, ['anonymous', 'enhanced'], true) ? $mode : 'unknown';
        $now = ($this->clock)();
        $subject = $mode.':'.($websiteToken ?? 'unknown');
        $task = $this->tasks->start(
            self::TASK_TYPE_INGEST_FAILURE,
            $subject,
            TaskTrigger::worker(),
            $now,
        );
        if ($task === null) {
            return;
        }
        $details = sprintf(
            'A %s tracking request failed before completion (%s).',
            $mode,
            $this->shortClass($error),
        );
        $this->tasks->fail($task, $now, $details);
    }

    /**
     * @return array{status: string, enabled: bool, retried: int, scanned: int, ignored: int, message: string}
     */
    public function retryNow(TaskTrigger $trigger, ?int $limit = null): array
    {
        $settings = $this->settings->toArray();
        if (!$settings[TrackingFailureSettings::KEY_ENABLED]) {
            return [
                'status' => 'disabled',
                'enabled' => false,
                'retried' => 0,
                'scanned' => 0,
                'ignored' => 0,
                'message' => 'Tracking retry is disabled by configuration.',
            ];
        }

        $max = $limit ?? $settings[TrackingFailureSettings::KEY_BATCH_SIZE];
        if ($max < 1 || $max > 1000) {
            throw new \InvalidArgumentException('The retry limit must be between 1 and 1000.');
        }

        $now = ($this->clock)();
        $task = $this->tasks->start(self::TASK_TYPE_RETRY, null, $trigger, $now, 900);
        if ($task === null) {
            return [
                'status' => 'busy',
                'enabled' => true,
                'retried' => 0,
                'scanned' => 0,
                'ignored' => 0,
                'message' => 'A tracking retry run is already in progress.',
            ];
        }

        try {
            $retried = 0;
            $scanned = 0;
            $ignored = 0;
            foreach ($this->failedReceiver->all($max * self::RETRY_SCAN_FACTOR) as $envelope) {
                ++$scanned;
                $message = $envelope->getMessage();
                if (!$message instanceof TrackEventMessage) {
                    ++$ignored;
                    continue;
                }
                if ($retried >= $max) {
                    break;
                }
                $this->bus->dispatch($message);
                $this->failedReceiver->ack($envelope);
                ++$retried;
            }

            $details = sprintf(
                'Scanned %d failed message%s, retried %d tracking message%s, ignored %d other message%s.',
                $scanned,
                $scanned === 1 ? '' : 's',
                $retried,
                $retried === 1 ? '' : 's',
                $ignored,
                $ignored === 1 ? '' : 's',
            );
            $this->tasks->succeed($task, ($this->clock)(), $retried, $details);

            return [
                'status' => 'succeeded',
                'enabled' => true,
                'retried' => $retried,
                'scanned' => $scanned,
                'ignored' => $ignored,
                'message' => $details,
            ];
        } catch (\Throwable $error) {
            $this->logger->error('Retrying failed tracking messages failed.', ['exception' => $error]);
            $details = sprintf(
                'Tracking retries stopped (%s). No pending failed message was deleted unless it had already been re-dispatched.',
                $this->shortClass($error),
            );
            $this->tasks->fail($task, ($this->clock)(), $details);

            return [
                'status' => 'failed',
                'enabled' => true,
                'retried' => 0,
                'scanned' => 0,
                'ignored' => 0,
                'message' => $details,
            ];
        }
    }

    /** @return array<string, mixed>|null */
    public function latestFailure(): ?array
    {
        $latest = null;
        foreach ($this->tasks->latest(self::TASK_TYPE_FAILURE) as $item) {
            if (!isset($item['attempt']) || !is_array($item['attempt'])) {
                continue;
            }
            if ($latest === null || $item['attempt']['started_at'] > $latest['started_at']) {
                $latest = $item['attempt'];
            }
        }

        return $latest;
    }

    /** @return array<string, mixed>|null */
    public function latestRetry(): ?array
    {
        return $this->tasks->latest(self::TASK_TYPE_RETRY)['']['attempt'] ?? null;
    }

    /** @return array<string, mixed>|null */
    public function latestIngestionFailure(): ?array
    {
        $latest = null;
        foreach ($this->tasks->latest(self::TASK_TYPE_INGEST_FAILURE) as $item) {
            if (!isset($item['attempt']) || !is_array($item['attempt'])) {
                continue;
            }
            if ($latest === null || $item['attempt']['started_at'] > $latest['started_at']) {
                $latest = $item['attempt'];
            }
        }

        return $latest;
    }

    private function shortClass(\Throwable $error): string
    {
        return (new \ReflectionClass($error))->getShortName();
    }
}
