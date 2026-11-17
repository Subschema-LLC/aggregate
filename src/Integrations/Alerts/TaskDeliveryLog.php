<?php

declare(strict_types=1);

namespace App\Integrations\Alerts;

use App\Entities\Incident\DeliveryLog;
use App\Service\Alerts\WebhookSender;
use App\Service\Operations\AuditTrail;
use App\Service\Operations\ProcessingTasks;
use App\Service\Operations\TaskTrigger;
use Psr\Clock\ClockInterface;

/**
 * Delivery records under task type `alerts_delivery` in processing_tasks
 * (the delivered alert ID is the fingerprint), and delivery attempts in
 * audit_trail with always: true, so turning off an audit switch never hides
 * recent alerts.
 */
final class TaskDeliveryLog implements DeliveryLog
{
    public const TASK_TYPE = 'alerts_delivery';
    public const AUDIT_CATEGORY = 'operations';

    /**
     * @param array<string, array{success: ?array<string, mixed>}> $delivered the latest
     *     delivery record per subject, read once per check
     */
    public function __construct(
        private readonly ProcessingTasks $tasks,
        private readonly AuditTrail $audit,
        private readonly ClockInterface $clock,
        private readonly TaskTrigger $trigger,
        private readonly ?int $taskId,
        private array $delivered = [],
    ) {
    }

    public function lastDelivered(string $subject): ?string
    {
        $fingerprint = $this->delivered[$subject]['success']['fingerprint'] ?? null;

        return $fingerprint === null ? null : (string) $fingerprint;
    }

    public function markDelivered(string $subject, string $alertId, string $details): void
    {
        $now = $this->now();
        $id = $this->tasks->start(self::TASK_TYPE, $subject, $this->trigger, $now);
        if ($id !== null) {
            $this->tasks->succeed($id, $now, null, $details, null, $alertId);
        }
        $this->delivered[$subject]['success']['fingerprint'] = $alertId;
    }

    public function record(
        string $operation,
        string $outcome,
        string $ruleId,
        string $websiteHost,
        string $destinationLabel,
        string $details,
    ): void {
        $this->audit->record(
            self::AUDIT_CATEGORY,
            $operation,
            $outcome,
            $this->now(),
            self::subject($ruleId, $websiteHost, $destinationLabel),
            $this->trigger->requestedBy,
            $this->taskId,
            WebhookSender::redact($details),
            always: true,
        );
    }

    public static function subject(
        string $ruleId,
        string $websiteHost,
        string $destinationLabel,
    ): string {
        return sprintf('%s · %s · %s', $ruleId, $websiteHost, $destinationLabel);
    }

    private function now(): \DateTimeImmutable
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());

        return $now->setTimezone(new \DateTimeZone('UTC'));
    }
}
