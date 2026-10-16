<?php

declare(strict_types=1);

namespace App\Service\Scheduler;

use App\Service\Operations\TaskTrigger;

/**
 * A background job that app:scheduler:run starts when it is due. Each job
 * decides for itself whether it is on and due, holds its own lock while it
 * runs, and records its runs as processing tasks.
 *
 * @phpstan-type JobStatus array{enabled: bool, schedule: string, last_run: ?\DateTimeImmutable, last_status: ?string, next_due: ?\DateTimeImmutable, problem: ?string}
 * @phpstan-type JobResult array{status: string, message: string}
 */
interface ScheduledJob
{
    /** A short code, such as analytics_maintenance. */
    public function name(): string;

    public function label(): string;

    /**
     * Whether the job is on, how often it runs, and its latest run.
     *
     * @return JobStatus
     */
    public function status(): array;

    /**
     * Runs the job when it is on and due. Status is "off", "not_due", "ran",
     * "busy" (another process is running it) or "failed".
     *
     * @return JobResult
     */
    public function runDue(TaskTrigger $trigger): array;
}
