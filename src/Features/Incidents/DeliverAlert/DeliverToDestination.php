<?php

declare(strict_types=1);

namespace App\Features\Incidents\DeliverAlert;

use App\Entities\Destination\AlertChannel;
use App\Entities\Destination\Destination;
use App\Entities\Incident\AlertIds;
use App\Entities\Incident\DeliveryLog;
use App\Entities\Incident\DeliveryRecord;

/**
 * Sends one alert to one destination unless it already has it. A recovery is
 * not sent to a destination that never received the firing alert.
 *
 * @phpstan-import-type DestinationArray from Destination
 */
final class DeliverToDestination
{
    public function __construct(
        private readonly AlertChannel $channel,
        private readonly DeliveryLog $log,
    ) {
    }

    /**
     * @param DestinationArray $destination
     *
     * @return 'sent'|'suppressed'|'failed'|null what to count, or null for nothing
     */
    public function deliver(array $destination, string $token, Attempt $attempt): ?string
    {
        $payload = $attempt->payload;
        $subject = DeliveryRecord::subject($payload['rule'], $token, $destination['id']);
        $last = $this->log->lastDelivered($subject);
        $recovery = $payload['type'] === 'recovered';
        $operation = $recovery ? DeliveryLog::OP_RECOVERED : DeliveryLog::OP_FIRING;

        if ($last === $payload['id']) {
            return $payload['type'] === 'firing' && $attempt->retryable ? 'suppressed' : null;
        }

        $firingId = AlertIds::alert(
            $attempt->installation,
            $payload['rule'],
            $token,
            'firing',
            $attempt->since,
        );
        if ($recovery && $last !== $firingId) {
            $this->log->markDelivered(
                $subject,
                $payload['id'],
                'Recovery not sent: the firing alert never reached this destination.',
            );
            $suppressed = DeliveryLog::OUTCOME_SUPPRESSED;
            $this->record($operation, $suppressed, $destination, $payload, 'Not sent: the firing '
                .'alert for this incident never reached this destination, so its recovery is not '
                .'sent either.');

            return 'suppressed';
        }

        $hours = DeliverAlert::RETRY_HOURS;
        $retryUntil = $attempt->windowEnd->modify('+'.$hours.' hours');
        if ($attempt->retryable && $retryUntil <= $attempt->completedHour) {
            return null; // Retries ran out earlier; the last failure is in the audit trail.
        }
        $lastAttempt = !$attempt->retryable
            || $retryUntil <= $attempt->completedHour->modify('+1 hour');

        $result = $this->channel->send($destination, $payload, $attempt->conditionDescription);
        if ($result['sent']) {
            $details = sprintf('Delivered %s alert %s.', $payload['type'], $payload['id']);
            $this->log->markDelivered($subject, $payload['id'], $details);
            $sent = DeliveryLog::OUTCOME_SENT;
            $this->record($operation, $sent, $destination, $payload, $result['reason']);

            return 'sent';
        }

        $reason = $result['reason'];
        if ($attempt->retryable) {
            $reason = rtrim($reason, '.').'. '.($lastAttempt
                ? 'Gave up: no more retries for this alert.'
                : 'Will retry at the next hourly check.');
        }
        $this->record($operation, DeliveryLog::OUTCOME_FAILED, $destination, $payload, $reason);

        return 'failed';
    }

    /**
     * @param DestinationArray                                $destination
     * @param array{rule: string, host: string, type: string} $payload
     */
    private function record(
        string $operation,
        string $outcome,
        array $destination,
        array $payload,
        string $details,
    ): void {
        $label = $destination['label'];
        $rule = $payload['rule'];
        $this->log->record($operation, $outcome, $rule, $payload['host'], $label, $details);
    }
}
