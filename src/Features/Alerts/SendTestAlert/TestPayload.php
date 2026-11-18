<?php

declare(strict_types=1);

namespace App\Features\Alerts\SendTestAlert;

use App\Entities\AlertRule\AlertRule;
use App\Entities\Destination\AlertChannel;
use App\Entities\Incident\AlertIds;
use App\Entities\Incident\UtcHour;

/**
 * Webhook payload v1 of type test. Its incident is keyed by the random ID, so
 * a test never shares an incident with a real alert.
 *
 * @phpstan-import-type RuleArray from AlertRule
 * @phpstan-import-type AlertPayload from AlertChannel
 */
final class TestPayload
{
    /**
     * @param RuleArray                                           $rule
     * @param array{token: string, name: string, domain: string} $site
     *
     * @return AlertPayload
     */
    public static function build(array $rule, array $site, TestAlertId $id): array
    {
        $incident = 'test:'.$id->id;

        return [
            'schema_version' => '1',
            'id' => $id->id,
            'incident' => AlertIds::incident(
                $id->installation,
                $rule['id'],
                $site['token'],
                $incident,
            ),
            'type' => 'test',
            'rule' => $rule['id'],
            'website' => $site['token'],
            'host' => $site['domain'] !== '' ? $site['domain'] : 'example.com',
            'installation' => $id->installation,
            'alert_condition' => AlertRule::conditionText($rule),
            'window_end' => UtcHour::format($id->windowEnd),
        ];
    }
}
