<?php

declare(strict_types=1);

namespace App\Tests\Service\Scheduler;

use App\Service\AggregateConfigLoader;
use App\Service\AnalyticsArchiveService;
use App\Service\AnalyticsDataLifecyclePolicy;
use App\Service\AnalyticsMaintenanceLease;
use App\Service\AnalyticsMaintenanceRunner;
use App\Service\AnalyticsRetentionService;
use App\Service\Operations\AuditTrail;
use App\Service\Operations\ProcessingTasks;
use App\Service\Operations\TaskTrigger;
use App\Service\Scheduler\AnalyticsMaintenanceJob;
use App\Tests\Service\Operations\OperationsDatabase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Yaml\Yaml;

final class AnalyticsMaintenanceJobTest extends TestCase
{
    private string $projectDir;
    private Connection $operations;
    private MockClock $clock;
    private array $environment;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        foreach (['ANALYTICS_ARCHIVING_ENABLED', 'ANALYTICS_RETENTION_ENABLED', 'AUDIT_TRAIL_RETENTION_DAYS', 'PROCESSING_TASKS_RETENTION_DAYS'] as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
        }
        $this->projectDir = sys_get_temp_dir().'/aggregate-maintenance-job-'.bin2hex(random_bytes(6));
        mkdir($this->projectDir.'/config', 0700, true);
        $this->operations = OperationsDatabase::connection();
        $this->clock = new MockClock('2026-10-15 03:00:00 UTC');
    }

    protected function tearDown(): void
    {
        [$_ENV, $_SERVER] = $this->environment;
        @unlink($this->projectDir.'/config/aggregate.yaml');
        rmdir($this->projectDir.'/config');
        rmdir($this->projectDir);
    }

    public function testItRunsOnceADayWhateverStartedTheLastRun(): void
    {
        $this->policy(['audit_trail_retention_days' => 365]);
        $job = $this->job();

        self::assertSame('ran', $job->runDue(TaskTrigger::dashboard('scott'))['status']);
        $task = $this->tasks()->latest(AnalyticsMaintenanceRunner::TASK_TYPE)['']['attempt'];
        self::assertSame(['dashboard', 'scott'], [$task['triggered_by'], $task['requested_by']]);
        self::assertEquals(new \DateTimeImmutable('2026-10-16 03:00:00 UTC'), $job->status()['next_due']);

        $this->clock->modify('+23 hours 45 minutes');
        self::assertSame('not_due', $job->runDue(TaskTrigger::schedule())['status']);
        // A run every five minutes may land just before the due time.
        $this->clock->modify('+5 minutes');
        self::assertSame('ran', $job->runDue(TaskTrigger::schedule())['status']);
        self::assertSame('schedule', $this->tasks()->latest(AnalyticsMaintenanceRunner::TASK_TYPE)['']['attempt']['triggered_by']);
    }

    public function testItIsOffWhenNothingIsTurnedOnAndReportsInvalidSettings(): void
    {
        $this->policy([]);
        $job = $this->job();
        self::assertSame(['status' => 'off', 'message' => 'Archiving, retention and record purging are all turned off.'], $job->runDue(TaskTrigger::schedule()));
        self::assertFalse($job->status()['enabled']);
        self::assertSame([], $this->tasks()->latest(AnalyticsMaintenanceRunner::TASK_TYPE));

        // The settings are read once per request, so a new job sees the change.
        $this->policy(['analytics_archiving_enabled' => 'sometimes']);
        $result = $this->job()->runDue(TaskTrigger::schedule());
        self::assertSame('failed', $result['status']);
        self::assertStringStartsWith('The data lifecycle settings are invalid', $result['message']);
    }

    private function policy(array $changes): void
    {
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump(array_replace([
            'analytics_archiving_enabled' => false,
            'analytics_retention_enabled' => false,
            'audit_trail_retention_days' => 0,
            'processing_tasks_retention_days' => 0,
        ], $changes)));
    }

    private function tasks(): ProcessingTasks
    {
        return new ProcessingTasks($this->operations, new AuditTrail($this->operations));
    }

    private function job(): AnalyticsMaintenanceJob
    {
        // Analytics data and the lease live elsewhere; only the task records matter here.
        $data = $this->createStub(Connection::class);
        $data->method('executeStatement')->willReturn(1);
        $policy = new AnalyticsDataLifecyclePolicy(new AggregateConfigLoader($this->projectDir, 'test'));
        $audit = new AuditTrail($this->operations);
        $runner = new AnalyticsMaintenanceRunner($policy, new AnalyticsArchiveService($data), new AnalyticsRetentionService($data), new AnalyticsMaintenanceLease($data), $this->clock, $this->tasks(), $audit);

        return new AnalyticsMaintenanceJob($runner, $policy, $this->tasks(), $this->clock, new NullLogger());
    }
}
