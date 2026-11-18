<?php

declare(strict_types=1);

namespace App\Integrations\Alerts;

use App\Entities\Incident\IncidentStates;
use App\Service\Operations\ProcessingTasks;

/**
 * Incident states kept under task type `alerts` in processing_tasks: one
 * record per rule and website (the state is the fingerprint), and the hourly
 * check's own record under the empty subject (its fingerprint is the last
 * window it evaluated).
 */
final class TaskIncidentStates implements IncidentStates
{
    public const TASK_TYPE = 'alerts';

    public function __construct(private readonly ProcessingTasks $tasks)
    {
    }

    public function snapshot(): array
    {
        try {
            $latest = $this->tasks->latest(self::TASK_TYPE);
        } catch (\Throwable) {
            $latest = [];
        }

        $job = $latest[''] ?? null;
        $attempt = $job !== null ? $job['attempt'] : null;
        $states = [];
        foreach ($latest as $subject => $item) {
            if ((string) $subject !== '') {
                $fingerprint = $item['success']['fingerprint'] ?? null;
                $states[(string) $subject] = $fingerprint === null ? null : (string) $fingerprint;
            }
        }

        return [
            'last_check' => $attempt === null
                ? null
                : ($attempt['finished_at'] ?? $attempt['started_at']),
            'last_window_end' => $job !== null ? ($job['success']['fingerprint'] ?? null) : null,
            'states' => $states,
        ];
    }
}
