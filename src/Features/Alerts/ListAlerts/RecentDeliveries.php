<?php

declare(strict_types=1);

namespace App\Features\Alerts\ListAlerts;

use App\Entities\Incident\DeliveryHistory;
use App\Entities\Incident\DeliveryLog;

/**
 * The latest alert deliveries with their rule, website and destination, read
 * back from the "rule · website · destination" subject they were recorded with.
 *
 * @phpstan-type Entry array{
 *     id: int,
 *     occurred_at: \DateTimeImmutable,
 *     rule: string,
 *     website: string,
 *     type: 'firing'|'recovered'|'test',
 *     type_label: string,
 *     destination: string,
 *     outcome: string,
 *     outcome_label: string,
 *     reason: string
 * }
 * @phpstan-type Recent array{entries: list<Entry>, has_more: bool}
 */
final class RecentDeliveries
{
    private const OPERATIONS = [
        DeliveryLog::OP_FIRING, DeliveryLog::OP_RECOVERED, DeliveryLog::OP_TEST,
    ];

    /** @return Recent */
    public static function of(DeliveryHistory $history, int $limit): array
    {
        $recent = $history->recent(self::OPERATIONS, $limit);
        if ($recent === null) {
            return ['entries' => [], 'has_more' => false];
        }

        $entries = [];
        foreach ($recent['rows'] as $row) {
            $parts = explode(' · ', $row['subject'], 3);
            $type = match ($row['operation']) {
                DeliveryLog::OP_RECOVERED => 'recovered',
                DeliveryLog::OP_TEST => 'test',
                default => 'firing',
            };
            $entries[] = [
                'id' => $row['id'],
                'occurred_at' => $row['occurred_at'],
                'rule' => $parts[0],
                'website' => $parts[1] ?? '',
                'type' => $type,
                'type_label' => match ($type) {
                    'recovered' => 'Recovered',
                    'test' => 'Test',
                    default => 'Firing',
                },
                'destination' => $parts[2] ?? '',
                'outcome' => $row['outcome'],
                'outcome_label' => match ($row['outcome']) {
                    DeliveryLog::OUTCOME_SENT => 'Sent',
                    DeliveryLog::OUTCOME_SUPPRESSED => 'Suppressed',
                    default => 'Failed',
                },
                'reason' => $row['details'],
            ];
        }

        return ['entries' => $entries, 'has_more' => $recent['total'] > $limit];
    }
}
