<?php

declare(strict_types=1);

namespace App\Integrations\Alerts;

use App\Entities\Destination\AlertChannel;
use App\Service\Email\EmailPurpose;
use App\Service\Email\TransactionalMailer;
use App\Service\Operations\TaskTrigger;

/**
 * Sends alert emails through TransactionalMailer, which records each send
 * without the recipient.
 *
 * @phpstan-import-type AlertPayload from AlertChannel
 */
final class EmailAlerts
{
    public function __construct(
        private readonly TransactionalMailer $mailer,
        private readonly TaskTrigger $trigger,
    ) {
    }

    /**
     * Sends to every listed recipient. Once one has it the alert counts as
     * delivered: retrying would send it again to those who already have it.
     *
     * @param list<string> $recipients
     * @param AlertPayload $payload
     *
     * @return array{sent: bool, reason: string}
     */
    public function sendAll(array $recipients, array $payload, string $conditionDescription): array
    {
        $sentCount = 0;
        $lastError = null;
        foreach ($recipients as $address) {
            $result = $this->sendOne($address, $payload, $conditionDescription);
            if ($result['sent']) {
                ++$sentCount;
            } else {
                $lastError = $result['reason'];
            }
        }

        if ($sentCount === 0) {
            return ['sent' => false, 'reason' => $lastError ?? 'Not sent: no recipient is listed.'];
        }
        if ($sentCount < count($recipients)) {
            return ['sent' => true, 'reason' => sprintf(
                'Delivered to %d of %d listed recipients (id %s); the others failed: %s',
                $sentCount,
                count($recipients),
                $payload['id'],
                rtrim((string) $lastError, '.'),
            )];
        }

        $reason = sprintf('Delivered to listed recipients (id %s)', $payload['id']);

        return ['sent' => true, 'reason' => $reason];
    }

    /**
     * @param AlertPayload $payload
     *
     * @return array{sent: bool, reason: string}
     */
    public function sendOne(string $recipient, array $payload, string $conditionDescription): array
    {
        $title = match ($payload['type']) {
            'recovered' => sprintf('Recovered: %s on %s', $payload['rule'], $payload['host']),
            'test' => sprintf('Test alert: %s on %s', $payload['rule'], $payload['host']),
            default => sprintf('Alert firing: %s on %s', $payload['rule'], $payload['host']),
        };

        $result = $this->mailer->send(EmailPurpose::Alert, $recipient, [
            'alert_title' => $title,
            'alert_type' => $payload['type'],
            'alert_rule' => $payload['rule'],
            'alert_website' => $payload['website'],
            'alert_host' => $payload['host'],
            'alert_installation' => $payload['installation'],
            'alert_condition' => $payload['alert_condition'],
            'alert_condition_description' => $conditionDescription,
            'alert_window_end' => $payload['window_end'],
            'alert_id' => $payload['id'],
            'alert_incident' => $payload['incident'],
        ], $this->trigger);

        if ($result->sent) {
            return ['sent' => true, 'reason' => 'Delivered to listed recipients'];
        }
        $reason = TransactionalMailer::redact((string) $result->error, [$recipient]);

        return ['sent' => false, 'reason' => $reason];
    }
}
