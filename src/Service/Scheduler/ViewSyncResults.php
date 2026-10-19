<?php

declare(strict_types=1);

namespace App\Service\Scheduler;

/**
 * Turns a view sync's per-view results (BigQuery or the reporting database)
 * into one scheduler result.
 */
final class ViewSyncResults
{
    /**
     * @param array{enabled: bool, results: list<array{view: string, status: string, rows: ?int, message: string}>} $result
     *
     * @return array{status: string, message: string}
     */
    public static function summarize(array $result, string $offMessage): array
    {
        if (!$result['enabled']) {
            return ['status' => 'off', 'message' => $offMessage];
        }
        $byStatus = [];
        foreach ($result['results'] as $view) {
            $byStatus[$view['status']][] = $view;
        }
        $parts = [];
        if (isset($byStatus['synced'])) {
            $parts[] = sprintf('Copied %d view%s.', count($byStatus['synced']), count($byStatus['synced']) === 1 ? '' : 's');
        }
        if (isset($byStatus['unchanged'])) {
            $parts[] = sprintf('Kept %d unchanged view%s.', count($byStatus['unchanged']), count($byStatus['unchanged']) === 1 ? '' : 's');
        }
        foreach ($byStatus['failed'] ?? [] as $view) {
            $parts[] = $view['view'].' failed: '.$view['message'];
        }
        if (isset($byStatus['busy'])) {
            $parts[] = count($byStatus['busy']).' already being copied by another process.';
        }

        return match (true) {
            isset($byStatus['failed']) => ['status' => 'failed', 'message' => implode(' ', $parts)],
            isset($byStatus['synced']) || isset($byStatus['unchanged']) => ['status' => 'ran', 'message' => implode(' ', $parts)],
            isset($byStatus['busy']) => ['status' => 'busy', 'message' => implode(' ', $parts)],
            default => ['status' => 'not_due', 'message' => 'Not due yet.'],
        };
    }

    /**
     * Status of a view sync for the scheduler page: when it last started, the
     * worst outcome of the views' latest attempts, and the soonest next sync.
     *
     * @param array{last_run: ?\DateTimeImmutable, views: list<array{status: string, next_due: ?\DateTimeImmutable}>} $status
     *
     * @return array{last_run: ?\DateTimeImmutable, last_status: ?string, next_due: ?\DateTimeImmutable}
     */
    public static function status(array $status, \DateTimeImmutable $now): array
    {
        $statuses = array_column($status['views'], 'status');
        $next = null;
        foreach ($status['views'] as $view) {
            $due = $view['next_due'] ?? $now;
            if ($next === null || $due < $next) {
                $next = $due;
            }
        }

        return [
            'last_run' => $status['last_run'],
            'last_status' => match (true) {
                in_array('failed', $statuses, true) => 'failed',
                in_array('running', $statuses, true) => 'running',
                in_array('succeeded', $statuses, true) => 'succeeded',
                default => null,
            },
            'next_due' => $next,
        ];
    }
}
