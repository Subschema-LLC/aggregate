<?php

declare(strict_types=1);

namespace App\Integrations\Alerts;

use App\Entities\Destination\AlertChannel;
use App\Entities\Destination\Destination;
use App\Service\Alerts\WebhookSender;

/** Webhook destinations go through WebhookSender, email destinations through EmailAlerts. */
final class AlertChannels implements AlertChannel
{
    public function __construct(
        private readonly WebhookSender $webhooks,
        private readonly EmailAlerts $email,
    ) {
    }

    public function send(array $destination, array $payload, string $conditionDescription): array
    {
        if ($destination['type'] === Destination::TYPE_WEBHOOK) {
            $result = $this->webhooks->send($destination, $payload);

            return ['sent' => $result['sent'], 'reason' => $result['reason']];
        }

        $recipients = $destination['recipients'] ?? [];

        return $this->email->sendAll($recipients, $payload, $conditionDescription);
    }

    public function sendEach(
        array $destination,
        array $payload,
        string $conditionDescription,
    ): array
    {
        if ($destination['type'] === Destination::TYPE_WEBHOOK) {
            return [$this->send($destination, $payload, $conditionDescription)];
        }
        $results = [];
        foreach ($destination['recipients'] ?? [] as $recipient) {
            $results[] = $this->email->sendOne($recipient, $payload, $conditionDescription);
        }

        return $results;
    }
}
