<?php

declare(strict_types=1);

namespace App\Features\DeliverAlert;

use App\Entities\AlertRule\AlertRule;
use App\Entities\Destination\AlertChannel;
use App\Entities\Incident\AlertIds;
use App\Entities\Incident\UtcHour;

/**
 * Webhook payload v1 for a firing or recovered alert. Its id and incident are
 * derived, so a retry sends the same payload.
 *
 * @phpstan-import-type RuleArray from AlertRule
 * @phpstan-import-type AlertPayload from AlertChannel
 */
final class PayloadV1
{
    /**
     * @param 'firing'|'recovered'                                $type
     * @param RuleArray                                           $rule
     * @param array{token: string, name: string, domain: string} $site
     *
     * @return AlertPayload
     */
    public static function build(
        string $type,
        array $rule,
        array $site,
        string $installation,
        \DateTimeImmutable $windowEnd,
        string $since,
    ): array {
        $windowEndIso = UtcHour::format($windowEnd);
        $token = $site['token'];

        return [
            'schema_version' => '1',
            'id' => AlertIds::alert($installation, $rule['id'], $token, $type, $windowEndIso),
            'incident' => AlertIds::incident($installation, $rule['id'], $site['token'], $since),
            'type' => $type,
            'rule' => $rule['id'],
            'website' => $site['token'],
            'host' => $site['domain'] !== '' ? $site['domain'] : $site['token'],
            'installation' => $installation,
            'alert_condition' => AlertRule::conditionText($rule),
            'window_end' => $windowEndIso,
        ];
    }
}
