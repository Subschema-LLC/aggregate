<?php

declare(strict_types=1);

namespace App\Service\Scheduler;

use App\Service\Operations\TaskTrigger;
use App\Service\ReportingDatabase\ReportingDatabaseSettings;
use App\Service\ReportingDatabase\ReportingDatabaseSyncRunner;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/** Reporting database sync: each selected view when its interval has passed. */
final class ReportingDatabaseSyncJob implements ScheduledJob
{
    public function __construct(
        private readonly ReportingDatabaseSyncRunner $runner,
        private readonly ReportingDatabaseSettings $settings,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function name(): string
    {
        return ReportingDatabaseSyncRunner::TASK_TYPE;
    }

    public function label(): string
    {
        return 'Reporting database sync';
    }

    public function status(): array
    {
        try {
            $settings = $this->settings->toArray();
        } catch (\Throwable $e) {
            return ['enabled' => false, 'schedule' => 'Invalid settings', 'last_run' => null, 'last_status' => null, 'next_due' => null,
                'problem' => 'The reporting database settings are invalid'.($e instanceof \InvalidArgumentException ? ': '.$e->getMessage() : '; see the application log.')];
        }
        $enabled = $settings[ReportingDatabaseSettings::KEY_ENABLED];
        $latest = ViewSyncResults::status($this->runner->status($settings), $this->now());

        return [
            'enabled' => $enabled,
            'schedule' => $enabled ? BigQuerySyncJob::every($settings[ReportingDatabaseSettings::KEY_INTERVAL]) : 'Off',
            'last_run' => $latest['last_run'],
            'last_status' => $latest['last_status'],
            'next_due' => $enabled ? $latest['next_due'] : null,
            'problem' => null,
        ];
    }

    public function runDue(TaskTrigger $trigger): array
    {
        try {
            return ViewSyncResults::summarize($this->runner->run(false, null, null, $trigger), 'The reporting database sync is turned off.');
        } catch (\InvalidArgumentException $e) {
            return ['status' => 'failed', 'message' => 'The reporting database settings are invalid: '.$e->getMessage()];
        } catch (\Throwable $e) {
            $this->logger->error('Scheduled reporting database sync failed.', ['exception' => $e]);

            return ['status' => 'failed', 'message' => 'The reporting database sync failed ('.(new \ReflectionClass($e))->getShortName().'); see the application log.'];
        }
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now())->setTimezone(new \DateTimeZone('UTC'));
    }
}
