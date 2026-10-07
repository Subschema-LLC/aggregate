<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Read-only mapping for the DBAL-managed processing_tasks table: one row per
 * run of a background job (a BigQuery view sync, analytics maintenance), with
 * its status and failure details. A running exclusive task holds lock_key.
 * Written by App\Service\Operations\ProcessingTasks.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(
    name: 'processing_tasks',
    options: ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci'],
)]
#[ORM\UniqueConstraint(name: 'UNIQ_PROCESSING_TASKS_LOCK', columns: ['lock_key'])]
#[ORM\Index(name: 'IDX_PROCESSING_TASKS_TYPE', columns: ['task_type', 'subject', 'status'])]
#[ORM\Index(name: 'IDX_PROCESSING_TASKS_STARTED', columns: ['started_at'])]
class ProcessingTask
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private string $id;

    #[ORM\Column(name: 'task_type', type: 'string', length: 64)]
    private string $taskType;

    #[ORM\Column(type: 'string', length: 191, nullable: true)]
    private ?string $subject;

    #[ORM\Column(type: 'string', length: 16)]
    private string $status;

    #[ORM\Column(name: 'triggered_by', type: 'string', length: 16)]
    private string $triggeredBy;

    #[ORM\Column(name: 'requested_by', type: 'string', length: 191, nullable: true)]
    private ?string $requestedBy;

    #[ORM\Column(name: 'lock_key', type: 'string', length: 255, nullable: true)]
    private ?string $lockKey;

    #[ORM\Column(name: 'started_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(name: 'finished_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $finishedAt;

    #[ORM\Column(name: 'row_count', type: 'bigint', nullable: true)]
    private ?string $rowCount;

    #[ORM\Column(name: 'external_id', type: 'string', length: 191, nullable: true)]
    private ?string $externalId;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $details;
}
