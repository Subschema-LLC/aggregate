<?php

declare(strict_types=1);

namespace App\Features\SendTestAlert;

use App\Entities\AlertRule\AlertRules;
use App\Entities\AlertRule\RuleCoverage;
use App\Entities\AlertRule\RuleDescription;
use App\Entities\Destination\AlertChannel;
use App\Entities\Destination\Destination;
use App\Entities\Destination\Destinations;
use App\Entities\Incident\DeliveryLog;

/**
 * Sends a test alert to one destination now, without changing any rule's
 * state. It describes the chosen rule (or the first, or an example rule) on its
 * first covered website. An email destination may name one of its listed
 * recipients; otherwise every listed recipient gets it, and it counts as sent
 * only when all of them did.
 */
final class SendTestAlert
{
    public function __construct(
        private readonly Destinations $destinations,
        private readonly AlertRules $rules,
        private readonly RuleCoverage $coverage,
        private readonly AlertChannel $channel,
        private readonly DeliveryLog $log,
    ) {
    }

    /** @return array{sent: bool, destination_label: string, alert_id: string, reason: string} */
    public function send(
        string $destinationId,
        ?string $recipient,
        ?string $ruleId,
        TestAlertId $id,
    ): array {
        $destination = $this->destinations->find($destinationId);
        if ($destination === null) {
            throw new \InvalidArgumentException(
                sprintf('Alert destination "%s" does not exist.', $destinationId),
            );
        }
        $rules = $this->rules->all();
        $rule = ($ruleId !== null && isset($rules[$ruleId]))
            ? $rules[$ruleId]
            : (reset($rules) ?: ExampleRule::for($destinationId));
        $site = $this->coverage->of($rule)[0] ?? ExampleRule::WEBSITE;
        $payload = TestPayload::build($rule, $site, $id);
        $description = RuleDescription::condition($rule);

        if ($destination['type'] === Destination::TYPE_WEBHOOK) {
            $result = $this->channel->send($destination, $payload, $description);
            $reason = $result['sent']
                ? sprintf('Test alert sent (%s; rule status unchanged).', $result['reason'])
                : $result['reason'];

            return $this->record($destination, $payload, $result['sent'], $reason);
        }

        $destination['recipients'] = self::targets($destination['recipients'] ?? [], $recipient);
        $results = $this->channel->sendEach($destination, $payload, $description);
        $sent = array_filter($results, static fn (array $result): bool => $result['sent']);
        $failed = array_filter($results, static fn (array $result): bool => !$result['sent']);
        $allSent = $sent !== [] && $failed === [];
        $lastError = $failed === [] ? null : end($failed)['reason'];
        $reason = $allSent
            ? 'Test alert (rule status unchanged)'
            : ($lastError ?? 'Not sent: no recipient could be reached.');

        return $this->record($destination, $payload, $allSent, $reason);
    }

    /**
     * @param list<string> $listed
     *
     * @return list<string>
     */
    private static function targets(array $listed, ?string $recipient): array
    {
        if ($recipient === null || $recipient === '' || $recipient === '__all__') {
            return $listed;
        }
        foreach ($listed as $address) {
            if (strcasecmp($address, trim($recipient)) === 0) {
                return [$address];
            }
        }

        throw new \InvalidArgumentException(
            'Choose a recipient from the destination’s configured recipient list.',
        );
    }

    /**
     * @param array{label: string}                          $destination
     * @param array{rule: string, host: string, id: string} $payload
     *
     * @return array{sent: bool, destination_label: string, alert_id: string, reason: string}
     */
    private function record(array $destination, array $payload, bool $sent, string $reason): array
    {
        $outcome = $sent ? DeliveryLog::OUTCOME_SENT : DeliveryLog::OUTCOME_FAILED;
        $label = $destination['label'];
        $test = DeliveryLog::OP_TEST;
        $this->log->record($test, $outcome, $payload['rule'], $payload['host'], $label, $reason);

        return [
            'sent' => $sent,
            'destination_label' => $label,
            'alert_id' => $payload['id'],
            'reason' => $reason,
        ];
    }
}
