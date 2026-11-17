<?php

declare(strict_types=1);

namespace App\Integrations\Alerts;

/**
 * The YAML written for the alerts mapping: lists in a fixed key order, and a
 * rule's filters only when it counts events.
 *
 * @phpstan-import-type AlertsState from AlertsMapping
 */
final class AlertsYaml
{
    /**
     * @param AlertsState $state
     *
     * @return array{destinations: list<array<string, mixed>>, rules: list<array<string, mixed>>}
     */
    public static function write(array $state): array
    {
        $destinations = [];
        foreach ($state['destinations'] as $destination) {
            $entry = [
                'id' => $destination['id'],
                'label' => $destination['label'],
                'type' => $destination['type'],
            ];
            if ($destination['type'] === 'webhook') {
                $entry['url'] = $destination['url'] ?? '';
            } else {
                $entry['recipients'] = $destination['recipients'] ?? [];
            }
            $destinations[] = $entry;
        }

        $rules = [];
        foreach ($state['rules'] as $rule) {
            $entry = [
                'id' => $rule['id'],
                'enabled' => $rule['enabled'],
                'covers' => $rule['covers'],
                'source' => $rule['source'],
            ];
            if ($rule['source'] === 'events') {
                $entry['privacy_mode'] = $rule['privacy_mode'];
                if ($rule['event_name'] !== '') {
                    $entry['event_name'] = $rule['event_name'];
                }
            }
            $entry['condition'] = $rule['condition'];
            $entry['threshold'] = $rule['threshold'];
            $entry['window'] = $rule['window'];
            $entry['destinations'] = $rule['destinations'];
            $rules[] = $entry;
        }

        return ['destinations' => $destinations, 'rules' => $rules];
    }
}
