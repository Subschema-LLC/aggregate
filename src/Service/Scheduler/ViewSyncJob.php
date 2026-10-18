<?php

declare(strict_types=1);

namespace App\Service\Scheduler;

use App\Service\Operations\TaskTrigger;
use App\Service\ViewSync\ViewSyncRunner;
use App\Service\ViewSync\ViewSyncSettings;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/** A view sync, BigQuery or the reporting database: each selected view when its interval has passed. */
abstract class ViewSyncJob implements ScheduledJob
{
    /**
     * @param ViewSyncRunner<*> $runner
     * @param string            $sync         begins "… is turned off." and "… failed (…)"
     * @param string            $settingsName begins "… are invalid"
     * @param string            $failureLog   logged when a run fails unexpectedly
     */
    public function __construct(
        private readonly ViewSyncRunner $runner,
        private readonly ViewSyncSettings $settings,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly string $sync,
        private readonly string $settingsName,
        private readonly string $failureLog,
    ) {
    }

    public function status(): array
    {
        try {
            $settings = $this->settings->toArray();
        } catch (\Throwable $e) {
            return ['enabled' => false, 'schedule' => 'Invalid settings', 'last_run' => null, 'last_status' => null, 'next_due' => null,
                'problem' => $this->settingsName.' are invalid'.($e instanceof \InvalidArgumentException ? ': '.$e->getMessage() : '; see the application log.')];
        }
        $enabled = $this->settings::isEnabled($settings);
        $latest = ViewSyncResults::status($this->runner->status($settings), $this->now());

        return [
            'enabled' => $enabled,
            'schedule' => $enabled ? self::every($this->settings::intervalMinutes($settings)) : 'Off',
            'last_run' => $latest['last_run'],
            'last_status' => $latest['last_status'],
            'next_due' => $enabled ? $latest['next_due'] : null,
            'problem' => null,
        ];
    }

    public function runDue(TaskTrigger $trigger): array
    {
        try {
            return ViewSyncResults::summarize($this->runner->run(false, null, null, $trigger), $this->sync.' is turned off.');
        } catch (\InvalidArgumentException $e) {
            return ['status' => 'failed', 'message' => $this->settingsName.' are invalid: '.$e->getMessage()];
        } catch (\Throwable $e) {
            $this->logger->error($this->failureLog, ['exception' => $e]);

            return ['status' => 'failed', 'message' => $this->sync.' failed ('.(new \ReflectionClass($e))->getShortName().'); see the application log.'];
        }
    }

    /** "Every 15 minutes", "Every hour", "Every 6 hours". */
    public static function every(int $minutes): string
    {
        return 'Every '.($minutes < 60 ? $minutes.' minutes' : ($minutes === 60 ? 'hour' : ($minutes / 60).' hours'));
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now())->setTimezone(new \DateTimeZone('UTC'));
    }
}
