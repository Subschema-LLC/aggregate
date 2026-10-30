<?php

declare(strict_types=1);

namespace App\Tests\Service\Scheduler;

use App\Service\Operations\TaskTrigger;
use App\Service\Scheduler\ScheduledJob;
use App\Service\Scheduler\Scheduler;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

final class SchedulerTest extends TestCase
{
    private string $projectDir;
    private MockClock $clock;
    /** @var list<array{0: string, 1: string}> */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/aggregate-scheduler-'.bin2hex(random_bytes(6));
        mkdir($this->projectDir.'/var', 0700, true);
        $this->clock = new MockClock('2026-10-15 22:00:00 UTC');
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->projectDir.'/var/scheduler/*') ?: []);
        @rmdir($this->projectDir.'/var/scheduler');
        rmdir($this->projectDir.'/var');
        rmdir($this->projectDir);
    }

    public function testEveryJobRunsInOrderAndAFailureDoesNotStopTheNext(): void
    {
        $scheduler = $this->scheduler(
            $this->job('analytics_maintenance', ['status' => 'ran', 'message' => 'Done.']),
            $this->job('bigquery_sync', new \RuntimeException('secret detail')),
            $this->job('reporting_database_sync', ['status' => 'not_due', 'message' => 'Not due yet.']),
            $this->job('forensic_database_sync', ['status' => 'off', 'message' => 'The forensic database sync is turned off.']),
        );

        $run = $scheduler->run(TaskTrigger::schedule());

        self::assertTrue($run['ran']);
        self::assertSame([['analytics_maintenance', 'schedule'], ['bigquery_sync', 'schedule'], ['reporting_database_sync', 'schedule'], ['forensic_database_sync', 'schedule']], $this->calls);
        self::assertSame(['ran', 'failed', 'not_due', 'off'], array_column($run['results'], 'status'));
        self::assertSame('The job failed (RuntimeException); see the application log.', $run['results'][1]['message']);
    }

    public function testHealthTellsScheduledRunsFromRunsByHand(): void
    {
        $scheduler = $this->scheduler($this->job('analytics_maintenance', ['status' => 'off', 'message' => 'Off.']));
        self::assertSame(['state' => 'never', 'scheduled' => null, 'manual' => null], $scheduler->health());

        // A run by hand does not show that the server's scheduled task works.
        $scheduler->run(TaskTrigger::dashboard('scott'));
        $health = $scheduler->health();
        self::assertSame('never', $health['state']);
        self::assertSame('dashboard', $health['manual']['trigger']);

        $scheduler->run(TaskTrigger::schedule());
        self::assertSame('working', $scheduler->health()['state']);
        self::assertEquals(new \DateTimeImmutable('2026-10-15 22:00:00 UTC'), $scheduler->health()['scheduled']['started_at']);

        $this->clock->modify('+'.(Scheduler::LATE_AFTER_MINUTES + 1).' minutes');
        $health = $scheduler->health();
        self::assertSame('late', $health['state']);
        self::assertSame(['job' => 'analytics_maintenance', 'label' => 'analytics_maintenance job', 'status' => 'off', 'message' => 'Off.'], $health['scheduled']['results'][0]);
        self::assertNotNull($health['manual'], 'The run by hand is kept beside the scheduled one.');
    }

    public function testASecondRunWaitsForTheFirstToFinish(): void
    {
        $scheduler = $this->scheduler($this->job('analytics_maintenance', ['status' => 'ran', 'message' => 'Done.']));
        $scheduler->run(TaskTrigger::schedule());
        $this->calls = [];
        self::assertFalse($scheduler->isRunning());

        $lock = fopen($this->projectDir.'/var/scheduler/run.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            self::assertTrue($scheduler->isRunning());
            $run = $scheduler->run(TaskTrigger::schedule());
            self::assertFalse($run['ran']);
            self::assertSame([], $this->calls);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function testAnUnreadableRecordCountsAsNoRun(): void
    {
        mkdir($this->projectDir.'/var/scheduler', 0700);
        file_put_contents($this->projectDir.'/var/scheduler/last-run.json', '{"scheduled": {"started_at": "not a date", "results": []}, "other": 1}');

        self::assertSame('never', $this->scheduler($this->job('analytics_maintenance', ['status' => 'off', 'message' => '']))->health()['state']);
    }

    private function scheduler(ScheduledJob ...$jobs): Scheduler
    {
        $jobs = array_pad($jobs, 4, null);
        foreach ($jobs as $index => $job) {
            $jobs[$index] = $job ?? $this->job('spare_'.$index, ['status' => 'off', 'message' => '']);
        }

        return new Scheduler($jobs[0], $jobs[1], $jobs[2], $jobs[3], $this->projectDir, $this->clock, new NullLogger());
    }

    /** @param array{status: string, message: string}|\Throwable $result */
    private function job(string $name, array|\Throwable $result): ScheduledJob
    {
        $job = $this->createStub(ScheduledJob::class);
        $job->method('name')->willReturn($name);
        $job->method('label')->willReturn($name.' job');
        $job->method('runDue')->willReturnCallback(function (TaskTrigger $trigger) use ($name, $result): array {
            $this->calls[] = [$name, $trigger->source];
            if ($result instanceof \Throwable) {
                throw $result;
            }

            return $result;
        });

        return $job;
    }
}
