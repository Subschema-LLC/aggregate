<?php

declare(strict_types=1);

namespace App\Tests\Service\Operations;

use App\Service\Operations\AuditTrail;
use App\Service\Operations\ProcessingTasks;
use App\Service\Operations\TaskTrigger;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class ProcessingTasksTest extends TestCase
{
    private Connection $connection;
    private ProcessingTasks $tasks;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->connection = OperationsDatabase::connection();
        $this->tasks = new ProcessingTasks($this->connection, new AuditTrail($this->connection));
        $this->now = new \DateTimeImmutable('2026-10-07 12:00:00', new \DateTimeZone('UTC'));
    }

    public function testARunIsRecordedAndEndsInTheAuditTrail(): void
    {
        $id = $this->tasks->start('example_job', 'first', TaskTrigger::schedule(), $this->now);
        self::assertSame(1, $id);
        self::assertSame('running', $this->row($id)['status']);

        $this->tasks->succeed($id, $this->now->modify('+2 minutes'), 42, "Copied 42 rows.\nDone.", 'job-1');
        // A second finish (for example after the run was expired) changes nothing.
        $this->tasks->fail($id, $this->now->modify('+3 minutes'), 'Late failure');

        self::assertSame([
            'id' => '1', 'task_type' => 'example_job', 'subject' => 'first', 'status' => 'succeeded', 'triggered_by' => 'schedule',
            'requested_by' => null, 'lock_key' => null, 'started_at' => '2026-10-07 12:00:00', 'finished_at' => '2026-10-07 12:02:00',
            'row_count' => '42', 'external_id' => 'job-1', 'details' => 'Copied 42 rows. Done.',
        ], array_map(static fn (mixed $value): ?string => $value === null ? null : (string) $value, $this->row($id)));
        self::assertSame([[
            'occurred_at' => '2026-10-07 12:02:00', 'category' => 'task', 'operation' => 'example_job', 'subject' => 'first',
            'outcome' => 'succeeded', 'actor' => null, 'processing_task_id' => '1', 'details' => 'Copied 42 rows. Done.',
        ]], $this->audit());
    }

    public function testFailuresKeepTheirDetailsAndTheAdministrator(): void
    {
        $id = $this->tasks->start('example_job', null, TaskTrigger::dashboard('scott'), $this->now);
        $this->tasks->fail((int) $id, $this->now, 'BigQuery refused the request: quota exceeded.', 'job-9');

        $row = $this->row((int) $id);
        self::assertSame(['failed', 'dashboard', 'scott', 'BigQuery refused the request: quota exceeded.', 'job-9'], [$row['status'], $row['triggered_by'], $row['requested_by'], $row['details'], $row['external_id']]);
        self::assertSame(['failed', 'scott', 'BigQuery refused the request: quota exceeded.'], [$this->audit()[0]['outcome'], $this->audit()[0]['actor'], $this->audit()[0]['details']]);
    }

    public function testOnlyOneExclusiveRunOfATypeAndSubjectAtATime(): void
    {
        $first = $this->tasks->start('example_job', 'a', TaskTrigger::schedule(), $this->now, 600);
        self::assertNotNull($first);
        self::assertNull($this->tasks->start('example_job', 'a', TaskTrigger::command(), $this->now->modify('+5 minutes'), 600));
        self::assertNotNull($this->tasks->start('example_job', 'b', TaskTrigger::schedule(), $this->now, 600), 'Another subject is independent.');
        self::assertNotNull($this->tasks->start('example_job', 'a', TaskTrigger::schedule(), $this->now), 'Non-exclusive runs take no lock.');

        $this->tasks->succeed($first, $this->now->modify('+6 minutes'));
        self::assertNotNull($this->tasks->start('example_job', 'a', TaskTrigger::schedule(), $this->now->modify('+7 minutes'), 600));
    }

    public function testAnAbandonedRunIsMarkedFailedAndTheNextRunTakesOver(): void
    {
        $abandoned = (int) $this->tasks->start('example_job', 'a', TaskTrigger::schedule(), $this->now, 600);

        self::assertNull($this->tasks->start('example_job', 'a', TaskTrigger::schedule(), $this->now->modify('+10 minutes'), 600));
        $next = $this->tasks->start('example_job', 'a', TaskTrigger::schedule(), $this->now->modify('+10 minutes +1 second'), 600);

        self::assertNotNull($next);
        $row = $this->row($abandoned);
        self::assertSame(['failed', null], [$row['status'], $row['lock_key']]);
        self::assertStringContainsString('still marked running after 10 minutes', (string) $row['details']);
        self::assertSame('failed', $this->audit()[0]['outcome']);
        // The abandoned process finishing late does not overwrite the result.
        $this->tasks->succeed($abandoned, $this->now->modify('+11 minutes'));
        self::assertSame('failed', $this->row($abandoned)['status']);
        self::assertCount(1, $this->audit());
    }

    public function testLatestReportsTheLastAttemptAndTheLastSuccessOfEachSubject(): void
    {
        $this->finished('example_job', 'a', 'succeeded', '-3 hours', 10);
        $this->finished('example_job', 'a', 'failed', '-2 hours');
        $this->finished('example_job', 'b', 'succeeded', '-1 hour', 5);
        $this->finished('other_job', 'a', 'succeeded', '-1 hour', 1);

        $latest = $this->tasks->latest('example_job');

        self::assertSame(['a', 'b'], array_keys($latest));
        self::assertSame(['failed', 'succeeded', 10], [$latest['a']['attempt']['status'], $latest['a']['success']['status'], $latest['a']['success']['row_count']]);
        self::assertEquals($this->now->modify('-2 hours'), $latest['a']['attempt']['started_at']);
        self::assertSame(5, $latest['b']['attempt']['row_count']);
        self::assertSame($latest['b']['attempt'], $latest['b']['success']);
        self::assertSame([], $this->tasks->latest('missing_job'));
    }

    public function testThePurgeKeepsEachSubjectsLatestSuccessAndFailure(): void
    {
        $oldestSuccess = $this->finished('example_job', 'a', 'succeeded', '-40 days');
        $lastSuccess = $this->finished('example_job', 'a', 'succeeded', '-35 days');
        $oldFailure = $this->finished('example_job', 'a', 'failed', '-33 days');
        $lastFailure = $this->finished('example_job', 'a', 'failed', '-32 days');
        $olderB = $this->finished('example_job', 'b', 'succeeded', '-31 days');
        $recent = $this->finished('example_job', 'b', 'succeeded', '-2 days');
        $stale = (int) $this->tasks->start('example_job', 'c', TaskTrigger::schedule(), $this->now->modify('-45 days'), 600);
        $running = (int) $this->tasks->start('example_job', 'd', TaskTrigger::schedule(), $this->now->modify('-1 hour'), 600);
        $cutoff = $this->now->modify('-30 days');

        self::assertSame(4, $this->tasks->countBefore($cutoff));
        self::assertSame(4, $this->tasks->purgeBefore($cutoff, 2));

        $remaining = array_map('intval', $this->connection->fetchFirstColumn('SELECT id FROM processing_tasks ORDER BY id'));
        self::assertSame([$lastSuccess, $lastFailure, $recent, $running], $remaining);
        self::assertNotContains($oldestSuccess, $remaining);
        self::assertNotContains($oldFailure, $remaining);
        self::assertNotContains($olderB, $remaining);
        self::assertNotContains($stale, $remaining, 'A run abandoned before the cutoff is removed.');
        self::assertSame(0, $this->tasks->countBefore($cutoff));
    }

    public function testTheAuditTrailPurgeRemovesOnlyOlderEntries(): void
    {
        $audit = new AuditTrail($this->connection);
        foreach (['-400 days', '-366 days', '-365 days', '-1 day'] as $age) {
            $audit->record(AuditTrail::CATEGORY_TASK, 'example_job', 'succeeded', $this->now->modify($age));
        }
        $cutoff = $this->now->modify('-365 days');

        self::assertSame(2, $audit->countBefore($cutoff));
        self::assertSame(2, $audit->purgeBefore($cutoff, 1));
        self::assertSame(['2025-10-07 12:00:00', '2026-10-06 12:00:00'], array_column($this->audit(), 'occurred_at'));
    }

    public function testAuditCodesAndTriggersAreValidated(): void
    {
        $audit = new AuditTrail($this->connection);
        try {
            $audit->record('task', 'Bad Operation', 'succeeded', $this->now);
            self::fail('A free-text operation was accepted.');
        } catch (\InvalidArgumentException) {
        }
        try {
            $this->tasks->start('Bad type', null, TaskTrigger::schedule(), $this->now);
            self::fail('A free-text task type was accepted.');
        } catch (\InvalidArgumentException) {
        }
        $this->expectException(\InvalidArgumentException::class);
        TaskTrigger::dashboard(" \n");
    }

    public function testStoredTextIsOneBoundedLine(): void
    {
        self::assertNull(AuditTrail::clean("  \t ", 10));
        self::assertSame('a b c', AuditTrail::clean("a\r\n b\x00c", 10));
        self::assertSame('abcdefghi…', AuditTrail::clean('abcdefghijklmnop', 10));
        self::assertSame('a?b', AuditTrail::clean("a\xFFb", 10));
    }

    private function finished(string $type, string $subject, string $status, string $age, ?int $rows = null): int
    {
        $at = $this->now->modify($age);
        $id = (int) $this->tasks->start($type, $subject, TaskTrigger::schedule(), $at);
        $status === 'succeeded' ? $this->tasks->succeed($id, $at->modify('+1 minute'), $rows) : $this->tasks->fail($id, $at->modify('+1 minute'), 'Failed.');

        return $id;
    }

    /** @return array<string, mixed> */
    private function row(int $id): array
    {
        return $this->connection->fetchAssociative('SELECT * FROM processing_tasks WHERE id = ?', [$id]) ?: [];
    }

    /** @return list<array<string, mixed>> */
    private function audit(): array
    {
        return array_map(
            static fn (array $row): array => array_map(static fn (mixed $value): ?string => $value === null ? null : (string) $value, $row),
            $this->connection->fetchAllAssociative('SELECT occurred_at, category, operation, subject, outcome, actor, processing_task_id, details FROM audit_trail ORDER BY id'),
        );
    }
}
