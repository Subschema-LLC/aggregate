<?php

declare(strict_types=1);

namespace App\Service\Scheduler;

use App\Service\Operations\TaskTrigger;
use App\Service\ViewSync\ViewSyncDefinition;
use App\Service\ViewSync\ViewSyncRunner;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * A view sync, BigQuery or the reporting database: each selected view when
 * its interval has passed. The runner's settings name the job and its
 * messages (ViewSyncSettings::definition()).
 */
abstract class ViewSyncJob implements ScheduledJob
{
    /** @param ViewSyncRunner<*> $runner */
    public function __construct(
        private readonly ViewSyncRunner $runner,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    final public function name(): string
    {
        return $this->sync()->taskType;
    }

    final public function label(): string
    {
        return $this->sync()->label;
    }

    public function status(): array
    {
        $settings = $this->runner->settings();
        try {
            $values = $settings->toArray();
        } catch (\Throwable $e) {
            return ['enabled' => false, 'schedule' => 'Invalid settings', 'last_run' => null, 'last_status' => null, 'next_due' => null,
                'problem' => $this->invalidSettings().($e instanceof \InvalidArgumentException ? ': '.$e->getMessage() : '; see the application log.')];
        }
        $enabled = $settings::isEnabled($values);
        $latest = ViewSyncResults::status($this->runner->status($values), $this->now());

        return [
            'enabled' => $enabled,
            'schedule' => $enabled ? self::every($settings::intervalMinutes($values)) : 'Off',
            'last_run' => $latest['last_run'],
            'last_status' => $latest['last_status'],
            'next_due' => $enabled ? $latest['next_due'] : null,
            'problem' => null,
        ];
    }

    public function runDue(TaskTrigger $trigger): array
    {
        try {
            return ViewSyncResults::summarize($this->runner->run(false, null, null, $trigger), $this->sync()->subject.' is turned off.');
        } catch (\InvalidArgumentException $e) {
            return ['status' => 'failed', 'message' => $this->invalidSettings().': '.$e->getMessage()];
        } catch (\Throwable $e) {
            $this->logger->error('Scheduled '.$this->sync()->settingsName.' sync failed.', ['exception' => $e]);

            return ['status' => 'failed', 'message' => $this->sync()->subject.' failed ('.(new \ReflectionClass($e))->getShortName().'); see the application log.'];
        }
    }

    /** "Every 15 minutes", "Every hour", "Every 6 hours". */
    public static function every(int $minutes): string
    {
        return 'Every '.($minutes < 60 ? $minutes.' minutes' : ($minutes === 60 ? 'hour' : ($minutes / 60).' hours'));
    }

    private function sync(): ViewSyncDefinition
    {
        return $this->runner->settings()::definition();
    }

    /** "The BigQuery settings are invalid", continued by the reason. */
    private function invalidSettings(): string
    {
        return 'The '.$this->sync()->settingsName.' settings are invalid';
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now())->setTimezone(new \DateTimeZone('UTC'));
    }
}
