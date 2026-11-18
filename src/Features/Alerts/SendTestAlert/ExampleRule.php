<?php

declare(strict_types=1);

namespace App\Features\Alerts\SendTestAlert;

use App\Entities\AlertRule\AlertRule;

/**
 * The rule and website a test alert describes when none is configured.
 *
 * @phpstan-import-type RuleArray from AlertRule
 */
final class ExampleRule
{
    public const WEBSITE = [
        'token' => 'test-website-token',
        'name' => 'Example Website',
        'domain' => 'example.com',
    ];

    /** @return RuleArray */
    public static function for(string $destinationId): array
    {
        return [
            'id' => 'test-rule',
            'enabled' => true,
            'covers' => 'all',
            'source' => AlertRule::SOURCE_EVENTS,
            'privacy_mode' => AlertRule::PRIVACY_ALL,
            'event_name' => '',
            'condition' => AlertRule::CONDITION_FEWER_THAN,
            'threshold' => 5,
            'window' => AlertRule::WINDOW_HOUR,
            'destinations' => [$destinationId],
        ];
    }
}
