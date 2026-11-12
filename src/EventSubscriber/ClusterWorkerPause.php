<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\Cluster\ClusterGate;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;

/**
 * Stops a worker server's queue consumer, before it takes a message, while
 * the server's configuration may be out of date: enhanced events wait in the
 * queue instead of being written under old privacy rules. systemd or the
 * process manager starts the consumer again, and it resumes once the
 * configuration is current. Nothing on `all` or `administration`.
 */
final class ClusterWorkerPause implements EventSubscriberInterface
{
    public function __construct(
        private readonly ClusterGate $cluster,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerStartedEvent::class => 'onStarted',
            WorkerRunningEvent::class => 'onRunning',
        ];
    }

    public function onStarted(WorkerStartedEvent $event): void
    {
        $this->stopIfPaused($event->getWorker());
    }

    public function onRunning(WorkerRunningEvent $event): void
    {
        $this->stopIfPaused($event->getWorker());
    }

    private function stopIfPaused(\Symfony\Component\Messenger\Worker $worker): void
    {
        $reason = $this->cluster->pauseReason();
        if ($reason !== null) {
            $this->logger?->warning('Queue consumer stopped: '.$reason);
            $worker->stop();
        }
    }
}
