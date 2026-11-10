<?php

declare(strict_types=1);

namespace App\Service\Alerts;

use App\Service\FeatureFlags;
use App\Service\Operations\ProcessingTasks;
use App\Service\Operations\TaskTrigger;
use App\Service\Scheduler\ScheduledJob;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Scheduled job that evaluates alert rules once per completed UTC hour, so the
 * 5-minute scheduler delivers firing and recovery alerts within minutes of an
 * hour closing.
 */
final class AlertsJob implements ScheduledJob
{
    public function __construct(
        private readonly AlertEvaluator $evaluator,
        private readonly AlertSettings $settings,
        private readonly FeatureFlags $features,
        private readonly ProcessingTasks $tasks,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function name(): string
    {
        return AlertEvaluator::TASK_TYPE;
    }

    public function label(): string
    {
        return 'Alerts';
    }

    public function status(): array
    {
        $latest = [];
        try {
            $latest = $this->tasks->latest(AlertEvaluator::TASK_TYPE);
        } catch (\Throwable) {
            // Handled below if the feature is enabled.
        }
        $attempt = $latest['']['attempt'] ?? null;
        $success = $latest['']['success'] ?? null;

        $status = [
            'enabled' => false,
            'schedule' => 'Hourly (completed UTC hours)',
            'last_run' => $attempt['started_at'] ?? null,
            'last_status' => $attempt['status'] ?? null,
            'next_due' => null,
            'problem' => null,
        ];

        if (!$this->features->isEnabled('alerts')) {
            return $status;
        }

        try {
            $configured = $this->settings->toArray();
        } catch (\Throwable $e) {
            $status['problem'] = 'The alert settings in YAML are invalid'.($e instanceof \InvalidArgumentException ? ': '.$e->getMessage() : '; see the application log.');

            return $status;
        }

        $enabledRules = array_filter($configured['rules'], static fn (array $r): bool => $r['enabled']);
        $status['enabled'] = $enabledRules !== [];
        if ($status['enabled']) {
            $now = $this->now();
            $completedHour = $this->evaluator->completedUtcHour($now);
            $cursorHour = AlertEvaluator::parseIsoHour($success['fingerprint'] ?? null);
            $status['next_due'] = ($cursorHour === null || $cursorHour < $completedHour)
                ? $now
                : $completedHour->modify('+1 hour');
        }

        return $status;
    }

    public function runDue(TaskTrigger $trigger): array
    {
        $status = $this->status();
        if ($status['problem'] !== null) {
            return ['status' => 'failed', 'message' => $status['problem']];
        }
        if (!$status['enabled']) {
            return ['status' => 'off', 'message' => !$this->features->isEnabled('alerts') ? 'Alerts are turned off.' : 'No alert rules are enabled.'];
        }

        try {
            $result = $this->evaluator->evaluate($trigger, false);
        } catch (\Throwable $e) {
            $this->logger->error('Scheduled alert evaluation failed.', ['exception' => $e]);

            return ['status' => 'failed', 'message' => 'Alert evaluation failed ('.(new \ReflectionClass($e))->getShortName().'); see the application log.'];
        }

        return [
            'status' => $result['status'],
            'message' => $result['message'],
        ];
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now())->setTimezone(new \DateTimeZone('UTC'));
    }
}
