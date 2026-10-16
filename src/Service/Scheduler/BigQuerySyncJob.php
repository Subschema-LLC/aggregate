<?php

declare(strict_types=1);

namespace App\Service\Scheduler;

use App\Service\BigQuery\BigQuerySettings;
use App\Service\BigQuery\BigQuerySyncRunner;
use App\Service\Operations\TaskTrigger;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/** BigQuery sync: each selected view when its interval has passed. */
final class BigQuerySyncJob implements ScheduledJob
{
    public function __construct(
        private readonly BigQuerySyncRunner $runner,
        private readonly BigQuerySettings $settings,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function name(): string
    {
        return BigQuerySyncRunner::TASK_TYPE;
    }

    public function label(): string
    {
        return 'BigQuery sync';
    }

    public function status(): array
    {
        try {
            $settings = $this->settings->toArray();
        } catch (\Throwable $e) {
            return ['enabled' => false, 'schedule' => 'Invalid settings', 'last_run' => null, 'last_status' => null, 'next_due' => null,
                'problem' => 'The BigQuery settings are invalid'.($e instanceof \InvalidArgumentException ? ': '.$e->getMessage() : '; see the application log.')];
        }
        $enabled = $settings[BigQuerySettings::KEY_ENABLED];
        $latest = ViewSyncResults::status($this->runner->status($settings), $this->now());

        return [
            'enabled' => $enabled,
            'schedule' => $enabled ? self::every($settings[BigQuerySettings::KEY_INTERVAL]) : 'Off',
            'last_run' => $latest['last_run'],
            'last_status' => $latest['last_status'],
            'next_due' => $enabled ? $latest['next_due'] : null,
            'problem' => null,
        ];
    }

    public function runDue(TaskTrigger $trigger): array
    {
        try {
            return ViewSyncResults::summarize($this->runner->run(false, null, null, $trigger), 'BigQuery sync is turned off.');
        } catch (\InvalidArgumentException $e) {
            return ['status' => 'failed', 'message' => 'The BigQuery settings are invalid: '.$e->getMessage()];
        } catch (\Throwable $e) {
            $this->logger->error('Scheduled BigQuery sync failed.', ['exception' => $e]);

            return ['status' => 'failed', 'message' => 'BigQuery sync failed ('.(new \ReflectionClass($e))->getShortName().'); see the application log.'];
        }
    }

    /** "Every 15 minutes", "Every hour", "Every 6 hours"; also used by the reporting database job. */
    public static function every(int $minutes): string
    {
        return 'Every '.($minutes < 60 ? $minutes.' minutes' : ($minutes === 60 ? 'hour' : ($minutes / 60).' hours'));
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now())->setTimezone(new \DateTimeZone('UTC'));
    }
}
